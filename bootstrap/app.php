<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\RequireAuthenticatedUser;
use App\Http\Middleware\RequirePageRole;
use App\Http\Middleware\RequireRole;
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
        // Trusted proxies are applied in AppServiceProvider from config('security.trusted_proxies'),
        // so the setting also works after `php artisan config:cache`.
        $middleware->alias([
            'api.auth' => RequireAuthenticatedUser::class,
            'role' => RequireRole::class,
            'page.role' => RequirePageRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
