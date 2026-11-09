<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading / unknown attributes outside production (N+1 guard).
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('crm-api', fn (Request $request) => Limit::perMinute(config('crm.rate_limit_per_minute'))->by($request->ip()));

        // Numeric ids only: /prospects/abc is a 404, not a database error.
        foreach (['prospect', 'followUp', 'product', 'salesStage', 'lossReason'] as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }
    }
}
