<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class HeadquartersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $response = fn (Request $request, array $headers): JsonResponse => response()->json([
            'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.',
            'code' => 429,
            'data' => null,
        ], 429, $headers);

        RateLimiter::for('apishadai-internal', fn (Request $request) => Limit::perMinute(config('security.rate_limits.internal_per_minute'))
            ->by($request->ip())->response($response));
        RateLimiter::for('headquarters-access', fn (Request $request): array => [
            Limit::perMinute(config('security.rate_limits.headquarters_ip_per_minute'))->by('ip:'.$request->ip())->response($response),
            Limit::perMinute(config('security.rate_limits.headquarters_key_per_minute'))
                ->by('key:'.hash('sha256', (string) $request->header('X-Headquarters-Key', '')))->response($response),
        ]);
    }
}
