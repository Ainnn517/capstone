<?php

namespace App\Providers;

use App\Services\Assistant\AnthropicAssistantModel;
use App\Services\Assistant\AssistantModel;
use App\Services\Assistant\GeminiAssistantModel;
use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\GeminiTriageModel;
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
        // The AI used for triage: Claude or Gemini (both cloud). Tests swap in a fake so they
        // never call a real service.
        $this->app->bind(TriageModel::class, fn ($app): TriageModel => match (config('triage.provider')) {
            'gemini' => $app->make(GeminiTriageModel::class),
            default => $app->make(AnthropicTriageModel::class),
        });
        // "Ask the data" uses the same cloud provider as triage (no local option).
        $this->app->bind(AssistantModel::class, fn ($app): AssistantModel => config('triage.provider') === 'gemini'
            ? $app->make(GeminiAssistantModel::class)
            : $app->make(AnthropicAssistantModel::class));
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

        // "Ask the data": a small share of the AI quota per administrator.
        RateLimiter::for('data-assistant', fn (Request $request): array => [
            Limit::perMinute((int) config('assistant.per_minute_per_admin'))->by('assistant-minute:'.$request->user()?->getAuthIdentifier())
                ->response(fn () => response()->json(['success' => false, 'message' => 'Too many questions in a minute. Please wait a moment and ask again.'], 429)),
            Limit::perDay((int) config('assistant.per_day_per_admin'))->by('assistant-day:'.$request->user()?->getAuthIdentifier())
                ->response(fn () => response()->json(['success' => false, 'message' => 'You have reached today\'s limit for AI questions. It resets tomorrow.'], 429)),
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
