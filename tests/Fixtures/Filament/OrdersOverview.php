<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Filament;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A hand-written widget registered with the plugin's `->widgets()`.
 */
class OrdersOverview extends StatsOverviewWidget
{
    public static bool $shown = true;

    public static function canView(): bool
    {
        return static::$shown;
    }

    protected function getStats(): array
    {
        return [Stat::make('Orders overview', 3)];
    }
}
