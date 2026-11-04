<?php

namespace Tests\Feature;

use App\Models\HeadquartersMatch;
use App\Models\SyncChange;
use App\Models\SyncConsumer;
use App\Services\Headquarters\EntityContract;
use App\Services\Headquarters\SyncPublicationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeadquartersSystemTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['headquarters.apishadai_secret' => str_repeat('a', 64), 'headquarters.apishadai_ips' => ['127.0.0.1']]);
    }

    private function signed(string $path, array $data, ?array $headers = null, string $method = 'POST'): TestResponse
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $headers ??= $this->signature($path, $body, $method);

        return $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars([...$headers, 'Accept' => 'application/json', 'Content-Type' => 'application/json']), $body);
    }

    private function signature(string $path, string $body, string $method = 'POST'): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();

        return ['X-Apishadai-Key-Id' => 'apishadai', 'X-Apishadai-Timestamp' => $timestamp, 'X-Apishadai-Nonce' => $nonce,
            'X-Apishadai-Signature' => hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), str_repeat('a', 64))];
    }

    private function register(string $name = 'HQ-01', bool $initialize = true): array
    {
        $registration = $this->signed('/api/internal/apishadai/registrations', $this->registrationPayload($name))->assertStatus(202)->json('data');
        $this->assertNotEquals($registration['approval_code'], HeadquartersMatch::firstOrFail()->approval_code_hash);
        $this->assertSame('ShHQ_Stock1', $registration['database_name']);

        $result = $this->signed('/api/internal/apishadai/registrations/confirm', [
            'registration_id' => $registration['registration_id'], 'registration_key' => $registration['registration_key'],
            'client_id' => hash_hmac('sha256', $registration['client_id_message'], $registration['registration_key']),
            'code' => $registration['approval_code'], 'ip' => '127.0.0.1',
        ])->assertCreated()->json('data');
        $this->assertSame('ShHQ_Stock1', $result['database_name']);
        $this->getJson('/api/headquarters/device', $this->clientHeaders($result))->assertOk()->assertJsonPath('data.database_name', 'ShHQ_Stock1');
        if ($initialize) {
            $headers = $this->clientHeaders($result);
            $uuid = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
            $this->postJson('/api/headquarters/sync/inicial/'.$uuid.'/confirmar', [], $headers)->assertOk();
        }

        return $result;
    }

    private function registrationPayload(string $name = 'HQ'): array
    {
        $id = (int) DB::table('source_device_links')->max('device_id') + 1;
        $uuid = (string) Str::uuid();
        DB::table('source_device_links')->insert(['device_id' => $id, 'hq_client_uuid' => $uuid, 'stock_id' => 1, 'active' => true, 'version' => 1]);

        return ['name' => $name, 'ip' => '127.0.0.1', 'source_device_id' => $id, 'hq_client_uuid' => $uuid, 'stock_id' => 1];
    }

    private function clientHeaders(array $registration): array
    {
        return ['X-Headquarters-Key' => $registration['headquarters_key'], 'X-Headquarters-Client-Id' => $registration['device']['client_id'], 'X-Headquarters-Name' => $registration['device']['name']];
    }

    private function change(int $id = 1, int $version = 1, string $action = 'CREATE'): array
    {
        return ['uuid' => (string) Str::uuid(), 'entity' => 'prod_categorias', 'entity_id' => (string) $id,
            'action' => $action, 'version' => (string) $version, 'schema_version' => 1,
            'payload' => $action === 'DELETE' ? null : ['id' => $id, 'nombre' => 'Medicamentos'], 'created_at' => now()->toIso8601String()];
    }

    public function test_clients_can_only_register_through_signed_apishadai_requests(): void
    {
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ'])->assertNotFound();
        $this->postJson('/api/internal/apishadai/registrations', ['name' => 'HQ', 'ip' => '127.0.0.1'])->assertUnauthorized();
        $registered = $this->register();
        $headers = $this->clientHeaders($registered);
        $this->getJson('/api/headquarters/device', $headers)->assertOk()->assertJsonPath('data.name', 'HQ-01')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/internal/apishadai/registrations', ['name' => 'Other', 'ip' => '127.0.0.1'], $headers)->assertUnauthorized();
        $this->assertDatabaseCount('headquarters_devices', 1);
    }

    public function test_new_clients_must_confirm_bootstrap_before_incremental_downloads(): void
    {
        $headers = $this->clientHeaders($this->register(initialize: false));
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertConflict();
        $uuid = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertConflict();
        $this->postJson('/api/headquarters/sync/inicial/'.$uuid.'/confirmar', [], $headers)->assertOk();
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk();
    }

    public function test_signature_cannot_be_replayed_or_used_with_another_body_method_or_ip(): void
    {
        $path = '/api/internal/apishadai/registrations';
        $data = $this->registrationPayload();
        $headers = $this->signature($path, json_encode($data));
        $this->signed($path, $data, $headers)->assertStatus(202);
        $this->signed($path, $data, $headers)->assertUnauthorized();
        $fresh = $this->signature($path, json_encode($data));
        $this->signed($path, [...$data, 'name' => 'Tampered'], $fresh)->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        $this->signed($path, $data)->assertUnauthorized();
        $this->assertDatabaseCount('headquarters_matches', 1);
    }

    public function test_stale_signature_is_rejected(): void
    {
        $path = '/api/internal/apishadai/changes';
        $data = ['changes' => [$this->change()]];
        $headers = $this->signature($path, json_encode($data));
        $this->travel(6)->minutes();
        $this->signed($path, $data, $headers)->assertUnauthorized();
        $this->assertDatabaseCount('sync_changes', 0);
    }

    public function test_wrong_codes_commit_attempts_and_revoke_the_request(): void
    {
        $registration = $this->signed('/api/internal/apishadai/registrations', $this->registrationPayload())->assertStatus(202)->json('data');
        $wrong = $registration['approval_code'] === '000000' ? '111111' : '000000';
        $body = ['registration_id' => $registration['registration_id'], 'registration_key' => $registration['registration_key'],
            'client_id' => hash_hmac('sha256', $registration['client_id_message'], $registration['registration_key']), 'code' => $wrong, 'ip' => '127.0.0.1'];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->signed('/api/internal/apishadai/registrations/confirm', $body)->assertUnauthorized();
            $this->assertDatabaseHas('headquarters_matches', ['uuid' => $registration['registration_id'], 'attempts' => $attempt]);
        }
        $this->signed('/api/internal/apishadai/registrations/confirm', [...$body, 'code' => $registration['approval_code']])->assertConflict();
        $this->assertDatabaseCount('headquarters_devices', 0);
    }

    #[DataProvider('clientMismatchCases')]
    public function test_device_credentials_and_active_status_are_enforced(string $case): void
    {
        $registered = $this->register();
        $headers = $this->clientHeaders($registered);
        if ($case === 'ip') {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        } elseif ($case === 'revoked') {
            HeadquartersMatch::where('status', 'ACTIVE')->update(['status' => 'REVOKED']);
        } elseif ($case === 'inactive') {
            SyncConsumer::query()->update(['status' => 'INACTIVO']);
        } else {
            $headers[$case] = $case === 'X-Headquarters-Name' ? 'Other' : str_repeat('b', 64);
        }
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertUnauthorized();
    }

    public static function clientMismatchCases(): array
    {
        return array_map(fn (string $case): array => [$case], ['ip', 'revoked', 'inactive', 'X-Headquarters-Key', 'X-Headquarters-Client-Id', 'X-Headquarters-Name']);
    }

    public function test_ingest_is_idempotent_and_conflicts_rollback_the_whole_batch(): void
    {
        $change = $this->change();
        $path = '/api/internal/apishadai/changes';
        $this->signed($path, ['changes' => [$change]])->assertOk();
        $this->signed($path, ['changes' => [$change]])->assertOk();
        $this->assertDatabaseCount('sync_changes', 1);
        $this->signed($path, ['changes' => [$this->change(2), [...$change, 'payload' => ['id' => 1, 'nombre' => 'Conflict']]]])->assertConflict();
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseCount('sync_publications', 1);
    }

    public function test_unknown_entities_and_private_payload_fields_are_rejected(): void
    {
        $change = $this->change();
        $this->signed('/api/internal/apishadai/changes', ['changes' => [[...$change, 'entity' => 'users']]])->assertUnprocessable();
        $this->signed('/api/internal/apishadai/changes', ['changes' => [[...$change, 'payload' => ['id' => 1, 'password' => 'private']]]])->assertUnprocessable();
        $this->signed('/api/internal/apishadai/changes', ['changes' => [[...$change, 'payload' => ['id' => 1]]]])->assertUnprocessable();
        $this->assertDatabaseCount('sync_changes', 0);
    }

    public function test_lot_valuation_tax_is_preserved_and_legacy_lots_remain_accepted(): void
    {
        $product = [...$this->change(), 'entity' => 'productos', 'payload' => [
            ...array_fill_keys(EntityContract::FIELDS['productos'], null), 'id' => 1,
        ]];
        $legacy = [...$this->change(), 'entity' => 'lotes', 'payload' => [
            'id' => 1, 'producto' => 1, 'lote' => 'TEST-LEGACY', 'fecha_elab' => null, 'fecha_exp' => null, 'costo' => '4.0000', 'venta' => null,
        ]];
        $tax = ['tasa_isv' => '12.5000', 'tratamiento' => 'GRAVADO'];
        $current = [...$this->change(2), 'entity' => 'lotes', 'payload' => [...$legacy['payload'], 'id' => 2, 'lote' => 'TEST-TAX', 'valoracion' => $tax]];
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$product, $legacy, $current]])->assertOk();
        $this->assertSame($tax, SyncChange::where('entity', 'lotes')->where('entity_id', 2)->firstOrFail()->payload['valoracion']);
        $invalid = [...$this->change(3), 'entity' => 'lotes', 'payload' => [...$current['payload'], 'id' => 3,
            'valoracion' => ['tasa_isv' => '101', 'tratamiento' => 'GRAVADO']]];
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$invalid]])->assertUnprocessable();
    }

    public function test_incremental_download_only_advances_after_acknowledgement_and_enforces_ownership(): void
    {
        $client = $this->register();
        $other = $this->register('HQ-02');
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change(), $this->change(2)]])->assertOk();
        $headers = $this->clientHeaders($client);
        $batch = $this->getJson('/api/headquarters/sync/cambios?limit=1', $headers)->assertOk()->assertJsonPath('data.has_more', true)->json('data');
        $this->assertDatabaseHas('sync_consumers', ['uuid' => $client['consumer_uuid'], 'last_sync_sequence' => 0]);
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $this->clientHeaders($other))->assertNotFound();
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk()->assertJsonPath('data.last_sync_sequence', '1');
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch['batch_id']], $headers)->assertOk()->assertJsonPath('data.last_sync_sequence', '1');
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->assertJsonPath('data.changes.0.entity_id', '2');
    }

    public function test_bootstrap_is_consistent_with_its_cursor_and_preserves_latest_tombstones(): void
    {
        $headers = $this->clientHeaders($this->register());
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change(1, 3, 'DELETE'), $this->change(1, 1), $this->change(2)]])->assertOk();
        $snapshot = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data');
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change(2, 2, 'UPDATE')]])->assertOk();
        $this->getJson('/api/headquarters/sync/inicial/'.$snapshot['bootstrap_id'].'/paginas/1', $headers)->assertOk()
            ->assertJsonPath('data.records.0.deleted', true)->assertJsonPath('data.records.0.version', '3')->assertJsonPath('data.records.1.version', '1');
        $this->postJson('/api/headquarters/sync/inicial/'.$snapshot['bootstrap_id'].'/confirmar', [], $headers)->assertOk()->assertJsonPath('data.last_sync_sequence', '3');
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->assertJsonPath('data.changes.0.version', '2');
    }

    public function test_expired_batches_and_snapshots_do_not_advance_cursor(): void
    {
        $headers = $this->clientHeaders($this->register());
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change()]])->assertOk();
        $batch = $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->json('data.batch_id');
        $snapshot = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $this->travel(61)->minutes();
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => $batch], $headers)->assertStatus(410);
        $this->postJson('/api/headquarters/sync/inicial/'.$snapshot.'/confirmar', [], $headers)->assertStatus(410);
        $this->assertDatabaseHas('sync_consumers', ['last_sync_sequence' => 0]);
    }

    public function test_expired_registration_cannot_create_a_device(): void
    {
        $registration = $this->signed('/api/internal/apishadai/registrations', $this->registrationPayload())->assertStatus(202)->json('data');
        $this->travel(11)->minutes();
        $this->signed('/api/internal/apishadai/registrations/confirm', [
            'registration_id' => $registration['registration_id'], 'registration_key' => $registration['registration_key'],
            'client_id' => hash_hmac('sha256', $registration['client_id_message'], $registration['registration_key']),
            'code' => $registration['approval_code'], 'ip' => '127.0.0.1',
        ])->assertStatus(410);
        $this->assertDatabaseCount('headquarters_devices', 0);
        $this->assertDatabaseHas('headquarters_matches', ['status' => 'REVOKED']);
    }

    public function test_bootstrap_pages_and_confirmation_are_bound_to_their_consumer(): void
    {
        $first = $this->clientHeaders($this->register());
        $second = $this->clientHeaders($this->register('HQ-02'));
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change()]])->assertOk();
        $uuid = $this->postJson('/api/headquarters/sync/inicial', [], $first)->assertCreated()->json('data.bootstrap_id');
        $this->getJson('/api/headquarters/sync/inicial/'.$uuid.'/paginas/1', $second)->assertNotFound();
        $this->postJson('/api/headquarters/sync/inicial/'.$uuid.'/confirmar', [], $second)->assertNotFound();
    }

    public function test_retention_floor_requires_bootstrap_and_then_allows_incremental_reads(): void
    {
        $headers = $this->clientHeaders($this->register());
        $this->signed('/api/internal/apishadai/changes', ['changes' => [$this->change()]])->assertOk();
        DB::table('sync_stream_state')->where('name', 'headquarters')->update(['retention_floor' => 1]);
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertStatus(410);
        $uuid = $this->postJson('/api/headquarters/sync/inicial', [], $headers)->assertCreated()->json('data.bootstrap_id');
        $this->postJson('/api/headquarters/sync/inicial/'.$uuid.'/confirmar', [], $headers)->assertOk();
        $this->getJson('/api/headquarters/sync/cambios', $headers)->assertOk()->assertJsonPath('data.changes', []);
    }

    public function test_changes_remain_immutable_even_with_quiet_updates(): void
    {
        $change = SyncChange::factory()->create();
        $this->expectException(\LogicException::class);
        $change->updateQuietly(['version' => 9]);
    }

    public function test_late_lower_ids_receive_later_publication_sequences(): void
    {
        $high = SyncChange::factory()->create(['id' => 41]);
        $publisher = app(SyncPublicationService::class);
        $publisher->publish();
        $low = SyncChange::factory()->create(['id' => 40]);
        $publisher->publish();
        $this->assertSame([41, 40], DB::table('sync_publications')->orderBy('sequence')->pluck('sync_change_id')->all());
        $this->assertSame([1, 2], DB::table('sync_publications')->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_only_signed_apishadai_can_revoke_a_client(): void
    {
        $client = $this->register();
        $headers = $this->clientHeaders($client);
        $path = '/api/internal/apishadai/devices/'.$client['device']['uuid'];
        $this->deleteJson($path, [], $headers)->assertUnauthorized();
        $this->signed($path, [], method: 'DELETE')->assertOk();
        $this->getJson('/api/headquarters/device', $headers)->assertUnauthorized();
        $this->assertDatabaseHas('sync_consumers', ['uuid' => $client['consumer_uuid'], 'status' => 'INACTIVO']);
    }
}
