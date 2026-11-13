<?php

namespace App\Providers;

use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\TriageModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
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

        // Behind a hosting proxy or load balancer, trust its forwarded headers so generated
        // links (email verification, password reset) use https and the real host.
        $trustedProxies = config('security.trusted_proxies', []);
        if ($trustedProxies !== []) {
            TrustProxies::at(count($trustedProxies) === 1 ? $trustedProxies[0] : array_values($trustedProxies));
        }
    }
}
