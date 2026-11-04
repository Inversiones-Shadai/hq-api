<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ([
            'Server', 'X-Powered-By', 'X-AspNet-Version', 'X-AspNetMvc-Version',
            'X-Generator', 'X-Runtime', 'X-Debug-Token', 'X-Debug-Token-Link',
            'X-Pingback', 'X-XSS-Protection', 'Expect-CT', 'Public-Key-Pins',
            'Public-Key-Pins-Report-Only', 'Feature-Policy',
        ] as $header) {
            $response->headers->remove($header);

            if (! headers_sent()) {
                header_remove($header);
            }
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', config('security.headers.frame_options'));
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', config('security.headers.permissions_policy'));
        $response->headers->set('Content-Security-Policy', config('security.headers.content_security_policy'));

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('security.headers.hsts_max_age'));
        }

        if ($request->is('api', 'api/*') || str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
