<?php

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    // Register the web routes, console commands, and health check.
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AddSecurityHeaders::class);
        $trustedProxies = array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        ));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }
        $middleware->alias([
            'api.auth' => RequireAuthenticatedUser::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
