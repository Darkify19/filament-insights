<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Filament;

use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\User;
use Filament\Resources\Resource;

/**
 * Registered with no record title, the same shape as an app user resource
 * that never set `$recordTitleAttribute`. Charts must still label the user by name.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static bool $shouldRegisterNavigation = false;
}
