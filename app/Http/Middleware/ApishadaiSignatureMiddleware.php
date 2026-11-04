<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApishadaiSignatureMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('headquarters.apishadai_secret');
        $timestamp = (string) $request->header('X-Apishadai-Timestamp', '');
        $nonce = (string) $request->header('X-Apishadai-Nonce', '');
        $signature = (string) $request->header('X-Apishadai-Signature', '');
        if ((app()->isProduction() && ! $request->isSecure()) || strlen($secret) < 64 || $request->header('X-Apishadai-Key-Id') !== config('headquarters.apishadai_key_id')
            || ! in_array($request->ip(), config('headquarters.apishadai_ips'), true)
            || ! ctype_digit($timestamp) || (int) $timestamp < now()->timestamp - 300 || (int) $timestamp > now()->timestamp + 60
            || ! Str::isUuid($nonce) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || strlen($request->getContent()) > 8 * 1024 * 1024) {
            return $this->unauthorized();
        }
        $canonical = $request->method()."\n".$request->getRequestUri()."\n".$timestamp."\n".$nonce."\n".hash('sha256', $request->getContent());
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $this->unauthorized();
        }
        try {
            DB::table('apishadai_request_nonces')->insert(['nonce' => $nonce, 'expires_at' => now()->addMinutes(10)]);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                return $this->unauthorized();
            }
            throw $exception;
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Solicitud de apishadai no autorizada.', 'code' => 401, 'data' => null], 401);
    }
}
