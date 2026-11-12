<?php

namespace Tests\Feature;

use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventoryMatrixProxyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['headquarters.apishadai_url' => 'https://central.example.test/api', 'headquarters.apishadai_secret' => str_repeat('t', 64)]);
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

    public function test_channel_preserves_request_and_uses_authenticated_server_binding(): void
    {
        $headers = $this->client();
        $payload = ['request_id' => '20000000-0000-4000-8000-000000000001', 'accion' => 'ENTRADA', 'registrado_at' => '2026-10-01T08:00:00-06:00'];
        Http::fake(['https://central.example.test/api/headquarters/pos/inventario/matriz/guardar' => Http::response(['data' => ['aceptada' => true]], 200)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => $payload])->assertOk()->assertJsonPath('data.aceptada', true);
        Http::assertSent(fn ($request): bool => $request['device_id'] === 7 && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
    }

    public function test_callers_cannot_replace_the_registered_device(): void
    {
        $headers = $this->client();
        Http::fake();
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => [], 'device_id' => 99])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_rejected_business_operation_is_a_confirmed_response_not_a_transport_failure(): void
    {
        $headers = $this->client();
        Http::fake(['https://central.example.test/api/headquarters/pos/inventario/matriz/guardar' => Http::response(['data' => ['aceptada' => false, 'mensaje' => 'TEST rechazo']], 200)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => []])->assertOk()->assertJsonPath('data.aceptada', false);
    }

    public function test_timeout_keeps_the_original_operation_pending(): void
    {
        $headers = $this->client();
        Http::fake(['https://central.example.test/*' => Http::response(['message' => 'TEST no confirmado'], 500)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => []])->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_branch_exchange_preserves_the_signed_binding_and_does_not_accept_a_replacement(): void
    {
        $headers = $this->client();
        $payload = ['stock_id' => '2', 'balance_after' => '11', 'alert_after' => '7', 'upload' => null];
        $path = '/api/headquarters/pos/inventario/matriz/sucursales';
        Http::fake(['https://central.example.test'.$path => Http::response(['data' => ['stock_id' => 2]], 200)]);
        $this->withHeaders($headers)->postJson($path, ['payload' => $payload, 'device_id' => 99])->assertUnprocessable();
        $this->postJson($path, ['payload' => $payload])->assertOk()->assertJsonPath('data.stock_id', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['device_id'] === 7 && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
    }

    public function test_lost_sales_are_forwarded_with_the_registered_stock_and_an_hmac_signature(): void
    {
        $path = '/api/headquarters/pos/ventas-perdidas';
        $requestId = '20000000-0000-4000-8000-000000000001';
        $rows = [['request_id' => $requestId, 'producto_id' => '8', 'usuario_pos_id' => '12', 'reported_at' => '2026-11-11T19:00:00Z']];
        $payload = ['stock_id' => '2', 'rows' => $rows];
        $result = ['stock_id' => '2', 'results' => [['request_id' => $requestId, 'accepted' => true]]];
        Http::fake(['https://central.example.test'.$path => Http::response(['data' => $result])]);

        $this->postJson($path, ['payload' => $payload])->assertUnauthorized();
        $headers = $this->client();
        $this->withHeaders($headers)->postJson($path, ['payload' => ['stock_id' => '3', 'rows' => $rows]])->assertForbidden();
        $this->postJson($path, ['payload' => $payload, 'device_id' => 99])->assertUnprocessable();
        $this->postJson($path, ['payload' => $payload])->assertOk()->assertJsonPath('data', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['device_id'] === 7 && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
    }

    public function test_matrix_requires_headquarters_authentication(): void
    {
        $this->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => []])->assertUnauthorized();
    }

    public function test_automatic_balance_snapshot_is_forwarded_without_changing_quantity_or_identity(): void
    {
        $headers = $this->client();
        $payload = ['version' => 2, 'tipo' => 'EXISTENCIAS', 'request_id' => '20000000-0000-4000-8000-000000000001',
            'stock_id' => '2', 'producto_id' => '10000000000000000001', 'revision' => 5, 'existencia_total' => '2.1250'];
        Http::fake(['https://central.example.test/api/headquarters/pos/inventario/matriz/guardar' => Http::response(['data' => ['accepted' => true]], 200)]);
        $this->withHeaders($headers)->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => $payload])
            ->assertOk()->assertJsonPath('data.accepted', true);
        Http::assertSent(fn ($request): bool => $request['device_id'] === 7 && $request['payload'] === $payload && $request->hasHeader('X-HQ-Signature'));
    }
}
