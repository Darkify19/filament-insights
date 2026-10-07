# Filament Insights

Admin-built stats and chart dashboards on any Filament resource. No data-source classes:
each dashboard reads from the resource's own list-page table query, so a chart counts exactly
the records its viewer could see in that table (`getEloquentQuery()` and `modifyQueryUsing()`
scopes both apply).

Works on **Filament 3.2+ and 5.x** from one codebase.

## Install

Not on Packagist yet, so point Composer at the repository first:

```bash
composer config repositories.filament-insights vcs https://github.com/Darkify19/filament-insights
composer require carljanzell/filament-insights
php artisan migrate   # creates insight_dashboards
```

Register the plugin on a panel:

```php
use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;

$panel->plugin(
    FilamentInsightsPlugin::make()
        // Who may edit dashboards. Anyone who can open the resource can view them.
        ->canManage(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
        // Optional: existing hand-written widgets admins may place as-is.
        ->widgets([UsageStatsOverviewWidget::class]),
);
```

Add an insights page to a resource:

```php
class AttendanceInsights extends \CarlJanzell\FilamentInsights\Pages\InsightsPage
{
    protected static string $resource = AttendanceResource::class;
}

// AttendanceResource::getPages()
'insights' => Pages\AttendanceInsights::route('/insights'),

// ListAttendances::getHeaderActions() — a button to get there
AttendanceInsights::linkAction(),
```

## What admins can build

- **Stat**: count / sum / average / min / max, optionally over the last 7–365 days of a date
  field, with change against the previous period and a trend sparkline.
- **Chart**: bar, line, pie or doughnut, grouped by day / month / year of a date field, or
  by a field (top N). Foreign keys show the related record's title, enums their label.
- **Custom widget**: any widget registered with `->widgets()`.

Only real columns are offered. Hidden and encrypted attributes are excluded, and every
column name is checked against the table schema before it reaches SQL.

## Limits

- Date grouping supports MySQL/MariaDB, PostgreSQL and SQLite.
- Accessors (computed attributes) can't be grouped or summed: they don't exist in SQL.
- Table filters and tabs are not applied; each widget sets its own period.
