<?php

namespace Tests\Feature;

use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use App\Services\Headquarters\EntityContract;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HeadquartersStockScopeTest extends TestCase
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
        $this->ingest($catalogs)->assertOk();
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

    private function transfer(int $id, int $from, int $to, int $version = 1, string $action = 'CREATE', array $removed = []): array
    {
        $payload = array_fill_keys(EntityContract::FIELDS['transferencias'], null);
        $payload = [...$payload, 'id' => $id, 'stock_de' => $from, 'stock_para' => $to, 'tipo' => 1,
            'items' => 2, 'total' => '20.00', 'detalles' => [['id' => 1, 'cantidad' => 2, 'lote' => 1]]];

        return ['uuid' => (string) Str::uuid(), 'entity' => 'transferencias', 'entity_id' => (string) $id, 'action' => $action,
            'version' => (string) $version, 'schema_version' => 1, 'payload' => $action === 'DELETE' ? null : $payload,
            'stock_ids' => [$from, $to], 'removed_stock_ids' => $removed, 'created_at' => now()->toIso8601String()];
    }

    public function test_incremental_and_current_queries_only_return_transfers_of_the_linked_stock(): void
    {
        [, , $headers] = $this->client(1, 1);
        $this->ingest([$this->transfer(10, 1, 2), $this->transfer(11, 2, 3), $this->transfer(12, 3, 1)])->assertOk();
        $download = $this->getJson('/api/headquarters/sync/cambios?stock_id=2', $headers)->assertOk()->json('data');
        $this->assertSame(['10', '12'], array_column($download['changes'], 'entity_id'));
        $this->assertNotNull($download['batch_id']);
        $this->getJson('/api/headquarters/transferencias', $headers)->assertOk()->assertJsonPath('data.stock_id', 1)->assertJsonPath('data.total', 2);
        $this->getJson('/api/headquarters/transferencias/11', $headers)->assertNotFound();
        $this->getJson('/api/headquarters/transferencias/10', $headers)->assertOk()->assertJsonPath('data.payload.stock_para', 2);
        $this->getJson('/api/headquarters/transferencias?stock_id=2', $headers)->assertUnprocessable();
    }

    public function test_an_empty_filtered_batch_still_advances_past_unrelated_and_internal_changes_only_after_ack(): void
    {
        [, $consumer, $headers] = $this->client(1, 1);
        $cursor = $consumer->last_sync_sequence;
        $this->ingest([$this->transfer(10, 2, 3)])->assertOk();
        $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->assertJsonPath('data.changes', [])->json('data');
        $this->assertNotNull($batch['batch_id']);
        $this->assertSame($cursor, $consumer->fresh()->last_sync_sequence);
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk();
        $this->assertSame((int) $batch['to_sequence'], $consumer->fresh()->last_sync_sequence);
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->assertJsonPath('data.batch_id', null);
    }

    public function test_bootstrap_filters_transfers_and_never_exposes_device_bindings(): void
    {
        [, , $headers] = $this->client(1, 1);
        $this->ingest([$this->transfer(10, 1, 2), $this->transfer(11, 2, 3), $this->transfer(12, 1, 3, 2, 'DELETE')])->assertOk();
        $uuid = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $page = $this->getJson('/api/headquarters/sync/inicial/'.$uuid.'/paginas/1', $headers)->assertOk()->json('data.records');
        $this->assertNotContains('devices', array_column($page, 'entity'));
        $page = array_values(array_filter($page, fn (array $record): bool => $record['entity'] === 'transferencias'));
        $this->assertSame(['10', '12'], array_column($page, 'entity_id'));
        $this->assertSame(['transferencias', 'transferencias'], array_column($page, 'entity'));
        $this->assertTrue($page[1]['deleted']);
    }

    public function test_scope_changes_invalidate_old_batches_and_pages_and_require_bootstrap_for_the_new_stock(): void
    {
        [$device, , $headers] = $this->client(1, 1);
        $this->ingest([$this->transfer(10, 1, 3), $this->transfer(11, 2, 3)])->assertOk();
        $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.batch_id');
        $old = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $this->ingest([$this->binding(1, 2, $device->uuid, 2)])->assertOk();
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertConflict();
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch], $headers)->assertConflict();
        $this->getJson('/api/headquarters/sync/inicial/'.$old.'/paginas/1', $headers)->assertConflict();
        $this->postJson('/api/headquarters/sync/inicial/'.$old.'/confirmar', [], $headers)->assertConflict();
        $new = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $page = $this->getJson('/api/headquarters/sync/inicial/'.$new.'/paginas/1', $headers)->assertOk()->json('data.records');
        $page = array_values(array_filter($page, fn (array $record): bool => $record['entity'] === 'transferencias'));
        $this->assertSame(['11'], array_column($page, 'entity_id'));
        $this->postJson('/api/headquarters/sync/inicial/'.$new.'/confirmar', [], $headers)->assertOk();
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk();
        $this->getJson('/api/headquarters/transferencias/10', $headers)->assertNotFound();
    }

    public function test_old_stocks_receive_only_a_redacted_delete_when_transfer_participants_change(): void
    {
        [, , $headers] = $this->client(1, 1);
        $this->ingest([$this->transfer(10, 1, 2), $this->transfer(10, 2, 3, 2, 'UPDATE', [1])])->assertOk();
        $changes = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.changes');
        $this->assertSame('DELETE', $changes[1]['action']);
        $this->assertNull($changes[1]['payload']);
        $this->assertSame('2', $changes[1]['version']);
        $this->getJson('/api/headquarters/transferencias/10', $headers)->assertNotFound();
    }

    public function test_device_deactivation_and_deletion_block_credentials_and_old_binding_updates_cannot_restore_access(): void
    {
        [$device, , $headers] = $this->client(1, 1);
        $this->ingest([$this->binding(1, 1, $device->uuid, 3, false), $this->binding(1, 1, $device->uuid, 2, true)])->assertOk();
        $this->getJson('/api/headquarters/device', $headers)->assertUnauthorized();
        $this->ingest([$this->binding(1, 1, $device->uuid, 4, true)])->assertOk();
        $this->getJson('/api/headquarters/device', $headers)->assertOk();
        $delete = [...$this->binding(1, 1, $device->uuid, 5), 'action' => 'DELETE', 'payload' => null];
        $this->ingest([$delete])->assertOk();
        $this->getJson('/api/headquarters/device', $headers)->assertUnauthorized();
    }

    public function test_scope_validation_rejects_missing_or_mismatched_transfer_participants(): void
    {
        $change = $this->transfer(1, 1, 2);
        $this->ingest([[...$change, 'stock_ids' => []]])->assertUnprocessable();
        $this->ingest([[...$change, 'stock_ids' => [2, 3]]])->assertUnprocessable();
        $this->ingest([[...$change, 'removed_stock_ids' => [1]]])->assertUnprocessable();
        $this->assertDatabaseCount('sync_changes', 0);
    }

    public function test_registration_requires_matching_binding_and_only_one_authorized_client_per_device(): void
    {
        [$device, , $headers] = $this->client(1, 1);
        $body = ['name' => 'New', 'ip' => '127.0.0.1', 'source_device_id' => 1, 'stock_id' => 2, 'hq_client_uuid' => $device->uuid];
        $this->signed('/api/internal/apishadai/registrations', $body)->assertConflict();
        $this->signed('/api/internal/apishadai/registrations', [...$body, 'stock_id' => 1])->assertConflict();
        $this->assertDatabaseCount('headquarters_devices', 1);
        $this->getJson('/api/headquarters/device', $headers)->assertOk()->assertJsonPath('data.device_id', 1);
    }
}
