<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopUpdateRelayTest extends TestCase
{
    private function configureChannel(): void
    {
        config(['headquarters.apishadai_url' => 'https://central.example/api', 'headquarters.apishadai_secret' => str_repeat('s', 64),
            'headquarters.apishadai_key_id' => 'test-hq']);
        Http::preventStrayRequests();
    }

    public static function actions(): array
    {
        return ['check' => ['check'], 'download' => ['download'], 'apply' => ['apply'], 'report' => ['report']];
    }

    #[DataProvider('actions')]
    public function test_relays_unchanged_signed_envelope_with_path_bound_hmac_without_login(string $action): void
    {
        $this->configureChannel();
        $url = 'https://central.example/api/headquarters/updates/'.$action;
        Http::fake([$url => Http::response(['data' => ['payload' => 'opaque-server-payload', 'signature' => 'opaque-server-signature']])]);

        $this->postJson('/api/desktop/updates/'.$action, ['payload' => 'opaque-device-proof', 'signature' => 'opaque-device-signature'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.payload', 'opaque-server-payload')
            ->assertJsonPath('data.signature', 'opaque-server-signature');

        Http::assertSent(function ($request) use ($action): bool {
            $timestamp = $request->header('X-HQ-Timestamp')[0];
            $nonce = $request->header('X-HQ-Nonce')[0];
            $canonical = "POST\n/api/headquarters/updates/".$action."\n".$timestamp."\n".$nonce."\n".hash('sha256', $request->body());

            return $request['payload'] === 'opaque-device-proof' && $request['signature'] === 'opaque-device-signature'
                && $request->header('X-HQ-Signature')[0] === hash_hmac('sha256', $canonical, str_repeat('s', 64));
        });
    }

    public function test_returns_422_for_missing_proof_or_unsigned_extra_fields_and_404_for_unsupported_action(): void
    {
        $this->configureChannel();
        $this->postJson('/api/desktop/updates/check', [])->assertUnprocessable();
        $this->postJson('/api/desktop/updates/check', ['payload' => 'proof', 'signature' => 'signature', 'object_key' => 'other.msix'])->assertUnprocessable();
        $this->postJson('/api/desktop/updates/withdraw', ['payload' => 'proof', 'signature' => 'signature'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_returns_503_for_central_outage_without_local_authorization_or_link(): void
    {
        $this->configureChannel();
        Http::fake(['https://central.example/api/headquarters/updates/download' => Http::failedConnection()]);
        $this->postJson('/api/desktop/updates/download', ['payload' => 'proof', 'signature' => 'signature'])
            ->assertServiceUnavailable()->assertJsonPath('data', null);
        Http::assertSentCount(1);
    }

    public function test_does_not_follow_upstream_redirect_or_expose_its_location(): void
    {
        $this->configureChannel();
        Http::fake(['https://central.example/api/headquarters/updates/check' => Http::response('', 302, ['Location' => 'https://other.example/private'])]);
        $this->postJson('/api/desktop/updates/check', ['payload' => 'proof', 'signature' => 'signature'])
            ->assertServiceUnavailable()->assertJsonPath('data', null)->assertDontSee('other.example');
        Http::assertSentCount(1);
    }

    public function test_returns_503_for_malformed_or_oversized_central_authorization(): void
    {
        $this->configureChannel();
        Http::fake(['https://central.example/api/headquarters/updates/check' => Http::sequence()
            ->push(['data' => ['allowed' => true]])->push(['data' => ['payload' => str_repeat('a', 131072), 'signature' => 'signature']])]);
        $envelope = ['payload' => 'proof', 'signature' => 'signature'];
        $this->postJson('/api/desktop/updates/check', $envelope)->assertServiceUnavailable()->assertJsonPath('data', null);
        $this->postJson('/api/desktop/updates/check', $envelope)->assertServiceUnavailable()->assertJsonPath('data', null);
        Http::assertSentCount(2);
    }

    public function test_returns_503_for_insecure_central_configuration_without_forwarding(): void
    {
        $this->configureChannel();
        config(['headquarters.apishadai_url' => 'http://central.example/api']);
        $this->postJson('/api/desktop/updates/check', ['payload' => 'proof', 'signature' => 'signature'])->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_returns_413_for_oversized_proof_before_forwarding(): void
    {
        $this->configureChannel();
        $this->postJson('/api/desktop/updates/check', ['payload' => str_repeat('x', 25000), 'signature' => 'signature'])->assertStatus(413);
        Http::assertNothingSent();
    }

    public function test_returns_429_after_relay_rate_limit_without_forwarding_the_last_attempt(): void
    {
        $this->configureChannel();
        Http::fake(['https://central.example/api/headquarters/updates/check' => Http::response(['data' => ['payload' => 'signed', 'signature' => 'signature']])]);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/desktop/updates/check', ['payload' => 'proof', 'signature' => 'signature'])->assertOk();
        }
        $this->postJson('/api/desktop/updates/check', ['payload' => 'proof', 'signature' => 'signature'])->assertTooManyRequests();
        Http::assertSentCount(30);
    }
}
