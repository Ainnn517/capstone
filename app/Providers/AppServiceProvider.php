<?php

namespace App\Providers;

use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\TriageModel;
use Illuminate\Database\Eloquent\Model;
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
    }
}
