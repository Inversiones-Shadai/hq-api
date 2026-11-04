<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpSecurityTest extends TestCase
{
    public function test_hq_limits_invalid_keys_and_rotating_keys_before_database_validation(): void
    {
        config([
            'security.rate_limits.headquarters_ip_per_minute' => 3,
            'security.rate_limits.headquarters_key_per_minute' => 2,
        ]);

        $this->getJson('/api/headquarters/device')->assertUnauthorized();
        $this->getJson('/api/headquarters/device')->assertUnauthorized();
        $this->getJson('/api/headquarters/device')->assertTooManyRequests()->assertJsonPath('code', 429);
        $this->getJson('/api/headquarters/device', ['X-Headquarters-Key' => 'rotated'])->assertUnauthorized();
        $this->getJson('/api/headquarters/device', ['X-Headquarters-Key' => 'rotated-again'])
            ->assertTooManyRequests()->assertJsonPath('code', 429);
    }

    public function test_internal_api_limits_unsigned_requests_before_signature_validation(): void
    {
        config(['security.rate_limits.internal_per_minute' => 1]);

        $this->postJson('/api/internal/apishadai/changes')->assertUnauthorized();
        $this->postJson('/api/internal/apishadai/changes')
            ->assertTooManyRequests()->assertJsonPath('code', 429);
    }

    public function test_returns_429_after_the_ip_limit_and_recovers_after_one_minute(): void
    {
        $this->freezeTime();
        config(['security.rate_limits.requests_per_minute' => 2]);

        $this->getJson('/api/security-missing-one')->assertNotFound();
        $this->getJson('/api/security-missing-two')->assertNotFound();
        $this->getJson('/api/security-missing-three')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('code', 429);

        $this->travel(61)->seconds();
        $this->getJson('/api/security-missing-one')->assertNotFound();
    }

    public function test_device_and_forwarded_headers_cannot_bypass_the_ip_limit(): void
    {
        config(['security.rate_limits.requests_per_minute' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson('/api/security-missing')->assertNotFound();

        $this->getJson('/api/security-missing', [
            'X-Forwarded-For' => '192.0.2.20',
            'X-Device-Ip' => '192.0.2.20',
            'X-Device-Name' => 'rotated-device',
        ])->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.30'])
            ->getJson('/api/security-missing')->assertNotFound();
    }

    public function test_removes_technology_headers_and_preserves_the_response_contract(): void
    {
        Route::get('/api/security-probe', fn () => response()->json(['ok' => true], 200, [
            'Server' => 'Apache/test',
            'X-Powered-By' => 'PHP/test',
            'X-Debug-Token' => 'debug-token',
            'X-XSS-Protection' => '1; mode=block',
            'X-Custom-Result' => 'preserved',
        ]));

        $this->getJson('/api/security-probe')
            ->assertOk()
            ->assertExactJson(['ok' => true])
            ->assertHeaderMissing('Server')
            ->assertHeaderMissing('X-Powered-By')
            ->assertHeaderMissing('X-Debug-Token')
            ->assertHeaderMissing('X-XSS-Protection')
            ->assertHeader('X-Custom-Result', 'preserved')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Content-Length')
            ->assertHeaderMissing('Transfer-Encoding')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_errors_also_have_security_headers_and_cannot_be_cached(): void
    {
        $this->getJson('/api/security-missing')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_sends_hsts_over_https_in_production(): void
    {
        $this->app->instance('env', 'production');
        Route::get('/api/security-probe', fn () => response()->json(['ok' => true]));

        $this->getJson('https://localhost/api/security-probe')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_does_not_send_hsts_over_plain_http_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->getJson('http://localhost/api/security-missing')
            ->assertNotFound()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_cors_does_not_allow_unconfigured_browser_origins(): void
    {
        $this->options('/api/security-probe', [], [
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-unused-header',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_cors_only_advertises_the_explicit_origin_methods_and_headers(): void
    {
        config(['cors.allowed_origins' => ['https://admin.example']]);

        $response = $this->options('/api/security-probe', [], [
            'Origin' => 'https://admin.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-unused-header',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->assertStringNotContainsString('*', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringNotContainsString('x-unused-header', strtolower($response->headers->get('Access-Control-Allow-Headers')));
        $this->assertStringNotContainsString('TRACE', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('content-type', strtolower($response->headers->get('Access-Control-Allow-Headers')));
    }
}
