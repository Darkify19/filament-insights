<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures;

use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\CustomerResource;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderResource;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrdersOverview;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('testing')
            ->resources([
                OrderResource::class,
                CustomerResource::class,
            ])
            ->plugin(
                FilamentInsightsPlugin::make()
                    ->canManage(fn (): bool => (bool) auth()->user()?->is_admin)
                    ->widgets([OrdersOverview::class])
                    ->currency('PHP'),
            );
    }
}
