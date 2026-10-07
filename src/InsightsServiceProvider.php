<?php

namespace CarlJanzell\FilamentInsights;

use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class InsightsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-insights');

        // Livewire 3 can't resolve a package component by name on the lazy-load request
        // unless it is registered; Livewire 4 doesn't need it but accepts it.
        Livewire::component(InsightChart::class);
        Livewire::component(InsightStat::class);
    }
}
