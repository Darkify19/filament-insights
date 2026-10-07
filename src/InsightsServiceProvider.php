<?php

namespace CarlJanzell\FilamentInsights;

use Illuminate\Support\ServiceProvider;

class InsightsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-insights');
    }
}
