# Captura y envío de cambios desde apishadai

La separación actual se describe en [headquarters-api.md](headquarters-api.md). hq-api conserva consumidores, dispositivos, matches, publicaciones, lotes, instantáneas y estados materializados en una base independiente. Apishadai conserva sync_changes como ledger inmutable de origen, sync_entity_versions y hq_outbox.

## Contrato actual

Se sincronizan productos, distribución, principios activos, clientes POS, laboratorios, proveedores, categorías, unidades, estados de producto, familias, presentaciones, administraciones, concentraciones, sucursales y estados de sucursal, transferencias, tipos y estados de transferencia. Los nombres de entity son los definidos por EntityContract::FIELDS; coinciden con los nombres actuales de tablas y forman parte del contrato schema_version=1. No son nombres recibidos arbitrariamente del cliente.

Las cuentas POS (`usuariospos`), sus permisos individuales (`permisosusuariospos`), roles y metadatos POS se distribuyen globalmente a todos los stocks autorizados. Sus nombres y verificadores PBKDF2 viajan en Base64, sin claves por stock. Los catálogos operativos POS mantienen alcance por stock. No se publican cuentas ni permisos del portal, contraseñas originales, tokens, compras ni existencias generales. Las transferencias incluyen IDs de usuarios participantes sin datos de usuario. El vínculo interno devices publica únicamente id, hq_client_uuid, stock_id y active; se usa para autorización y se excluye de todas las descargas públicas. El alcance de transferencias es exclusivamente stock_de y stock_para: hq-api lo aplica según el stock vigente del dispositivo.

El payload incluye los campos explícitos de EntityContract. Productos incluyen IDs de principios_activos ordenados. imagen_base64 representa los bytes almacenados en imagen codificados en base64: decodificar una vez para recuperar exactamente el valor original que espera el POS, incluso si el valor original ya era texto base64.

Distribución incluye lote_datos con producto, número de lote y fechas. Transferencias incluyen detalles ordenados por ID, cantidades, importes y lote_datos. No se incluye costo del lote. El sobre interno de transferencias lleva stock_ids y removed_stock_ids; hq-api valida que el alcance corresponda a origen y destino. El público recibe solo los cambios autorizados; una remoción de alcance se convierte en DELETE sin payload.

## Garantías de escritura

PublishesHeadquarters envuelve save/delete (incluidas sus variantes quiet) en una transacción y bloquea el registro existente. La modificación, el incremento de sync_entity_versions, la inserción en sync_changes y hq_outbox se confirman juntos. Un rollback no deja cambios ni versiones. La versión se conserva después de DELETE. ProductRepository difiere la captura hasta terminar la actualización de pivots; las modificaciones directas de ProdActivo publican los productos afectados.

TransferRepository captura un solo CREATE después de completar cabecera y detalles, y UPDATE al recibir la transferencia. Las modificaciones directas de detalles y de datos de lote vuelven a publicar los agregados afectados. Crear un dispositivo reserva su identidad incluso con createQuietly; no permite reemplazarla. Editar su stock/estado o el estado asociado publica el vínculo para actualizar la autorización en hq-api. Las migraciones convierten los escritores incluidos a InnoDB para mantener transacciones reales en MySQL.

Todos los escritores actuales de estas entidades usan Eloquent o los métodos integrados de ProductRepository. Operaciones directas DB::table, SQL y updates/deletes/insert masivos de Eloquent omiten save/delete: cualquier escritor futuro de ese tipo debe utilizar ChangeRecorder explícitamente dentro de la misma transacción o escribir mediante modelos. No habilitar QueryBuilder para escritores sincronizados sin integrar la captura.

Después del commit se intenta enviar un lote. Una caída de hq-api no revierte la operación de negocio ya confirmada: queda un pendiente persistente. OutboxSender reclama lotes con bloqueo y vencimiento; hace HTTP fuera de la transacción y confirma enviados únicamente cuando hq-api reconoce todos los UUID. Reintentos conservan UUID/version/payload y usan nonce/firma nuevos. Un worker interrumpido deja claims recuperables después de un minuto.

Los estados materializados de hq-api solo aceptan versiones superiores. El POS debe seguir la misma regla, incluyendo tombstones, para que entregas fuera de orden nunca sobrescriban estados nuevos.

## Orden y recuperación

EntityContract::DEPENDENCIES es el contrato común de relaciones. El orden se calcula de forma topológica, verificando que no haya ciclos: concentraciones antes de principios activos; presentaciones y administraciones antes de familias; categorías, unidades, laboratorios, estados, familias y principios activos antes de productos; productos antes de distribución; estados de stock antes de stocks; stocks, productos, tipos y estados de transferencia antes de transferencias. Los lotes incluidos dentro de distribución/detalles se aplican después del producto y antes del agregado que los referencia.

hq:seed recorre ese orden. Si se captura un registro que referencia padres existentes aún no versionados en apishadai, ChangeRecorder los prepara primero, incluyendo sus propias dependencias, en la misma transacción. En hq-api un CREATE/UPDATE recibido antes que sus padres permanece guardado pero sin publicar; se libera cuando los padres están publicados y activos. Otros registros independientes siguen avanzando. Las eliminaciones se publican en orden inverso y esperan a que los hijos publicados eliminen sus referencias. Una versión antigua que llega después de una superior se conserva en el ledger; el cliente debe ignorarla al comparar versiones.

Las instantáneas usan el mismo orden entre todas sus páginas y solo incluyen estados cuya versión está publicada. No basta con ordenar cada página por separado. Aplicar páginas en orden 1..N, conservar las versiones de tombstones y no insertar un registro cuya versión local sea igual o mayor. Una inconsistencia histórica con padres ausentes devuelve 503 en vez de entregar una instantánea que rompería relaciones. Los clientes nuevos deben confirmar su instantánea inicial antes de poder descargar cambios (409 mientras esté pendiente).

sync_changes.id no es un cursor: el AUTO_INCREMENT no ordena commits. La publicación en hq-api usa un contador independiente bajo el bloqueo singleton sync_stream_state. Cada ejecución busca los cambios confirmados todavía no publicados por su orden de dependencias, sin un high-water mark de IDs. Un ID menor que confirma tarde recibe una secuencia posterior cuando sus padres estén disponibles. Una prueba con dos conexiones MySQL verifica este caso. No reordenar las secuencias ya publicadas ni ordenar los cambios por UUID/ID en el cliente: aplicar el orden recibido y confirmar el lote completo después de la transacción local.

La instantánea inicial usa estados materializados y un cursor de publicación bajo ese mismo bloqueo, guardando páginas persistentes. Descargar no confirma aplicación. El cliente aplica lote/instantánea y guarda su cursor local en una transacción antes de confirmar al servidor. Una retención futura deberá conservar estos invariantes y actualizar retention_floor; no borrar publicaciones manualmente.

## Verificación

Apishadai: `php artisan test --compact tests/Feature/HeadquartersGatewayOutboxTest.php tests/Feature/Farma tests/Feature/Pos`.

hq-api: `php artisan test --compact tests/Feature/HeadquartersSystemTest.php tests/Feature/HeadquartersStockScopeTest.php tests/Feature/HeadquartersDependencyOrderTest.php tests/Feature/HeadquartersCommitOrderTest.php`.

Las pruebas de dependencias aplican entregas en una base SQLite de cliente con claves foráneas activas, incluyendo llegadas en orden inverso, lotes de un registro, instantáneas que cruzan las 500 filas por página y eliminaciones de padres recibidas antes que sus hijos.

Las pruebas normales usan SQLite en memoria; también se verificaron ambas implementaciones en bases MySQL desechables. La prueba de commits concurrentes requiere una base MySQL cuyo nombre cumpla apishadai_sync_test_<hex>, jamás la base de operación. En apishadai se selecciona con SYNC_TEST_MYSQL_DATABASE; en hq-api con DB_CONNECTION=mysql y DB_DATABASE igual a esa base previamente creada. El runner debe crear/eliminar únicamente esa base aislada.
