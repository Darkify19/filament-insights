<?php

namespace CarlJanzell\FilamentInsights\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One dashboard per insights page, keyed by the page class.
 *
 * @property string $key
 * @property list<array<string, mixed>>|null $widgets
 */
class InsightDashboard extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'widgets' => 'array',
        ];
    }
}
