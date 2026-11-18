<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\RedirectSignedInUser;
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
        // Outside local development, answer only for the APP_URL host (and its subdomains,
        // such as www) plus localhost for checks on the server itself. Requests with any
        // other Host header are refused.
        $middleware->trustHosts(at: ['^localhost$', '^127\.0\.0\.1$'], subdomains: true);
        // Trusted proxies are applied in AppServiceProvider from config('security.trusted_proxies'),
        // so the setting also works after `php artisan config:cache`.
        $middleware->alias([
            'api.auth' => RequireAuthenticatedUser::class,
            'role' => RequireRole::class,
            'page.role' => RequirePageRole::class,
            'guest.page' => RedirectSignedInUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
