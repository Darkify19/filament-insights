# Filament Insights

Admin-built stats and chart dashboards on any Filament resource. No data-source classes:
each dashboard reads from the resource's own list-page table query, so a chart counts exactly
the records its viewer could see in that table (`getEloquentQuery()` and `modifyQueryUsing()`
scopes both apply).

Works on **Filament 3.2+ and 5.x** from one codebase.

## Install

```bash
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
        ->widgets([UsageStatsOverviewWidget::class])
        // Optional: lets admins show sums and averages as money (stats, axes, tooltips).
        ->currency('PHP'),
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

Admins rearrange a dashboard by dragging widgets on the page (press and hold on touch), or by
focusing one and pressing Alt + arrow keys. The order is saved for everyone who views it.

Only real columns are offered. Hidden and encrypted attributes are excluded, and every
column name is checked against the table schema before it reaches SQL.

Keep a page's personal fields out of the picker with `$excludedColumns`, or override
`getExcludedColumns()` when it depends on the viewer. A saved widget that reads an
excluded column is skipped:

```php
class SalnInsights extends InsightsPage
{
    protected static string $resource = SalnResource::class;

    protected static ?string $title = 'SALN insights';

    protected static array $excludedColumns = ['spouse', 'spouse_office'];
}
```

## Limits

- Date grouping supports MySQL/MariaDB, PostgreSQL and SQLite.
- Accessors (computed attributes) can't be grouped or summed: they don't exist in SQL.
- Table filters and tabs are not applied; each widget sets its own period.
