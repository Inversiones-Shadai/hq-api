<?php

namespace App\Http\Middleware;

use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HeadquartersSecurityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Headquarters-Key', '');
        $clientId = (string) $request->header('X-Headquarters-Client-Id', '');
        $name = (string) $request->header('X-Headquarters-Name', '');
        if ((app()->isProduction() && ! $request->isSecure()) || ! preg_match('/\A[a-f0-9]{64}\z/', $key)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $clientId)
            || $name === '' || strlen($name) > 400) {
            return $this->unauthorized();
        }

        $match = HeadquartersMatch::with('device.consumer')->where('key_hash', hash('sha256', $key))
            ->where('status', HeadquartersMatch::STATUS_ACTIVE)->first();
        $device = $match?->device;
        $consumer = $device?->consumer;
        $link = $device ? DB::table('source_device_links')->where('device_id', $device->source_device_id)->first() : null;
        if (! $match || ! $device || ! $consumer || ! $link || ! $link->active
            || (int) $link->stock_id !== (int) $device->stock_id
            || ! hash_equals($match->key_hash, hash('sha256', $key))
            || ! hash_equals($device->client_id, $clientId)
            || ! hash_equals($device->name, $name)
            || $device->ip !== $request->ip()
            || $device->status !== HeadquartersDevice::STATUS_ACTIVE
            || $consumer->status !== SyncConsumer::STATUS_ACTIVE) {
            return $this->unauthorized();
        }

        $now = now();
        $match->update(['last_used_at' => $now]);
        $device->update(['last_seen_at' => $now]);
        $consumer->update(['last_seen_at' => $now]);
        $request->attributes->set('headquarters_device', $device);
        $request->attributes->set('headquarters_consumer', $consumer);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Credenciales de Headquarters incorrectas o dispositivo inactivo.', 'code' => 401, 'data' => null], 401);
    }
}
