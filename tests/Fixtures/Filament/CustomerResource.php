<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Filament;

use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\Customer;
use Filament\Resources\Resource;

/**
 * Registered only so a chart grouped by `customer_id` can label bars with the customer's title.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $recordTitleAttribute = 'name';

    // No pages, so no sidebar link: Filament 3 would build it from a missing index route.
    protected static bool $shouldRegisterNavigation = false;
}
