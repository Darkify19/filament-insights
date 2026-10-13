<?php

namespace CarlJanzell\FilamentInsights\Pages;

use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;
use CarlJanzell\FilamentInsights\Models\InsightDashboard;
use CarlJanzell\FilamentInsights\Support\InsightQuery;
use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;

/**
 * A dashboard page under one resource. Subclass it per resource:
 *
 *     class AttendanceInsights extends InsightsPage
 *     {
 *         protected static string $resource = AttendanceResource::class;
 *     }
 *
 * and register it in the resource's getPages(): 'insights' => AttendanceInsights::route('/insights').
 */
abstract class InsightsPage extends Page
{
    protected static ?string $breadcrumb = 'Insights';

    /**
     * Columns this page never offers, e.g. personal fields that shouldn't be charted.
     *
     * @var list<string>
     */
    protected static array $excludedColumns = [];

    /** @var list<array<string, mixed>>|null */
    protected ?array $cachedWidgets = null;

    /**
     * Override when exclusions depend on the viewer. A saved widget that reads an
     * excluded column is skipped rather than shown.
     *
     * @return list<string>
     */
    public static function getExcludedColumns(): array
    {
        return static::$excludedColumns;
    }

    public function getTitle(): string
    {
        return static::$title ?? Str::ucfirst(static::getResource()::getModelLabel()).' insights';
    }

    /**
     * A header action for the resource's list page that links here.
     */
    public static function linkAction(): Action
    {
        return Action::make('insights')
            ->label('Insights')
            ->icon('heroicon-o-chart-bar')
            ->color('gray')
            ->url(static::getUrl());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dashboardWidgets(): array
    {
        return $this->cachedWidgets ??= InsightDashboard::query()->where('key', static::class)->value('widgets') ?? [];
    }

    /**
     * Shares one blade view across Filament v3–v5 (v3 declares `$view` static, v4 and v5 don't).
     */
    public function getView(): string
    {
        return 'filament-insights::page';
    }

    /**
     * What the view mounts, one entry per widget the viewer may see.
     *
     * @return list<array{class: class-string, props: array<string, mixed>, key: string, span: int}>
     */
    public function getDashboardMounts(): array
    {
        $custom = FilamentInsightsPlugin::get()->getWidgets();
        $excluded = static::getExcludedColumns();
        $keys = static::widgetKeys($this->dashboardWidgets());

        return collect($this->dashboardWidgets())
            ->map(function (array $widget, int $index) use ($custom, $excluded, $keys): ?array {
                if (array_intersect(InsightQuery::columnsIn($widget), $excluded)) {
                    return null;
                }

                $class = match ($widget['type'] ?? null) {
                    'stat' => InsightStat::class,
                    'chart' => InsightChart::class,
                    'widget' => in_array($widget['widget'] ?? null, $custom, true) && $widget['widget']::canView() ? $widget['widget'] : null,
                    default => null,
                };

                return $class === null ? null : [
                    'class' => $class,
                    'props' => [
                        ...$class::getDefaultProperties(),
                        ...($widget['type'] === 'widget' ? [] : ['config' => $widget, 'resource' => static::getResource()]),
                    ],
                    'key' => $keys[$index],
                    'span' => (int) ($widget['span'] ?? 6),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Saves the order a manager dragged on the page. Widgets they can't see keep their slots.
     *
     * @param  array<int, mixed>  $keys  the visible widgets' keys, in their new order
     */
    public function reorderWidgets(array $keys): void
    {
        abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

        $visible = array_column($this->getDashboardMounts(), 'key');

        // Anything other than a reordering of what's on screen means someone edited the dashboard meanwhile.
        if (collect($keys)->sort()->values()->all() !== collect($visible)->sort()->values()->all()) {
            // Sent from the browser: a widget remounting in this same render would claim a session notification.
            $this->js("new FilamentNotification().title('This dashboard was changed elsewhere, so it has been reloaded').warning().send()");

            return;
        }

        $widgets = $this->dashboardWidgets();
        $byKey = array_combine(static::widgetKeys($widgets), $widgets);

        foreach (array_keys($byKey) as $index => $key) {
            if (in_array($key, $visible, true)) {
                $widgets[$index] = $byKey[array_shift($keys)];
            }
        }

        InsightDashboard::query()->updateOrCreate(['key' => static::class], ['widgets' => $widgets]);

        // The browser already shows this order. A morph would re-insert every widget and drop keyboard focus.
        $this->skipRender();
    }

    /**
     * One key per saved widget: its content hash, plus a count for identical copies. Free of position,
     * so a reordered widget keeps its mounted component; tied to content, so an edited one remounts.
     *
     * @param  list<array<string, mixed>>  $widgets
     * @return list<string>
     */
    protected static function widgetKeys(array $widgets): array
    {
        $seen = [];

        return array_map(function (array $widget) use (&$seen): string {
            $hash = md5(json_encode($widget));

            return $hash.'-'.($seen[$hash] = ($seen[$hash] ?? 0) + 1);
        }, $widgets);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editDashboard')
                ->label('Edit dashboard')
                ->icon('heroicon-o-squares-plus')
                ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable())
                ->slideOver()
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Save dashboard')
                ->fillForm(fn (): array => ['widgets' => $this->dashboardWidgets()])
                ->form([
                    Repeater::make('widgets')
                        ->hiddenLabel()
                        ->schema($this->widgetFields())
                        ->columns(2)
                        ->collapsible()
                        ->cloneable()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                        ->addActionLabel('Add widget')
                        ->defaultItems(0),
                ])
                ->action(function (array $data): void {
                    abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                    InsightDashboard::query()->updateOrCreate(
                        ['key' => static::class],
                        ['widgets' => array_values($data['widgets'] ?? [])],
                    );

                    $this->cachedWidgets = null;

                    // Sent from the browser, as in reorderWidgets(): an edited widget remounts in this render and would claim it.
                    $this->js("new FilamentNotification().title('Dashboard saved').success().send()");
                }),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function widgetFields(): array
    {
        $insights = new InsightQuery(static::getResource(), static::getExcludedColumns());
        $numeric = $insights->options('numeric');
        $dates = $insights->options('date');
        $groups = $insights->options('group');
        $timeline = ['@day' => 'Day', '@month' => 'Month', '@year' => 'Year'];
        $custom = collect(FilamentInsightsPlugin::get()->getWidgets())
            ->mapWithKeys(fn (string $widget): array => [$widget => Str::headline(class_basename($widget))])
            ->all();

        $currency = FilamentInsightsPlugin::get()->getCurrency();

        $isData = fn ($get): bool => in_array($get('type'), ['stat', 'chart'], true);
        $isChart = fn ($get): bool => $get('type') === 'chart';
        $isTimeline = fn ($get): bool => str_starts_with((string) $get('group_by'), '@');

        return [
            ToggleButtons::make('type')
                ->options(array_filter(['stat' => 'Stat', 'chart' => 'Chart', 'widget' => $custom ? 'Custom widget' : null]))
                ->icons(['stat' => 'heroicon-m-hashtag', 'chart' => 'heroicon-m-chart-bar', 'widget' => 'heroicon-m-puzzle-piece'])
                ->default('stat')
                ->grouped()
                ->required()
                ->live()
                ->columnSpanFull(),
            TextInput::make('label')
                ->required()
                ->maxLength(80)
                ->live(onBlur: true),
            ToggleButtons::make('span')
                ->label('Width')
                ->options([3 => '¼', 4 => '⅓', 6 => '½', 8 => '⅔', 12 => 'Full'])
                ->default(6)
                ->grouped()
                ->required(),
            Select::make('widget')
                ->options($custom)
                ->required()
                ->visible(fn ($get): bool => $get('type') === 'widget')
                ->columnSpanFull(),
            Select::make('aggregate')
                ->label('Measure')
                ->options(InsightQuery::AGGREGATES)
                ->default('count')
                ->required()
                ->live()
                ->visible($isData),
            Select::make('column')
                ->label('Of field')
                ->options($numeric)
                // Selects don't reject values outside their options; these reach SQL, so say it outright.
                ->in(array_keys($numeric))
                ->required()
                ->visible(fn ($get): bool => $isData($get) && $get('aggregate') !== 'count'),
            ToggleButtons::make('format')
                ->label('Show as')
                ->options(['number' => 'Number', 'money' => "Money ({$currency})"])
                ->default('number')
                ->grouped()
                ->visible(fn ($get): bool => filled($currency) && $isData($get) && $get('aggregate') !== 'count'),
            ToggleButtons::make('chart')
                ->label('Chart type')
                ->options(['bar' => 'Bar', 'line' => 'Line', 'pie' => 'Pie', 'doughnut' => 'Doughnut'])
                ->default('bar')
                ->grouped()
                ->required()
                ->visible($isChart),
            Select::make('group_by')
                ->label('Group by')
                ->options([
                    'Over time' => $timeline,
                    'By field' => $groups,
                ])
                ->in([...array_keys($timeline), ...array_keys($groups)])
                ->required()
                ->live()
                ->visible($isChart),
            TextInput::make('limit')
                ->label('Top')
                ->numeric()
                ->minValue(1)
                ->maxValue(50)
                ->default(10)
                ->visible(fn ($get): bool => $isChart($get) && filled($get('group_by')) && ! $isTimeline($get)),
            Select::make('date_column')
                ->label('Date field')
                ->options($dates)
                ->in(array_keys($dates))
                ->placeholder('None')
                ->required(fn ($get): bool => $isChart($get) && $isTimeline($get))
                ->live()
                ->visible($isData),
            Select::make('range')
                ->label('Period')
                ->options([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 365 days'])
                ->placeholder('All time')
                ->visible(fn ($get): bool => $isData($get) && filled($get('date_column'))),
        ];
    }
}
