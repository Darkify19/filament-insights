# Filament Insights

Admin-built stats and chart dashboards on any Filament resource. No data-source classes:
each dashboard reads from the resource's own list-page table query, so a chart counts exactly
the records its viewer could see in that table (`getEloquentQuery()` and `modifyQueryUsing()`
scopes both apply).

Works on **Filament 3, 4 and 5** from one codebase: 3.3.53+, 4.13.2+ and 5.8.2+, the first
releases of each line without a known security advisory.

## Install

```bash
composer require carljanzell/filament-insights
php artisan migrate          # creates insight_dashboards
php artisan filament:assets  # publishes the motion scripts; most apps run this via filament:upgrade
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

Widgets rise in one after another, stats count up as they load, a picked-up widget lifts over
a soft outlined slot, and a dropped one settles with a small spring. The motion runs on a
bundled [anime.js](https://animejs.com) 4.5 (MIT), imported only on insights pages and never
when the viewer's system asks for reduced motion. If the scripts aren't published, the
dashboard works the same without it.

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

## Tests

```bash
composer install
composer test   # Pest, on an in-memory SQLite panel
composer lint   # Pint, read-only
```

CI runs the suite against every supported Filament line.
