<?php

namespace App\Providers;

use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\TriageModel;
use App\Support\ProxyTrust;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The AI used for triage. Tests swap in a fake so they never call a real service.
        $this->app->bind(TriageModel::class, AnthropicTriageModel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // AI category suggestions while a resident types: a short burst limit and a daily cap
        // per resident, so one account cannot run up the AI bill or tie up the server.
        RateLimiter::for('triage', fn (Request $request): array => [
            Limit::perMinute(20)->by('triage-minute:'.$request->user()?->getAuthIdentifier()),
            Limit::perDay((int) config('triage.daily_suggestions_per_resident'))->by('triage-day:'.$request->user()?->getAuthIdentifier()),
        ]);

        // Behind the tunnel, trust its client-IP and https headers (never its host header).
        ProxyTrust::apply(config('security.trusted_proxies', []));

        // Every generated link (password reset, email verification, unsubscribe) uses
        // APP_URL in production, whatever Host the request arrived with.
        if (app()->isProduction() && filled(config('app.url'))) {
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }
    }
}
