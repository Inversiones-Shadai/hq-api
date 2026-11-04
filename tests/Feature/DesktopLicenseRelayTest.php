<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DesktopLicenseRelayTest extends TestCase
{
    public function test_forwards_device_proof_with_hmac_without_requiring_a_login_or_hq_device_registration(): void
    {
        config(['headquarters.apishadai_url' => 'https://central.example/api', 'headquarters.apishadai_secret' => str_repeat('s', 64), 'headquarters.apishadai_key_id' => 'test-hq']);
        Http::preventStrayRequests();
        Http::fake(['https://central.example/api/headquarters/licenses/activate' => Http::response(['data' => ['payload' => 'signed-authorization', 'signature' => 'server-signature']])]);
        $this->postJson('/api/desktop/licenses/activate', ['payload' => 'device-proof', 'signature' => 'device-signature'])
            ->assertOk()->assertJsonPath('data.payload', 'signed-authorization');
        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-HQ-Timestamp')[0];
            $nonce = $request->header('X-HQ-Nonce')[0];
            $canonical = "POST\n/api/headquarters/licenses/activate\n".$timestamp."\n".$nonce."\n".hash('sha256', $request->body());

            return $request['payload'] === 'device-proof' && $request['signature'] === 'device-signature'
                && $request->header('X-HQ-Signature')[0] === hash_hmac('sha256', $canonical, str_repeat('s', 64));
        });
    }

    public function test_does_not_accept_incomplete_proof_or_unsupported_actions(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/api/desktop/licenses/activate', [])->assertUnprocessable();
        $this->postJson('/api/desktop/licenses/delete', ['payload' => 'proof', 'signature' => 'signature'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_reports_central_outage_without_issuing_local_authorization(): void
    {
        config(['headquarters.apishadai_url' => 'https://central.example/api', 'headquarters.apishadai_secret' => str_repeat('s', 64)]);
        Http::preventStrayRequests();
        Http::fake(['https://central.example/api/headquarters/licenses/validate' => Http::response(['message' => 'offline'], 503)]);
        $this->postJson('/api/desktop/licenses/validate', ['payload' => 'proof', 'signature' => 'signature'])->assertServiceUnavailable();
        Http::assertSentCount(1);
    }

    public function test_rejects_insecure_remote_central_address_and_malformed_authorization(): void
    {
        config(['headquarters.apishadai_url' => 'http://central.example/api', 'headquarters.apishadai_secret' => str_repeat('s', 64)]);
        Http::preventStrayRequests();
        $this->postJson('/api/desktop/licenses/activate', ['payload' => 'proof', 'signature' => 'signature'])->assertServiceUnavailable();
        Http::assertNothingSent();
        config(['headquarters.apishadai_url' => 'https://central.example/api']);
        Http::fake(['https://central.example/api/headquarters/licenses/activate' => Http::response(['data' => ['allowed' => true]])]);
        $this->postJson('/api/desktop/licenses/activate', ['payload' => 'proof', 'signature' => 'signature'])->assertServiceUnavailable();
        Http::assertSentCount(1);
    }

    public function test_rejects_oversized_payload_before_relaying(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/api/desktop/licenses/activate', ['payload' => str_repeat('x', 25000), 'signature' => 'signature'])->assertStatus(413);
        Http::assertNothingSent();
    }
}
