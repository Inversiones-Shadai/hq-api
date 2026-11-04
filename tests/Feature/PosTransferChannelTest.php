<?php

namespace Tests\Feature;

use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PosTransferChannelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['headquarters.apishadai_url' => 'https://central.example.test/api', 'headquarters.apishadai_secret' => str_repeat('t', 64)]);
        $this->freezeTime();
        Http::preventStrayRequests();
    }

    private function client(): array
    {
        $uuid = '10000000-0000-4000-8000-000000000001';
        DB::table('source_device_links')->insert(['device_id' => 7, 'hq_client_uuid' => $uuid, 'stock_id' => 2, 'active' => true, 'version' => 1]);
        $device = HeadquartersDevice::factory()->create(['uuid' => $uuid, 'source_device_id' => 7, 'stock_id' => 2]);
        SyncConsumer::factory()->create(['uuid' => $uuid, 'headquarters_device_id' => $device->id, 'stock_id' => 2]);
        $key = str_repeat('b', 64);
        HeadquartersMatch::factory()->create(['source_device_id' => 7, 'stock_id' => 2, 'headquarters_device_id' => $device->id,
            'status' => 'ACTIVE', 'key_hash' => hash('sha256', $key)]);

        return ['X-Headquarters-Key' => $key, 'X-Headquarters-Client-Id' => $device->client_id, 'X-Headquarters-Name' => $device->name];
    }

    public function test_authenticated_channel_forwards_server_device_binding_and_original_payload(): void
    {
        $headers = $this->client();
        Http::fake(['https://central.example.test/api/headquarters/pos/transferencias/enviar' => Http::response(['data' => ['id' => '10']], 200)]);
        $payload = ['request_id' => '20000000-0000-4000-8000-000000000001', 'stock_de' => '2', 'stock_para' => '3', 'tipo_id' => '4', 'tipo_nombre' => 'TEST type', 'lineas' => [['cantidad' => '1.2500']]];
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/transferencias/enviar', ['payload' => $payload])->assertOk()->assertJsonPath('data.id', '10');
        Http::assertSent(fn ($request): bool => $request['device_id'] === 7
            && $request['client_uuid'] === '10000000-0000-4000-8000-000000000001'
            && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
    }

    public function test_caller_device_or_stock_override_returns_422_without_forwarding(): void
    {
        $headers = $this->client();
        Http::fake(['https://central.example.test/*' => Http::response(['data' => []], 200)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/transferencias/listar', ['payload' => [], 'device_id' => 99, 'stock_id' => 99])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_revoked_link_returns_401_without_forwarding(): void
    {
        $headers = $this->client();
        DB::table('source_device_links')->where('device_id', 7)->update(['active' => false]);
        Http::fake(['https://central.example.test/*' => Http::response(['data' => []], 200)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/transferencias/listar', ['payload' => []])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_uncertain_central_response_returns_503_without_creating_a_transfer_in_hq(): void
    {
        $headers = $this->client();
        Http::fake(['https://central.example.test/api/headquarters/pos/transferencias/recibir' => Http::response(['message' => 'TEST'], 500)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/transferencias/recibir', ['payload' => ['request_id' => '20000000-0000-4000-8000-000000000001']])->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_cancellation_and_destination_vote_keep_binding_payload_and_decision(): void
    {
        $headers = $this->client();
        foreach (['anular' => 'ANULANDO', 'confirmar-anulacion' => 'ANULADA'] as $action => $state) {
            Http::fake(['https://central.example.test/api/headquarters/pos/transferencias/'.$action => Http::response(['data' => ['id' => '10', 'estado' => $state]], 200)]);
            $payload = ['request_id' => '20000000-0000-4000-8000-000000000001', 'stock_para' => '2'];
            $this->withHeaders($headers)->postJson('/api/headquarters/pos/transferencias/'.$action, ['payload' => $payload])->assertOk()->assertJsonPath('data.estado', $state);
            Http::assertSent(fn ($request): bool => $request->url() === 'https://central.example.test/api/headquarters/pos/transferencias/'.$action
                && $request['device_id'] === 7 && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
        }
    }
}
