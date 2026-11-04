<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class HttpSecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('http-requests', function (Request $request): array {
            $response = fn (Request $request, array $headers): JsonResponse => response()->json([
                'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.',
                'code' => 429,
                'data' => null,
            ], 429, $headers);

            $limits = [
                Limit::perMinute(config('security.rate_limits.requests_per_minute'))
                    ->by('hq-api:requests:'.$request->ip())->response($response),
            ];

            return $limits;
        });
    }
}
