# Agent guide: filament-insights

A Filament plugin (not an app) for admin-built stat and chart dashboards on any Filament resource. One codebase for Filament 3.3, 4 and 5.

- **Using the plugin in an app?** Read [resources/boost/guidelines/core.blade.php](resources/boost/guidelines/core.blade.php).
  Apps with [Laravel Boost](https://github.com/laravel/boost) get it in their own agent guidelines automatically on `php artisan boost:install` / `boost:update`.
- **Working on the plugin itself?** Read on.

## Commands

```bash
composer install
composer test          # Pest, Testbench, in-memory SQLite (tests/Feature)
composer lint          # Pint, check only
composer format        # Pint, rewrites files
```

CI (`.github/workflows/ci.yaml`) runs Pint, `php -l` on `src/`, and Pest on each Filament line.

## Where things are

| Path | What |
|---|---|
| `src/FilamentInsightsPlugin.php` | Panel plugin: `widgets()`, `canManage()`, `currency()` |
| `src/InsightsServiceProvider.php` | Migrations, views, motion assets, Livewire component registration |
| `src/Pages/InsightsPage.php` | Base dashboard page: Edit dashboard slide-over, widget form, drag-to-reorder |
| `src/Widgets/InsightStat.php`, `InsightChart.php` | Render one saved config each; the page mounts them, panels never do |
| `src/Support/InsightQuery.php` | All numbers and SQL: column rules, date windows, buckets, labels |
| `src/Models/InsightDashboard.php` | One `insight_dashboards` row per page class |
| `database/migrations/` | `insight_dashboards` table |
| `resources/views/page.blade.php` | Page template, shared across Filament 3, 4 and 5 |
| `resources/js/motion.js`, `resources/js/vendor/` | Optional motion; bundled anime.js (MIT) |
| `resources/boost/guidelines/core.blade.php` | Agent-facing usage guide shipped to apps |
| `tests/Fixtures/` | Test panel, Order, Customer, Person and User resources, `OrderInsights` page |

## Rules

- Any change must work on Filament 3.3, 4 and 5. Filament 3 declares some widget and page properties `static`, and 4 and 5 don't. Keep the overrides as methods, as `InsightStat` and `InsightChart` do.
- Column names end up in raw SQL. Every column taken from a config goes through `InsightQuery::column()` and its `is()` rules. Never interpolate a config value into SQL. Keep `#[Locked]` on widget `$config` and `$resource`.
- `ponytail:` comments mark deliberate shortcuts and their ceilings (`grep -rn "ponytail:" src`). Read them before widening the code they sit in.
- Views use Filament components and inline styles. No Tailwind utility classes: host builds never scan vendor.
- When the public API changes (a plugin method, page member, widget config key, event or table column), update `README.md` and `resources/boost/guidelines/core.blade.php` in the same commit.
