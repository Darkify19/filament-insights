## Filament Insights (carljanzell/filament-insights)

Admin-built stat and chart dashboards on any Filament resource, for Filament 3.3, 4 and 5. A dashboard is a page under one resource. Admins add stats and charts from an **Edit dashboard** slide-over and drag them into order. No data-source classes: every number is a query over the resource's own list-page table query, so a chart counts exactly the records its viewer could see there (`getEloquentQuery()` and the table's `modifyQueryUsing()` both apply).

### Install

@verbatim
<code-snippet name="Install" lang="bash">
composer require carljanzell/filament-insights:^0.5
php artisan migrate
php artisan filament:assets
</code-snippet>
@endverbatim

- `migrate` creates `insight_dashboards` (one row per insights page). The package's migration loads automatically; nothing to publish.
- `filament:assets` publishes the motion scripts. Most apps get this from `filament:upgrade`. Without them the dashboard works the same, just without animation.
- No config file, no service provider to register (auto-discovered). All panel setup is on the plugin object.

### Register on a panel and add a page

@verbatim
<code-snippet name="Plugin on a panel" lang="php">
use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;

$panel->plugin(
    FilamentInsightsPlugin::make()
        ->canManage(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
        ->widgets([UsageStatsOverviewWidget::class])
        ->currency('PHP'),
);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Insights page under a resource" lang="php">
use CarlJanzell\FilamentInsights\Pages\InsightsPage;

class AttendanceInsights extends InsightsPage
{
    protected static string $resource = AttendanceResource::class;
}

// AttendanceResource::getPages()
'insights' => Pages\AttendanceInsights::route('/insights'),

// ListAttendances::getHeaderActions(): a button to get there
AttendanceInsights::linkAction(),
</code-snippet>
@endverbatim

### Plugin methods

Only these three are fluent config. Each returns the plugin, so chain them in `$panel->plugin(...)`.

| Method | Default | Notes |
|---|---|---|
| `widgets(array)` | `[]` | Hand-written widget classes admins may place as-is (type `widget`). Each one still needs its own `canView()` to pass. |
| `canManage(bool\|Closure)` | `true` | Who may edit dashboards: the Edit dashboard action and drag-to-reorder. Viewing is not gated here. The default lets everyone who opens a page edit it, so set this. |
| `currency(?string)` | `null` | ISO 4217 code such as `PHP`. Enables the Money option for sum, average, min and max. Null means no money formatting. |

Other public methods, read-only or static: `make()`, `get()` (the plugin for the current panel, at runtime), `getWidgets()`, `getCurrency()`, `isManageable()` (evaluates `canManage`), `getId()` (`insights`).

### Page members

| Member | Default | Notes |
|---|---|---|
| `protected static string $resource` | required | The resource whose list query the numbers come from. |
| `protected static ?string $title` | `{Model} insights` | Page title. |
| `protected static array $excludedColumns` | `[]` | Columns the page never offers. Widgets that read one are skipped, not deleted. |
| `static getExcludedColumns(): array` | returns `$excludedColumns` | Override when exclusions depend on the viewer. |
| `static linkAction(): Action` | "Insights" button | Header action for the list page, linking to this page. |

### Dashboard config (what gets saved)

Each saved widget is one entry in `insight_dashboards.widgets`. Admins build these in the form; write them by hand only when seeding.

| Key | Values | Notes |
|---|---|---|
| `type` | `stat`, `chart`, `widget` | Anything else is skipped. `widget` takes `widget` (a class from `widgets()`). |
| `label` | string, max 80 | Required. Stat title or chart heading. |
| `span` | `3`, `4`, `6`, `8`, `12` | Width on a 12-column grid. Default `6`. |
| `aggregate` | `count` (default), `sum`, `avg`, `min`, `max` | `count` needs no `column`. |
| `column` | numeric field | Required for every aggregate except `count`. Ids, `*_id` and relations are refused. |
| `format` | `number` (default), `money` | `money` only applies with `currency()` set and a non-count aggregate. |
| `chart` | `bar` (default), `line`, `pie`, `doughnut` | Charts only. |
| `group_by` | `@day`, `@month`, `@year`, or a field | Time buckets need `date_column`. A field gives top N, sized by `limit` (1 to 50, default 10). Dates, text, JSON, binary and decimal fields are not groupable. |
| `date_column` | date or datetime field | Needed for `range` and time buckets. |
| `range` | `7`, `30`, `90`, `365`, or absent | Last N days, against `date_column`. Absent means all time. |

Stats also show change against the previous period and a sparkline when `range` is set.

### Common tasks

- **Add a hand-written widget:** write a normal Filament widget, pass its class to `->widgets([...])`. Admins pick it under Custom widget. Removing it from `widgets()` hides it from dashboards without touching the saved row.
- **Hide personal fields:** `protected static array $excludedColumns = ['spouse'];`, or override `getExcludedColumns()` per viewer.
- **Show money:** set `->currency('PHP')` on the plugin, then `format` `money` on sum, average, min or max.
- **Query the same numbers in code:** `new InsightQuery(AttendanceResource::class, ['spouse'])` from `CarlJanzell\FilamentInsights\Support\InsightQuery`, then `->value($config)` for one number or `->series($config)` for `[$labels, $values]`. Configs use the same keys as above. `->options()` takes `numeric`, `date` or `group` and lists the columns usable for that role.
- **Link to a dashboard:** `AttendanceInsights::getUrl()`.

@verbatim
<code-snippet name="Numbers outside the page" lang="php">
use CarlJanzell\FilamentInsights\Support\InsightQuery;

$insights = new InsightQuery(OrderResource::class);

$revenue = $insights->value([
    'aggregate' => 'sum', 'column' => 'total', 'date_column' => 'ordered_at', 'range' => 30,
]);

[$labels, $values] = $insights->series([
    'aggregate' => 'count', 'date_column' => 'ordered_at', 'group_by' => '@day', 'range' => 7,
]);
</code-snippet>
@endverbatim

### Data

- `insight_dashboards`: `key` (unique, the page's class name) and `widgets` (JSON list). One dashboard per page, shared by everyone who can open it. Model: `CarlJanzell\FilamentInsights\Models\InsightDashboard`.
- Renaming a page class orphans its saved dashboard, because the key is the class name.
- Columns are checked against the real table. Hidden, encrypted and excluded columns are never offered. Accessors don't exist in SQL, so they can't be grouped or summed.

### Limits

- Date grouping supports MySQL/MariaDB, PostgreSQL and SQLite.
- Table filters and tabs don't narrow the numbers. Each widget sets its own period.
- Money needs `currency()`. The form offers it only for sum, average, min and max.

### Don't

- Don't build a custom query, data-source class or chart page for an admin dashboard. Add an `InsightsPage` subclass.
- Don't use an accessor as a stat column or a group key. It has no SQL column.
- Don't assume table filters narrow the charts. They don't.
- Don't leave `canManage` at its default if only some admins should edit. Everyone who opens the page can edit it by default.
- Don't rename a page class after saving its dashboard. The saved row is keyed by class name.
- Don't add `InsightStat` or `InsightChart` to a panel or a page. They render saved configs; use `widgets()` for your own widgets.
- Don't write `format` `money` without `currency()`. It silently shows plain numbers.
- Don't style the dashboard with the app's Tailwind classes. Views use Filament components and inline styles.
