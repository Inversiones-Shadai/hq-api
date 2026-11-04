# API de Headquarters

`apishadai-laravel12` es el origen de datos y el único servidor autorizado para registrar/revocar instalaciones. `hq-api` usa su propia base MySQL `hq_api`, conserva el registro de equipos y es el único servidor que entrega actualizaciones al cliente de escritorio. No requiere JWT de usuario.

## Configuración

En apishadai:

```dotenv
HQ_API_URL=http://127.0.0.1:8011/api
HQ_API_KEY_ID=apishadai
HQ_API_SIGNING_SECRET=<secreto-de-servidor-de-al-menos-64-caracteres>
HQ_PUBLISHING_ENABLED=true
HQ_SEND_IMMEDIATELY=true
HEADQUARTERS_APPROVAL_EMAIL=administrador@su-dominio.com
```

En hq-api:

```dotenv
DB_DATABASE=hq_api
APISHADAI_KEY_ID=apishadai
APISHADAI_SIGNING_SECRET=<el-mismo-secreto-de-servidor>
APISHADAI_ALLOWED_IPS=127.0.0.1,::1
APP_DEBUG=false
```

La clave de servidor es independiente de APP_KEY y de las claves de cada cliente. Se configura únicamente en servidores; jamás se entrega al cliente. El HTTP local se admite para desarrollo; en producción se exige HTTPS. Configurar proxies confiables y la IP de salida real de apishadai antes de desplegar. No registrar headers ni bodies con claves/códigos en proxies o herramientas de tráfico. Regenerar config:cache después de cambios de configuración.

El correo administrador debe configurarse en apishadai. No lo elige el cliente ni se usa el remitente automáticamente. Se reutiliza el SMTP existente, sin transporte log ni fallback. Sin un correo válido, el registro devuelve 503 antes de contactar hq-api.

## Registro: solamente en apishadai

1. `POST /api/headquarters/registro/solicitar`, body `{"name":"HQ-01","device_id":1}`. El dispositivo debe existir y estar ACTIVO en apishadai. Su stock y UUID reservado de hq-client se obtienen en el servidor; enviar stock_id está prohibido. La IP se obtiene de Request::ip(); no se acepta una IP indicada por el cliente. Name admite 100 caracteres, sin controles ni `|`.
2. La respuesta 202 contiene `registration_id`, `registration_key` temporal, `client_id_message`, `client_id_algorithm`, `name`, `ip` y `expires_at`. El código de seis dígitos se envía únicamente al administrador por email. Nunca aparece en esta respuesta pública.
3. El cliente calcula `client_id = HMAC-SHA256(client_id_message, registration_key)` en UTF-8, hexadecimal en minúsculas. Ejemplo PHP: `hash_hmac('sha256', $data['client_id_message'], $data['registration_key'])`. Usar exactamente el mensaje retornado.
4. `POST /api/headquarters/registro/confirmar`, body `{"registration_id":"UUID","registration_key":"HEX64","client_id":"HEX64","code":"012345"}`. El código es string para conservar ceros iniciales. La IP debe coincidir con la solicitud.
5. La respuesta 201 entrega `device`, `consumer_uuid` y `headquarters_key` operacional. Guardar la key y el client_id en el almacenamiento seguro de la instalación. La key operacional es distinta de la temporal y se entrega una sola vez. Una solicitud ya utilizada retorna 409; si se pierde su respuesta, solicitar autorización nueva y revocar la instalación anterior.

El código vence en diez minutos; cinco fallos revocan la solicitud. Los contadores se confirman incluso cuando la respuesta es un error. Un fallo de SMTP intenta revocar la solicitud en hq-api; si la red impide revocarla, vence automáticamente y su código nunca se entrega al solicitante. Dispositivo, consumidor y credencial se crean en una sola transacción en hq-api.

Al crear un dispositivo de apishadai se reserva un hq_client_uuid permanente; los dispositivos existentes se vinculan mediante la migración. El vínculo se publica internamente a hq-api antes de solicitar el código. Solo se activa el cliente al confirmar la autorización por email. Un dispositivo admite un hq-client autorizado: para sustituirlo, revocar primero el anterior. Si cambia su stock durante una solicitud pendiente, se exige solicitar un código nuevo.

## Consultas: solamente en hq-api

Enviar estos headers a todas las rutas públicas de hq-api:

```http
X-Headquarters-Key: KEY-OPERACIONAL
X-Headquarters-Client-Id: ID-DERIVADO
X-Headquarters-Name: HQ-01
```

El middleware verifica el hash de la key, client_id, nombre exacto, IP observada, estado activo de match, dispositivo y consumidor, y el vínculo vigente con el dispositivo de apishadai y su stock. No confía en una IP privada o en X-Forwarded-For de un proxy desconocido. Cambiar IP/nombre exige nueva autorización. Desactivar, eliminar o revocar bloquea el acceso. Las claves se almacenan como hashes. Se limitan 120 peticiones/minuto por credencial y 300 por IP. Las respuestas llevan Cache-Control: no-store.

- `GET /api/headquarters/device`: identidad de la instalación, sin hashes ni secretos.
- `GET /api/headquarters/sync/cambios?limit=100`: cambios desde el cursor confirmado. Limit admite 1–500. Devuelve `consumer_uuid`, `batch_id`, `from_sequence`, `to_sequence`, `published_sequence`, `has_more` y `changes`.
- `POST /api/headquarters/sync/confirmar`, body `{"batch_id":"UUID"}`: confirmar únicamente después de aplicar el lote y guardar su cursor en una transacción local.
- `GET /api/headquarters/transferencias?per_page=30&page=1`: estado actual paginado de las transferencias del stock del dispositivo (origen o destino). per_page admite 1–100; no acepta stock_id ni device_id.
- `GET /api/headquarters/transferencias/{id}`: transferencia actual autorizada, con sus detalles y datos de lote. Devuelve 404 si pertenece a otros stocks o está eliminada.

Cada cambio contiene sequence, uuid, entity, entity_id, action, version, schema_version, payload y created_at. Las secuencias/versiones/IDs del sobre son strings decimales. Para DELETE, payload es null; entity/entity_id/version identifican la eliminación. CREATE/UPDATE entregan un estado completo según EntityContract. Descargar no avanza el cursor. Los lotes vencen en 30 minutos, pertenecen a un consumidor y su confirmación es idempotente y monotónica. Si no existen publicaciones nuevas, batch_id es null. Un lote filtrado puede contener changes=[] y un batch_id válido: confirmar también ese lote para avanzar sobre publicaciones de otros stocks o vínculos internos. Las secuencias pueden tener saltos. Consultar cada 1–3 minutos incluso con has_more=false; con has_more=true continuar descargando.

Los catálogos son comunes; las transferencias se filtran por el stock del dispositivo tanto en cambios como en instantáneas y consultas actuales. Los vínculos de dispositivos son internos y nunca se descargan al cliente. Si una transferencia cambia sus stocks participantes, el stock que deja de participar recibe únicamente DELETE con payload=null. No se entrega el contenido actualizado al stock anterior.

## Sincronización inicial

1. `POST /api/headquarters/sync/inicial`, body `{}`: obtener bootstrap_id, sequence, pages, expires_at.
2. `GET /api/headquarters/sync/inicial/{bootstrap_id}/paginas/{page}` por cada página desde 1 hasta pages. Puede haber cero páginas. Cada registro lleva entity, entity_id, version, schema_version, deleted y payload.
3. Guardar todas las páginas en staging local; reemplazar el estado sincronizado y guardar sequence en una única transacción local. Los tombstones deleted=true también conservan su versión para impedir resurrecciones antiguas.
4. `POST /api/headquarters/sync/inicial/{bootstrap_id}/confirmar`, body `{}`. Continuar después por sync/cambios.

La instantánea persistida y su cursor se obtienen bajo el mismo bloqueo de publicación. Cambios recibidos después aparecen en la descarga incremental. Cada snapshot pertenece a su consumidor, tiene páginas de hasta 500 registros y vence en una hora. Si falla la aplicación local, no confirmar. Un snapshot vencido exige solicitar otro. Un cursor menor a retention_floor retorna 410 y requiere bootstrap. Los clientes nuevos deben comenzar por bootstrap.

Las páginas se generan en orden de dependencias: aplicar 1..N e insertar los catálogos padres antes de productos, distribución y transferencias. Los registros contienen IDs de sus relaciones; los principios activos referenciados se entregan antes del producto. Dentro de distribución/detalles, insertar o actualizar lote_datos después del producto y antes del registro que usa ese lote. Comparar siempre versiones antes de aplicar. hq-api retiene cambios sin padres publicados y muestra su cantidad en hq:status. No confirmar un lote ni una instantánea si la aplicación local falla. Las descargas de un cliente recién registrado devuelven 409 hasta que confirme su instantánea inicial.

Cambiar el stock del dispositivo en apishadai actualiza el vínculo en hq-api y su revisión de alcance. Las descargas incrementales y las confirmaciones/páginas anteriores retornan 409. Solicitar una nueva instantánea, reemplazar los datos locales sincronizados y confirmarla antes de continuar; así se eliminan también las transferencias que pertenecían al stock anterior.

## Comunicación interna firmada

Únicamente apishadai llama a estas rutas en hq-api:

- POST `/api/internal/apishadai/registrations`
- POST `/api/internal/apishadai/registrations/confirm`
- DELETE `/api/internal/apishadai/registrations/{uuid}`
- DELETE `/api/internal/apishadai/devices/{uuid}`
- POST `/api/internal/apishadai/changes`, body `{"changes":[...]}` (1–100 cambios, máximo 8 MiB).

Headers internos: X-Apishadai-Key-Id, X-Apishadai-Timestamp (Unix segundos), X-Apishadai-Nonce (UUID nuevo por intento) y X-Apishadai-Signature (HMAC-SHA256 hexadecimal).

Mensaje firmado: `METHOD + "\n" + URI + "\n" + timestamp + "\n" + nonce + "\n" + SHA256(rawBody)`. Se validan origen permitido, key id, cuerpo exacto, método, URI y ventana temporal de cinco minutos (máximo 60 segundos futuros). Los nonces se insertan con unicidad en base de datos antes de procesar, impidiendo repeticiones. No se aceptan claves de clientes en estas rutas. hq-api no expone registro público.

UUID y versión de cambios permiten reintentos: un UUID recibido con contenido idéntico es idempotente; otro contenido o un UUID distinto para la misma versión retorna 409 y revierte el lote. El estado materializado solo avanza con versiones superiores, conservando eliminaciones.

## Operación

Aplicar `php artisan migrate --no-interaction` en ambos proyectos. En apishadai:

```shell
php artisan hq:seed
php artisan hq:push
php artisan hq:status
php artisan hq:revoke UUID-DE-CLIENTE
```

hq:seed prepara los registros existentes sin duplicar entidades ya versionadas. hq:push reintenta pendientes con backoff y devuelve error si quedan pendientes. hq:status muestra cantidades sin secretos. hq:revoke revoca en hq-api mediante la conexión firmada.

Mantener el scheduler de Laravel activo en ambos proyectos (`php artisan schedule:work` en desarrollo, `schedule:run` cada minuto en despliegue). Apishadai reintenta cada minuto; hq-api limpia nonces, lotes y snapshots vencidos cada hora. Los cambios persistentes y credenciales activas no se purgan automáticamente. Para servir hq-api local: `php artisan serve --host=127.0.0.1 --port=8011 --tries=1`.

La migración de traslado retira el registro local solo si sus tablas están vacías. En este entorno no había clientes registrados. El ledger de origen sync_changes queda en apishadai exclusivamente para respaldo y envío; no se consulta desde endpoints de clientes.
