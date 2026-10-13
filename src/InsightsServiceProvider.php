<?php

namespace CarlJanzell\FilamentInsights;

use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class InsightsServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'carljanzell/filament-insights';

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-insights');

        // Published by `php artisan filament:assets` and imported by the insights page itself,
        // so no other Filament page downloads them.
        FilamentAsset::register([
            Js::make('anime', __DIR__.'/../resources/js/vendor/anime.esm.min.js')->loadedOnRequest(),
            Js::make('motion', __DIR__.'/../resources/js/motion.js')->loadedOnRequest(),
        ], static::PACKAGE);

        // Livewire 3 can't resolve a package component by name on the lazy-load request
        // unless it is registered; Livewire 4 doesn't need it but accepts it.
        Livewire::component(InsightChart::class);
        Livewire::component(InsightStat::class);
    }
}
