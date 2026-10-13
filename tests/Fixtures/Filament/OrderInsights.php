<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Filament;

use CarlJanzell\FilamentInsights\Pages\InsightsPage;

class OrderInsights extends InsightsPage
{
    protected static string $resource = OrderResource::class;

    protected static array $excludedColumns = ['channel'];
}
