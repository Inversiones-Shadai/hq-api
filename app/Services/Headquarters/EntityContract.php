<?php

namespace App\Services\Headquarters;

use Illuminate\Support\Arr;

final class EntityContract
{
    /** Reserved routing token for global discount catalogs; never a stock identity. */
    public const GLOBAL_DISCOUNT_SCOPE = 0;

    /** @var array<string, list<string>> */
    public const FIELDS = [
        'devices' => ['id', 'hq_client_uuid', 'stock_id', 'active'],
        'modulospos' => ['id', 'codigo', 'nombre', 'activo'],
        'vistaspos' => ['id', 'modulo_id', 'codigo', 'nombre', 'activo'],
        'botonespos' => ['id', 'vista_id', 'codigo', 'nombre', 'activo'],
        'permisospos' => ['id', 'rol_id', 'modulo_id', 'vista_id', 'boton_id', 'activo'],
        'permisosusuariospos' => ['id', 'usuario', 'modulo', 'vista', 'actionvista', 'tipo_tiempo', 'lifetime'],
        'rolespos' => ['id', 'nombre', 'nivel', 'activo'],
        'usuariospos' => ['id', 'rol_id', 'nombre', 'activo', 'credenciales', 'vendedor'],
        'catalogospos' => ['id', 'stock_id', 'tipo', 'codigo', 'datos', 'activo', 'lote_datos'],
        'distribucion' => ['id', 'lote', 'precio', 'isv', 'dto1', 'dto2', 'dto3', 'dto4', 'lote_datos'],
        'transferencias' => ['id', 'tipo', 'stock_de', 'stock_para', 'usuario_envia', 'usuario_recibe', 'items', 'subtotal', 'isvtotal', 'total', 'estado', 'estado_recepcion', 'enviado_at', 'recibido_at', 'detalles'],
        'transferencias_tipo' => ['id', 'descripcion'],
        'transferencia_estado' => ['id', 'descripcion'],
        'lotes' => ['id', 'producto', 'lote', 'fecha_elab', 'fecha_exp', 'costo', 'venta', 'valoracion'],
        'productos' => ['id', 'producto_padre_id', 'categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado', 'principios_activos', 'imagen_base64'],
        'principal_activos' => ['id', 'nombre', 'concentracion'],
        'clientepos' => ['id', 'nombre', 'rtn', 'telefono', 'direccion', 'tipo_documento', 'fecha_nacimiento'],
        'laboratorios' => ['id', 'nombre', 'telefono', 'direccion', 'imagen_base64'],
        'proveedores' => ['id', 'nombre', 'telefono', 'direccion', 'imagen_base64'],
        'prod_categorias' => ['id', 'nombre'],
        'prod_unidades' => ['id', 'abreviatura_c', 'cantidad_c', 'abreviatura_v', 'cantidad_v'],
        'prod_estado' => ['id', 'descripcion'],
        'familia' => ['id', 'presentacion', 'administracion', 'descripcion'],
        'fam_presentacion' => ['id', 'descripcion'],
        'fam_administracion' => ['id', 'descripcion'],
        'concentraciones' => ['id', 'valor'],
        'stocks' => ['id', 'descripcion', 'telefono', 'ubicacion', 'estado'],
        'stock_estado' => ['id', 'descripcion'],
    ];

    public const INTERNAL = ['devices'];

    public const SCOPED = ['transferencias', 'catalogospos'];

    /** @var array<string, array<string, list<string>>> */
    public const DEPENDENCIES = [
        'lotes' => ['productos' => ['producto']],
        'familia' => ['fam_presentacion' => ['presentacion'], 'fam_administracion' => ['administracion']],
        'principal_activos' => ['concentraciones' => ['concentracion']],
        'stocks' => ['stock_estado' => ['estado']],
        'devices' => ['stocks' => ['stock_id']],
        'vistaspos' => ['modulospos' => ['modulo_id']],
        'botonespos' => ['vistaspos' => ['vista_id']],
        'permisospos' => ['rolespos' => ['rol_id'], 'modulospos' => ['modulo_id'], 'vistaspos' => ['vista_id'], 'botonespos' => ['boton_id']],
        'permisosusuariospos' => ['usuariospos' => ['usuario'], 'modulospos' => ['modulo'], 'vistaspos' => ['vista'], 'botonespos' => ['actionvista']],
        'usuariospos' => ['rolespos' => ['rol_id']],
        'catalogospos' => ['stocks' => ['stock_id'], 'productos' => ['datos.producto_id'], 'prod_categorias' => ['datos.categoria_id']],
        'productos' => ['prod_categorias' => ['categoria'], 'laboratorios' => ['laboratorio'],
            'prod_unidades' => ['unidad'], 'familia' => ['familia'], 'prod_estado' => ['estado'],
            'principal_activos' => ['principios_activos.*']],
        'distribucion' => ['productos' => ['lote_datos.producto']],
        'transferencias' => ['stocks' => ['stock_de', 'stock_para'], 'transferencias_tipo' => ['tipo'],
            'transferencia_estado' => ['estado', 'estado_recepcion'], 'productos' => ['detalles.*.lote_datos.producto'],
            'distribucion' => []],
    ];

    /** @return list<string> */
    public static function orderedEntities(): array
    {
        $pending = array_keys(self::FIELDS);
        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $entity) {
                if (array_diff(array_keys(self::DEPENDENCIES[$entity] ?? []), $ordered) !== []) {
                    continue;
                }
                $ordered[] = $entity;
                unset($pending[$index]);
                $progress = true;
            }
            if (! $progress) {
                throw new \LogicException('El contrato de sincronización tiene dependencias cíclicas o desconocidas.');
            }
        }

        return $ordered;
    }

    /** @return list<array{entity: string, entity_id: int}> */
    public static function references(string $entity, array $payload): array
    {
        $references = [];
        // Same-entity references do not change the topological order of entity tables.
        if ($entity === 'productos' && isset($payload['producto_padre_id'])
            && filter_var($payload['producto_padre_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $id = (int) $payload['producto_padre_id'];
            $references['productos:'.$id] = ['entity' => 'productos', 'entity_id' => $id];
        }
        foreach (self::DEPENDENCIES[$entity] ?? [] as $parent => $paths) {
            foreach ($paths as $path) {
                foreach (Arr::flatten([data_get($payload, $path)]) as $id) {
                    if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        continue;
                    }
                    $references[$parent.':'.$id] = ['entity' => $parent, 'entity_id' => (int) $id];
                }
            }
        }

        return array_values($references);
    }
}
