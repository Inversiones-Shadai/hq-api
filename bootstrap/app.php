<?php

use App\Exceptions\HeadquartersException;
use App\Http\Middleware\NoStoreMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(ThrottleRequests::using('http-requests'));

        $middleware->api(append: [NoStoreMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (HeadquartersException $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => $exception->getCode(), 'data' => null,
        ], $exception->getCode()));
    })->create();
