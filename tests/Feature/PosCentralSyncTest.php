<?php

namespace Tests\Feature;

use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PosCentralSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['headquarters.apishadai_secret' => str_repeat('a', 64), 'headquarters.apishadai_ips' => ['127.0.0.1']]);
    }

    private function signed(string $path, array $data): TestResponse
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', "POST\n$path\n$timestamp\n$nonce\n".hash('sha256', $body), str_repeat('a', 64));

        return $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Apishadai-Key-Id' => 'apishadai',
            'X-Apishadai-Timestamp' => $timestamp, 'X-Apishadai-Nonce' => $nonce, 'X-Apishadai-Signature' => $signature,
        ]), $body);
    }

    private function ingest(array $changes): TestResponse
    {
        return $this->signed('/api/internal/apishadai/changes', ['changes' => $changes]);
    }

    private function binding(int $id, int $stock, string $uuid, int $version = 1, bool $active = true): array
    {
        return ['uuid' => (string) Str::uuid(), 'entity' => 'devices', 'entity_id' => (string) $id, 'action' => 'UPDATE',
            'version' => (string) $version, 'schema_version' => 1, 'created_at' => now()->toIso8601String(),
            'payload' => ['id' => $id, 'stock_id' => $stock, 'hq_client_uuid' => $uuid, 'active' => $active]];
    }

    private function client(int $id, int $stock): array
    {
        $catalogs = [];
        foreach ([1, 2, 3] as $stockId) {
            $catalogs[] = ['uuid' => (string) Str::uuid(), 'entity' => 'stocks', 'entity_id' => (string) $stockId, 'action' => 'CREATE',
                'version' => '1', 'schema_version' => 1, 'created_at' => now()->toIso8601String(),
                'payload' => ['id' => $stockId, 'descripcion' => 'Stock '.$stockId, 'telefono' => null, 'ubicacion' => null, 'estado' => null]];
        }
        $catalogs[] = ['uuid' => (string) Str::uuid(), 'entity' => 'transferencias_tipo', 'entity_id' => '1', 'action' => 'CREATE',
            'version' => '1', 'schema_version' => 1, 'created_at' => now()->toIso8601String(), 'payload' => ['id' => 1, 'descripcion' => 'Traslado']];
        if (! DB::table('sync_changes')->where('entity', 'stocks')->exists()) {
            $this->ingest($catalogs)->assertOk();
        }
        $cursor = (int) DB::table('sync_stream_state')->where('name', 'headquarters')->value('published_sequence');
        $uuid = (string) Str::uuid();
        $this->ingest([$this->binding($id, $stock, $uuid)])->assertOk();
        $device = HeadquartersDevice::factory()->create(['uuid' => $uuid, 'source_device_id' => $id, 'stock_id' => $stock]);
        $consumer = SyncConsumer::factory()->create(['uuid' => $uuid, 'headquarters_device_id' => $device->id, 'stock_id' => $stock, 'last_sync_sequence' => $cursor]);
        $key = bin2hex(random_bytes(32));
        HeadquartersMatch::factory()->create(['source_device_id' => $id, 'stock_id' => $stock, 'headquarters_device_id' => $device->id,
            'status' => 'ACTIVE', 'key_hash' => hash('sha256', $key)]);

        return [$device, $consumer, ['X-Headquarters-Key' => $key, 'X-Headquarters-Client-Id' => $device->client_id, 'X-Headquarters-Name' => $device->name]];
    }

    private function central(string $entity, int $id, array $payload, array $stocks = [], int $version = 1, array $removed = []): array
    {
        return ['uuid' => (string) Str::uuid(), 'entity' => $entity, 'entity_id' => (string) $id, 'action' => 'UPDATE', 'version' => (string) $version, 'schema_version' => 1, 'created_at' => now()->toIso8601String(), 'payload' => ['id' => $id] + $payload, 'stock_ids' => $stocks, 'removed_stock_ids' => $removed];
    }

    private function credentials(): array
    {
        return ['version' => 2, 'purpose' => 'ShPOS', 'algorithm' => 'PBKDF2-SHA512', 'usuario' => base64_encode('test.operator'),
            'hash' => base64_encode(str_repeat('h', 64)), 'salt' => base64_encode(str_repeat('s', 32)), 'iterations' => 210000];
    }

    public function test_global_discount_reaches_current_and_future_stocks_and_deactivates_everywhere(): void
    {
        [, , $first] = $this->client(1, 1);
        [, , $second] = $this->client(2, 2);
        $rule = ['stock_id' => null, 'tipo' => 'DESCUENTO', 'codigo' => 'categoria-10', 'activo' => true, 'lote_datos' => null,
            'datos' => ['version' => 2, 'revision' => 1, 'alcance' => 'CATEGORIA', 'referencia_id' => '10', 'categoria_id' => '10', 'producto_id' => null, 'regla' => ['modo' => 'EXCLUIR']]];
        $this->ingest([$this->central('prod_categorias', 10, ['nombre' => 'TEST category']), $this->central('catalogospos', 20, $rule, [0])])->assertOk();
        foreach ([$first, $second] as $headers) {
            $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data');
            $rules = array_values(array_filter($batch['changes'], fn (array $change): bool => $change['entity'] === 'catalogospos'));
            $this->assertSame(['20'], array_column($rules, 'entity_id'));
            $this->assertNull($rules[0]['payload']['stock_id']);
            $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk();
        }
        [, , $future] = $this->client(3, 3);
        $bootstrap = $this->postJson('/api/headquarters/sync/inicial', [], $future)->assertCreated()->json('data.bootstrap_id');
        $records = $this->getJson('/api/headquarters/sync/inicial/'.$bootstrap.'/paginas/1', $future)->assertOk()->json('data.records');
        $rules = array_values(array_filter($records, fn (array $record): bool => $record['entity'] === 'catalogospos'));
        $this->assertSame(['20'], array_column($rules, 'entity_id'));
        $this->postJson('/api/headquarters/sync/inicial/'.$bootstrap.'/confirmar', [], $future)->assertOk();
        $this->ingest([$this->central('catalogospos', 20, [...$rule, 'activo' => false], [0], 2)])->assertOk();
        foreach ([$first, $second, $future] as $headers) {
            $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data');
            $rules = array_values(array_filter($batch['changes'], fn (array $change): bool => $change['entity'] === 'catalogospos'));
            $this->assertSame(['20'], array_column($rules, 'entity_id'));
            $this->assertFalse($rules[0]['payload']['activo']);
            $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk();
        }
        $deleted = $this->central('catalogospos', 20, [], [0], 3);
        $deleted['action'] = 'DELETE';
        $deleted['payload'] = null;
        $this->ingest([$deleted])->assertOk();
        foreach ([$first, $second, $future] as $headers) {
            $rules = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.changes');
            $this->assertSame('DELETE', $rules[0]['action']);
            $this->assertNull($rules[0]['payload']);
        }
    }

    public function test_global_scope_rejects_payment_catalogs_stock_selection_and_transfer_wildcards(): void
    {
        $payment = ['stock_id' => 1, 'tipo' => 'MEDIO_PAGO', 'codigo' => 'TEST', 'datos' => [], 'activo' => true, 'lote_datos' => null];
        $this->ingest([$this->central('catalogospos', 20, $payment, [0])])->assertUnprocessable();
        $this->ingest([$this->central('catalogospos', 20, [...$payment, 'stock_id' => null], [0])])->assertUnprocessable();
        $this->ingest([$this->central('catalogospos', 20, [...$payment, 'tipo' => 'DESCUENTO'], [0])])->assertUnprocessable();
        $this->ingest([$this->central('catalogospos', 20, [...$payment, 'tipo' => 'DESCUENTO', 'stock_id' => null], [1])])->assertUnprocessable();
        $this->ingest([$this->central('transferencias', 20, [], [0])])->assertUnprocessable();
        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_global_users_are_delivered_to_every_stock_while_catalogs_remain_scoped(): void
    {
        [,,$first] = $this->client(1, 1);
        [,,$second] = $this->client(2, 2);
        $role = $this->central('rolespos', 5, ['nombre' => 'TEST role', 'nivel' => 2, 'activo' => true]);
        $user = ['rol_id' => 5, 'nombre' => base64_encode('TEST José'), 'activo' => true, 'credenciales' => $this->credentials()];
        $this->ingest([$role, $this->central('usuariospos', 10, $user),
            $this->central('catalogospos', 20, ['stock_id' => 1, 'tipo' => 'MEDIO_PAGO', 'codigo' => 'TEST', 'datos' => ['codigo' => 'TEST', 'descripcion' => 'TEST', 'es_efectivo' => true, 'requiere_referencia' => false], 'activo' => true, 'lote_datos' => null], [1])])->assertOk();
        foreach ([$first, $second] as $index => $headers) {
            $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data');
            $users = array_values(array_filter($batch['changes'], fn (array $change): bool => $change['entity'] === 'usuariospos'));
            $catalogs = array_values(array_filter($batch['changes'], fn (array $change): bool => $change['entity'] === 'catalogospos'));
            $this->assertSame(['10'], array_column($users, 'entity_id'));
            $this->assertSame(['id' => 10] + $user, $users[0]['payload']);
            $this->assertCount($index === 0 ? 1 : 0, $catalogs);
            $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk();
        }
        $this->ingest([$this->central('usuariospos', 10, [...$user, 'activo' => false], [], 2)])->assertOk();
        foreach ([$first, $second] as $headers) {
            $changes = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.changes');
            $this->assertSame('UPDATE', $changes[0]['action']);
            $this->assertFalse($changes[0]['payload']['activo']);
        }
    }

    public function test_delivers_pos_hierarchy_parents_before_grants_and_rejects_portal_fields(): void
    {
        [, , $headers] = $this->client(1, 1);
        $grant = ['rol_id' => 5, 'modulo_id' => 1, 'vista_id' => 2, 'boton_id' => 3, 'activo' => true];
        $changes = [
            $this->central('permisospos', 4, $grant),
            $this->central('botonespos', 3, ['vista_id' => 2, 'codigo' => 'configurar', 'nombre' => 'TEST botón', 'activo' => true]),
            $this->central('vistaspos', 2, ['modulo_id' => 1, 'codigo' => 'configuracion.establecimiento', 'nombre' => 'TEST vista', 'activo' => true]),
            $this->central('modulospos', 1, ['codigo' => 'configuracion', 'nombre' => 'TEST módulo', 'activo' => true]),
            $this->central('rolespos', 5, ['nombre' => 'TEST rol', 'nivel' => 2, 'activo' => true]),
        ];

        $this->ingest($changes)->assertOk();
        $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.changes');

        $entities = array_column($batch, 'entity');
        foreach (['rolespos', 'modulospos', 'vistaspos', 'botonespos'] as $parent) {
            $this->assertLessThan(array_search('permisospos', $entities, true), array_search($parent, $entities, true));
        }
        $this->ingest([$this->central('permisospos', 8, [...$grant, 'portal_permission' => 9])])->assertUnprocessable();
        $this->ingest([$this->central('permisospos', 8, [...$grant, 'activo' => 'true'])])->assertUnprocessable();
        $this->assertSame(1, DB::table('sync_changes')->where('entity', 'permisospos')->count());
    }

    public function test_direct_user_permissions_are_global_ordered_and_revoked_in_every_stock(): void
    {
        [,,$first] = $this->client(1, 1);
        [,,$second] = $this->client(2, 2);
        $user = ['rol_id' => 5, 'nombre' => base64_encode('TEST user'), 'activo' => true, 'credenciales' => $this->credentials()];
        $grant = ['usuario' => 10, 'modulo' => 1, 'vista' => 2, 'actionvista' => 3, 'tipo_tiempo' => 1, 'lifetime' => 4102444800];
        $this->ingest([
            $this->central('permisosusuariospos', 6, $grant),
            $this->central('rolespos', 5, ['nombre' => 'TEST nivel', 'nivel' => 2, 'activo' => true]),
            $this->central('usuariospos', 10, $user),
            $this->central('usuariospos', 11, [...$user, 'nombre' => base64_encode('TEST other')]),
            $this->central('modulospos', 1, ['codigo' => 'configuracion', 'nombre' => 'TEST módulo', 'activo' => true]),
            $this->central('vistaspos', 2, ['modulo_id' => 1, 'codigo' => 'configuracion.establecimiento', 'nombre' => 'TEST vista', 'activo' => true]),
            $this->central('botonespos', 3, ['vista_id' => 2, 'codigo' => 'configurar', 'nombre' => 'TEST botón', 'activo' => true]),
            $this->central('permisosusuariospos', 7, [...$grant, 'usuario' => 11]),
        ])->assertOk();
        foreach ([$first, $second] as $headers) {
            $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data');
            $entities = array_column($batch['changes'], 'entity');
            foreach (['usuariospos', 'modulospos', 'vistaspos', 'botonespos'] as $parent) {
                $this->assertLessThan(array_search('permisosusuariospos', $entities, true), array_search($parent, $entities, true));
            }
            $permissions = array_values(array_filter($batch['changes'], fn (array $change): bool => $change['entity'] === 'permisosusuariospos'));
            $this->assertSame(['6', '7'], array_column($permissions, 'entity_id'));
            $this->assertSame(['id' => 6] + $grant, $permissions[0]['payload']);
            $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk();
        }
        $deleted = $this->central('permisosusuariospos', 6, [], [], 2);
        $deleted['action'] = 'DELETE';
        $deleted['payload'] = null;
        $this->ingest([$deleted])->assertOk();
        foreach ([$first, $second] as $headers) {
            $changes = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.changes');
            $this->assertSame('DELETE', $changes[0]['action']);
            $this->assertSame('permisosusuariospos', $changes[0]['entity']);
            $this->assertNull($changes[0]['payload']);
        }
    }

    public function test_direct_permissions_reject_role_fields_false_scope_and_missing_expiry_fields(): void
    {
        $grant = ['usuario' => 10, 'modulo' => 1, 'vista' => 2, 'actionvista' => 3, 'tipo_tiempo' => null, 'lifetime' => null];
        $this->ingest([$this->central('permisosusuariospos', 6, [...$grant, 'rol_id' => 5])])->assertUnprocessable();
        $this->ingest([$this->central('permisosusuariospos', 6, $grant, [2])])->assertUnprocessable();
        $this->ingest([$this->central('permisosusuariospos', 6, [...$grant, 'usuario' => true])])->assertUnprocessable();
        $this->ingest([$this->central('permisosusuariospos', 6, [...$grant, 'lifetime' => true])])->assertUnprocessable();
        $this->ingest([$this->central('rolespos', 5, ['nombre' => 'TEST', 'nivel' => true, 'activo' => true])])->assertUnprocessable();
        unset($grant['lifetime']);
        $this->ingest([$this->central('permisosusuariospos', 6, $grant)])->assertUnprocessable();
        $this->ingest([$this->central('rolespos', 5, ['nombre' => 'TEST', 'nivel' => -1, 'activo' => true])])->assertUnprocessable();
        $this->ingest([$this->central('rolespos', 5, ['nombre' => 'TEST', 'nivel' => 2, 'permisos' => [], 'activo' => true])])->assertUnprocessable();
        $this->assertDatabaseCount('sync_changes', 0);
    }

    public function test_plain_credentials_extra_fields_and_a_false_stock_scope_are_rejected_before_storage(): void
    {
        $user = ['rol_id' => 5, 'nombre' => base64_encode('TEST'), 'activo' => true, 'credenciales' => ['usuario' => 'plaintext', 'password' => 'plaintext']];
        $this->ingest([$this->central('usuariospos', 10, $user)])->assertUnprocessable();
        $user['credenciales'] = $this->credentials();
        $user['password'] = 'plaintext';
        $this->ingest([$this->central('usuariospos', 10, $user)])->assertUnprocessable();
        unset($user['password']);
        $this->ingest([$this->central('usuariospos', 10, $user, [2])])->assertUnprocessable();
        $this->ingest([$this->central('usuariospos', 10, [...$user, 'stock_id' => 1])])->assertUnprocessable();
        $this->assertSame(0, DB::table('sync_changes')->where('entity', 'usuariospos')->count());
    }
}
