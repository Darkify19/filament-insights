<?php

namespace CarlJanzell\FilamentInsights\Pages;

use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;
use CarlJanzell\FilamentInsights\Models\InsightDashboard;
use CarlJanzell\FilamentInsights\Support\InsightQuery;
use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use CarlJanzell\FilamentInsights\Widgets\InsightTable;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\Page;
use Illuminate\Support\HtmlString;
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
    public const SPANS = [
        3 => '¼',
        4 => '⅓',
        6 => '½',
        8 => '⅔',
        12 => 'Full',
    ];

    protected static ?string $breadcrumb = 'Insights';

    /**
     * Columns this page never offers, e.g. personal fields that shouldn't be charted.
     *
     * @var list<string>
     */
    protected static array $excludedColumns = [];

    /** @var list<array<string, mixed>>|null */
    protected ?array $cachedWidgets = null;

    /** When true, managers see the dashboard exactly as viewers do (no toolbars or drag handles). */
    public bool $previewing = false;

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
     * What the view mounts, one entry per widget or section the viewer may see.
     *
     * @return list<array<string, mixed>>
     */
    public function getDashboardMounts(): array
    {
        $plugin = FilamentInsightsPlugin::get();
        $custom = $plugin->getWidgets();
        $manageable = $plugin->isManageable();
        $excluded = static::getExcludedColumns();
        $keys = static::widgetKeys($this->dashboardWidgets());

        $mounts = collect($this->dashboardWidgets())
            ->map(function (array $widget, int $index) use ($custom, $excluded, $keys): ?array {
                $type = $widget['type'] ?? null;

                $color = is_string($widget['color'] ?? null) && array_key_exists($widget['color'], InsightStat::COLORS)
                    ? $widget['color']
                    : null;

                if ($type === 'section') {
                    $icon = is_string($widget['icon'] ?? null) && array_key_exists($widget['icon'], InsightStat::ICONS)
                        ? $widget['icon']
                        : null;

                    return [
                        'type' => 'section',
                        'class' => null,
                        'props' => [],
                        'key' => $keys[$index],
                        'span' => 12,
                        'label' => (string) ($widget['label'] ?? ''),
                        'description' => trim((string) ($widget['description'] ?? '')),
                        'icon' => $icon,
                        'color' => $color,
                        'collapsible' => (bool) ($widget['collapsible'] ?? false),
                        'collapsed' => (bool) ($widget['collapsible'] ?? false) && (bool) ($widget['collapsed'] ?? false),
                    ];
                }

                if (array_intersect(InsightQuery::columnsIn($widget), $excluded)) {
                    return null;
                }

                $class = match ($type) {
                    'stat' => InsightStat::class,
                    'chart' => InsightChart::class,
                    'table' => InsightTable::class,
                    'widget' => in_array($widget['widget'] ?? null, $custom, true) && $widget['widget']::canView() ? $widget['widget'] : null,
                    default => null,
                };

                return $class === null ? null : [
                    'type' => (string) $type,
                    'class' => $class,
                    'props' => [
                        ...$class::getDefaultProperties(),
                        ...($type === 'widget' ? [] : ['config' => $widget, 'resource' => static::getResource()]),
                    ],
                    'key' => $keys[$index],
                    'span' => (int) ($widget['span'] ?? 6),
                    'label' => (string) ($widget['label'] ?? ''),
                    'color' => $color,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($manageable) {
            return $mounts;
        }

        // Hide empty sections from non-manager viewers (e.g., when all child widgets are excluded/hidden).
        $filtered = [];
        $count = count($mounts);

        foreach ($mounts as $i => $mount) {
            if ($mount['type'] === 'section') {
                $next = $mounts[$i + 1] ?? null;

                if ($next === null || $next['type'] === 'section') {
                    continue;
                }
            }

            $filtered[] = $mount;
        }

        return $filtered;
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
     * Duplicates a single widget or section right after itself on the dashboard.
     */
    public function duplicateWidget(string $key): void
    {
        abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

        $widgets = $this->dashboardWidgets();
        $index = $this->findWidgetIndex($widgets, $key);

        if ($index === null) {
            $this->js("new FilamentNotification().title('This dashboard was changed elsewhere, so it has been reloaded').warning().send()");

            return;
        }

        $copy = $widgets[$index];
        $copy['label'] = Str::limit(trim((string) ($copy['label'] ?? 'Widget')), 73, '').' (copy)';

        array_splice($widgets, $index + 1, 0, [$copy]);

        $toast = ($copy['type'] ?? null) === 'section' ? 'Section duplicated' : 'Widget duplicated';

        $this->persistWidgets($widgets, $toast);
    }

    /**
     * Changes a single widget's grid width directly from its card toolbar.
     */
    public function resizeWidget(string $key, int $span): void
    {
        abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

        if (! array_key_exists($span, self::SPANS)) {
            return;
        }

        $widgets = $this->dashboardWidgets();
        $index = $this->findWidgetIndex($widgets, $key);

        if ($index === null) {
            $this->js("new FilamentNotification().title('This dashboard was changed elsewhere, so it has been reloaded').warning().send()");

            return;
        }

        if (($widgets[$index]['type'] ?? null) === 'section') {
            return;
        }

        $widgets[$index]['span'] = $span;

        $this->persistWidgets($widgets, 'Widget width updated');
    }

    /**
     * Edit one widget or section directly from its toolbar.
     */
    public function editWidgetAction(): Action
    {
        return Action::make('editWidget')
            ->label('Edit widget')
            ->modalHeading(function (array $arguments): string {
                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));

                return ($index !== null && ($widgets[$index]['type'] ?? null) === 'section')
                    ? 'Edit section'
                    : 'Edit widget';
            })
            ->modalSubmitActionLabel('Save changes')
            ->slideOver()
            ->modalWidth('2xl')
            ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable())
            ->fillForm(function (array $arguments): array {
                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));

                if ($index === null) {
                    return [];
                }

                $state = $this->hydrateWidget($widgets[$index]);

                if (($widgets[$index]['type'] ?? null) !== 'section') {
                    $state['section_key'] = $this->sectionKeyForWidget($widgets, $index);
                }

                return $state;
            })
            ->form(fn (): array => [
                ...$this->sectionPlacementFields(forEdit: true),
                ...$this->widgetFields(includeSection: true),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));

                if ($index === null) {
                    $this->js("new FilamentNotification().title('This dashboard was changed elsewhere, so it has been reloaded').warning().send()");

                    return;
                }

                $oldSectionKey = ($widgets[$index]['type'] ?? null) === 'section'
                    ? null
                    : $this->sectionKeyForWidget($widgets, $index);
                $updated = $this->normalizeWidget($data);
                $hasSectionPicker = array_key_exists('section_key', $data) && ($updated['type'] ?? null) !== 'section';
                $newSectionKey = filled($data['section_key'] ?? null) ? (string) $data['section_key'] : null;

                if ($hasSectionPicker && $newSectionKey !== $oldSectionKey) {
                    array_splice($widgets, $index, 1);

                    if ($newSectionKey === null) {
                        $insertAt = $this->ungroupedInsertIndex($widgets);
                        array_splice($widgets, $insertAt, 0, [$updated]);
                    } elseif (($insertAt = $this->sectionInsertIndex($widgets, $newSectionKey)) !== null) {
                        array_splice($widgets, $insertAt, 0, [$updated]);
                    } else {
                        $widgets[] = $updated;
                    }
                } else {
                    $widgets[$index] = $updated;
                }

                $toast = ($updated['type'] ?? null) === 'section' ? 'Section updated' : 'Widget updated';

                $this->persistWidgets($widgets, $toast);
            });
    }

    /**
     * Remove one widget or section directly from its toolbar, with confirmation.
     */
    public function deleteWidgetAction(): Action
    {
        return Action::make('deleteWidget')
            ->label('Remove widget')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(function (array $arguments): string {
                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));

                return ($index !== null && ($widgets[$index]['type'] ?? null) === 'section')
                    ? 'Remove section'
                    : 'Remove widget';
            })
            ->modalDescription(function (array $arguments): string {
                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));
                $widget = $index !== null ? $widgets[$index] : null;
                $label = $widget['label'] ?? 'this widget';

                if (($widget['type'] ?? null) === 'section') {
                    return "Remove the \"{$label}\" section heading? Widgets underneath it will stay on the dashboard.";
                }

                return "Remove \"{$label}\" from this dashboard? You can add it again anytime.";
            })
            ->modalSubmitActionLabel('Remove')
            ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable())
            ->action(function (array $arguments): void {
                abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                $widgets = $this->dashboardWidgets();
                $index = $this->findWidgetIndex($widgets, (string) ($arguments['key'] ?? ''));

                if ($index === null) {
                    $this->js("new FilamentNotification().title('This dashboard was changed elsewhere, so it has been reloaded').warning().send()");

                    return;
                }

                $wasSection = ($widgets[$index]['type'] ?? null) === 'section';
                array_splice($widgets, $index, 1);

                $this->persistWidgets($widgets, $wasSection ? 'Section removed' : 'Widget removed');
            });
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
            Action::make('togglePreview')
                ->label(fn (): string => $this->previewing ? 'Exit preview' : 'Preview')
                ->icon(fn (): string => $this->previewing ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color(fn (): string => $this->previewing ? 'warning' : 'gray')
                ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable() && filled($this->dashboardWidgets()))
                ->action(function (): void {
                    abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                    $this->previewing = ! $this->previewing;
                }),

            Action::make('addSection')
                ->label('Add section')
                ->icon('heroicon-o-rectangle-group')
                ->color('gray')
                ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable() && ! $this->previewing)
                ->slideOver()
                ->modalHeading('Add a dashboard section')
                ->modalDescription('Group related widgets under a clear title and short description.')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Add section')
                ->form(fn (): array => $this->sectionOnlyFields())
                ->action(function (array $data): void {
                    abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                    $placement = (string) ($data['placement'] ?? 'bottom');
                    $section = $this->normalizeWidget(['type' => 'section', ...$data]);
                    $widgets = $this->dashboardWidgets();

                    if ($placement === 'top') {
                        array_unshift($widgets, $section);
                    } else {
                        $widgets[] = $section;
                    }

                    $this->persistWidgets($widgets, 'Section added');
                }),

            Action::make('addWidget')
                ->label('Add widget')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable() && ! $this->previewing)
                ->slideOver()
                ->modalHeading('Add a dashboard widget')
                ->modalDescription('Pick a quick-start style or customize every option below.')
                ->modalWidth('2xl')
                ->modalSubmitActionLabel('Add to dashboard')
                ->fillForm(function (array $arguments): array {
                    $sectionKey = $arguments['section_key'] ?? null;

                    return filled($sectionKey) ? ['section_key' => (string) $sectionKey] : [];
                })
                ->form(fn (): array => [
                    ...$this->quickStartFields(),
                    ...$this->sectionPlacementFields(),
                    ...$this->widgetFields(),
                ])
                ->action(function (array $data, array $arguments): void {
                    abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                    $targetSection = $data['section_key'] ?? ($arguments['section_key'] ?? null);
                    $widget = $this->normalizeWidget($data);
                    $widgets = $this->dashboardWidgets();

                    if (filled($targetSection) && ($insertAt = $this->sectionInsertIndex($widgets, (string) $targetSection)) !== null) {
                        array_splice($widgets, $insertAt, 0, [$widget]);
                    } else {
                        $widgets[] = $widget;
                    }

                    $this->persistWidgets($widgets, 'Widget added');
                }),

            Action::make('editDashboard')
                ->label('Manage all')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray')
                ->visible(fn (): bool => FilamentInsightsPlugin::get()->isManageable() && ! $this->previewing)
                ->slideOver()
                ->modalHeading('Manage dashboard widgets')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Save dashboard')
                ->fillForm(fn (): array => [
                    'widgets' => array_map(fn (array $widget): array => $this->hydrateWidget($widget), $this->dashboardWidgets()),
                ])
                ->form([
                    Repeater::make('widgets')
                        ->hiddenLabel()
                        ->schema($this->widgetFields(includeSection: true))
                        ->columns(['default' => 1, 'sm' => 2])
                        ->collapsible()
                        ->collapsed()
                        ->cloneable()
                        ->itemLabel(fn (array $state): string => $this->repeaterItemLabel($state))
                        ->addActionLabel('Add item')
                        ->defaultItems(0),
                ])
                ->action(function (array $data): void {
                    abort_unless(FilamentInsightsPlugin::get()->isManageable(), 403);

                    $widgets = array_map(
                        fn (array $widget): array => $this->normalizeWidget($widget),
                        array_values($data['widgets'] ?? []),
                    );

                    $this->persistWidgets($widgets, 'Dashboard saved');
                }),
        ];
    }

    /**
     * Form fields for the dedicated "Add section" slide-over.
     *
     * @return array<int, mixed>
     */
    protected function sectionOnlyFields(): array
    {
        $hasWidgets = filled($this->dashboardWidgets());

        return [
            TextInput::make('label')
                ->label('Section title')
                ->placeholder('e.g., Overview, Application Breakdown, Errors & Alerts')
                ->required()
                ->maxLength(80),

            TextInput::make('description')
                ->label('Short description (optional)')
                ->placeholder('Explain what the widgets in this section show')
                ->maxLength(200),

            Select::make('icon')
                ->label('Icon (optional)')
                ->options(InsightStat::ICONS)
                ->in(array_keys(InsightStat::ICONS))
                ->native(false)
                ->searchable()
                ->placeholder('No icon'),

            ToggleButtons::make('color')
                ->label('Accent color')
                ->helperText('Optional subtle accent for the section icon and divider.')
                ->options(InsightStat::COLORS)
                ->colors([
                    'primary' => 'primary',
                    'info' => 'info',
                    'success' => 'success',
                    'warning' => 'warning',
                    'danger' => 'danger',
                    'gray' => 'gray',
                ])
                ->inline(),

            Toggle::make('collapsible')
                ->label('Allow viewers to collapse this section')
                ->default(false)
                ->live(),

            Toggle::make('collapsed')
                ->label('Start collapsed by default')
                ->default(false)
                ->visible(fn ($get): bool => (bool) $get('collapsible')),

            ToggleButtons::make('placement')
                ->label('Place section at')
                ->helperText('Bottom starts an empty section you can drag widgets into; Top wraps existing widgets.')
                ->options([
                    'top' => 'Top of dashboard',
                    'bottom' => 'Bottom of dashboard',
                ])
                ->default('bottom')
                ->grouped()
                ->visible($hasWidgets),
        ];
    }

    /**
     * Optional "Section" picker shown inside "Add widget" and "Edit widget" when sections exist.
     *
     * @return array<int, mixed>
     */
    protected function sectionPlacementFields(bool $forEdit = false): array
    {
        $sections = $this->sectionOptions();

        if ($sections === []) {
            return [];
        }

        return [
            Select::make('section_key')
                ->label($forEdit ? 'Section' : 'Place in section')
                ->options($sections)
                ->native(false)
                ->placeholder($forEdit ? 'None (outside sections)' : 'End of dashboard')
                ->helperText($forEdit
                    ? 'Move this widget into a section, or leave empty to place it outside sections.'
                    : 'Drops the new widget directly inside the chosen section.')
                ->visible(fn ($get): bool => $get('type') !== 'section')
                ->columnSpanFull(),
        ];
    }

    /**
     * Quick-start template pills shown at the top of the "Add widget" slide-over.
     *
     * @return array<int, mixed>
     */
    protected function quickStartFields(): array
    {
        $insights = new InsightQuery(static::getResource(), static::getExcludedColumns());
        $dates = $insights->options('date');
        $groups = $insights->options('group');
        $firstDate = array_key_first($dates);
        $firstGroup = array_key_first($groups);
        $modelPlural = Str::ucfirst(static::getResource()::getPluralModelLabel());

        return [
            ToggleButtons::make('preset')
                ->label('Quick start')
                ->helperText('Pick a starting template to pre-fill sensible defaults, then adjust anything below.')
                ->options(array_filter([
                    'kpi' => 'KPI Counter',
                    'trend' => $firstDate ? 'Trend over time' : null,
                    'breakdown' => $firstGroup ? 'Top breakdown' : null,
                    'share' => $firstGroup ? 'Proportional split' : null,
                    'table' => $firstGroup ? 'Summary table' : null,
                ]))
                ->icons([
                    'kpi' => 'heroicon-m-hashtag',
                    'trend' => 'heroicon-m-presentation-chart-line',
                    'breakdown' => 'heroicon-m-chart-bar',
                    'share' => 'heroicon-m-chart-pie',
                    'table' => 'heroicon-m-table-cells',
                ])
                ->inline()
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (?string $state, $get, $set) use ($firstDate, $firstGroup, $groups, $modelPlural): void {
                    if ($state === null) {
                        return;
                    }

                    $hasLabel = filled($get('label'));

                    match ($state) {
                        'kpi' => (function () use ($set, $firstDate, $modelPlural, $hasLabel): void {
                            $set('type', 'stat');
                            $set('span', 3);
                            $set('aggregate', 'count');
                            $set('date_column', $firstDate);
                            $set('range', $firstDate ? '30' : null);
                            $set('icon', 'heroicon-m-chart-bar');
                            if (! $hasLabel) {
                                $set('label', "Total {$modelPlural}");
                            }
                        })(),
                        'trend' => (function () use ($set, $firstDate, $modelPlural, $hasLabel): void {
                            $set('type', 'chart');
                            $set('chart', 'line');
                            $set('span', 8);
                            $set('aggregate', 'count');
                            $set('group_by', '@day');
                            $set('date_column', $firstDate);
                            $set('range', '30');
                            if (! $hasLabel) {
                                $set('label', "{$modelPlural} per Day");
                            }
                        })(),
                        'breakdown' => (function () use ($set, $firstGroup, $groups, $modelPlural, $hasLabel): void {
                            $set('type', 'chart');
                            $set('chart', 'bar');
                            $set('span', 6);
                            $set('aggregate', 'count');
                            $set('group_by', $firstGroup);
                            $set('limit', 10);
                            if (! $hasLabel && $firstGroup !== null) {
                                $set('label', "{$modelPlural} by {$groups[$firstGroup]}");
                            }
                        })(),
                        'share' => (function () use ($set, $firstGroup, $groups, $modelPlural, $hasLabel): void {
                            $set('type', 'chart');
                            $set('chart', 'doughnut');
                            $set('span', 6);
                            $set('aggregate', 'count');
                            $set('group_by', $firstGroup);
                            $set('limit', 8);
                            if (! $hasLabel && $firstGroup !== null) {
                                $set('label', "{$modelPlural} by {$groups[$firstGroup]}");
                            }
                        })(),
                        'table' => (function () use ($set, $firstGroup, $groups, $modelPlural, $hasLabel): void {
                            $set('type', 'table');
                            $set('span', 6);
                            $set('aggregate', 'count');
                            $set('group_by', $firstGroup);
                            $set('limit', 10);
                            if (! $hasLabel && $firstGroup !== null) {
                                $set('label', "{$modelPlural} by {$groups[$firstGroup]}");
                            }
                        })(),
                        default => null,
                    };
                })
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function widgetFields(bool $includeSection = false): array
    {
        $insights = new InsightQuery(static::getResource(), static::getExcludedColumns());
        $numeric = $insights->options('numeric');
        $dates = $insights->options('date');
        $groups = $insights->options('group');
        $filterable = $insights->options('filter');
        $timeline = ['@hour' => 'Hour', '@day' => 'Day', '@month' => 'Month', '@year' => 'Year'];
        $custom = collect(FilamentInsightsPlugin::get()->getWidgets())
            ->mapWithKeys(fn (string $widget): array => [$widget => Str::headline(class_basename($widget))])
            ->all();

        $currency = FilamentInsightsPlugin::get()->getCurrency();

        $isData = fn ($get): bool => in_array($get('type'), ['stat', 'chart', 'table'], true);
        $isStat = fn ($get): bool => $get('type') === 'stat';
        $isChart = fn ($get): bool => $get('type') === 'chart';
        $isGrouped = fn ($get): bool => in_array($get('type'), ['chart', 'table'], true);
        $isSection = fn ($get): bool => $get('type') === 'section';
        $isTimeline = fn ($get): bool => str_starts_with((string) $get('group_by'), '@');
        $isRoundChart = fn ($get): bool => $isChart($get) && in_array($get('chart'), ['pie', 'doughnut'], true);
        $needsColumn = fn ($get): bool => $isData($get) && $get('aggregate') !== 'count';
        $isNumericAggregate = fn ($get): bool => $isData($get) && in_array($get('aggregate'), ['sum', 'avg', 'min', 'max'], true);

        return [
            Placeholder::make('preview')
                ->hiddenLabel()
                ->content(fn ($get): HtmlString => $this->renderPreview($get, $insights, $timeline, $custom))
                ->columnSpanFull(),

            ToggleButtons::make('type')
                ->label('Widget type')
                ->options(array_filter([
                    'stat' => 'KPI Stat',
                    'chart' => 'Chart',
                    'table' => 'Table',
                    'section' => $includeSection ? 'Section' : null,
                    'widget' => $custom ? 'Custom widget' : null,
                ]))
                ->icons([
                    'stat' => 'heroicon-m-hashtag',
                    'chart' => 'heroicon-m-chart-bar',
                    'table' => 'heroicon-m-table-cells',
                    'section' => 'heroicon-m-rectangle-group',
                    'widget' => 'heroicon-m-puzzle-piece',
                ])
                ->default('stat')
                ->grouped()
                ->required()
                ->live()
                ->columnSpanFull(),

            TextInput::make('label')
                ->label(fn ($get): string => $isSection($get) ? 'Section title' : 'Title')
                ->placeholder(fn ($get): string => $isSection($get) ? 'e.g., Overview' : 'e.g., Errors this week')
                ->helperText(fn ($get): string => $isSection($get) ? 'Heading shown above this group of widgets.' : 'Shown at the top of the card.')
                ->required()
                ->maxLength(80)
                ->live(onBlur: true)
                ->columnSpan(fn ($get): int|string => $isSection($get) ? 'full' : 1),

            ToggleButtons::make('span')
                ->label('Card width')
                ->options(self::SPANS)
                ->default(6)
                ->grouped()
                ->required()
                ->live()
                ->visible(fn ($get): bool => ! $isSection($get)),

            TextInput::make('description')
                ->label(fn ($get): string => $isSection($get) ? 'Short description (optional)' : 'Subtitle / note (optional)')
                ->placeholder(fn ($get): string => $isSection($get)
                    ? 'Explain what the widgets in this section cover'
                    : 'Short explanation for viewers, e.g., Production records only')
                ->maxLength(200)
                ->visible(fn ($get): bool => $isData($get) || $isSection($get))
                ->columnSpanFull(),

            Toggle::make('collapsible')
                ->label('Allow viewers to collapse this section')
                ->default(false)
                ->live()
                ->visible($isSection),

            Toggle::make('collapsed')
                ->label('Start collapsed by default')
                ->default(false)
                ->visible(fn ($get): bool => $isSection($get) && (bool) $get('collapsible')),

            Select::make('widget')
                ->label('Custom widget')
                ->options($custom)
                ->native(false)
                ->searchable()
                ->required()
                ->live()
                ->visible(fn ($get): bool => $get('type') === 'widget')
                ->columnSpanFull(),

            Select::make('aggregate')
                ->label('What to measure')
                ->options(InsightQuery::AGGREGATES)
                ->default('count')
                ->native(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, $get, $set) use ($numeric): void {
                    if ($state === 'count') {
                        $set('column', null);
                    } elseif (in_array($state, ['sum', 'avg', 'min', 'max'], true) && ! array_key_exists((string) $get('column'), $numeric)) {
                        $set('column', null);
                    }
                })
                ->visible($isData),

            Select::make('column')
                ->label('Of field')
                ->options(fn ($get): array => $get('aggregate') === 'count_distinct' ? $filterable : $numeric)
                // Selects don't reject values outside their options; these reach SQL, so say it outright.
                ->in(fn ($get): array => array_keys($get('aggregate') === 'count_distinct' ? $filterable : $numeric))
                ->native(false)
                ->searchable()
                ->placeholder('Pick a field...')
                ->helperText(fn ($get): string => $get('aggregate') === 'count_distinct'
                    ? 'Counts how many distinct values appear in this field.'
                    : 'Numeric field to calculate.')
                ->required()
                ->live()
                ->visible($needsColumn),

            ToggleButtons::make('format')
                ->label('Number format')
                ->options(['number' => 'Number', 'money' => "Money ({$currency})"])
                ->default('number')
                ->grouped()
                ->live()
                ->visible(fn ($get): bool => filled($currency) && $isNumericAggregate($get)),

            ToggleButtons::make('chart')
                ->label('Chart style')
                ->options(['bar' => 'Bar', 'line' => 'Line', 'pie' => 'Pie', 'doughnut' => 'Doughnut'])
                ->icons([
                    'bar' => 'heroicon-m-chart-bar',
                    'line' => 'heroicon-m-presentation-chart-line',
                    'pie' => 'heroicon-m-chart-pie',
                    'doughnut' => 'heroicon-m-circle-stack',
                ])
                ->default('bar')
                ->grouped()
                ->required()
                ->live()
                ->visible($isChart)
                ->columnSpanFull(),

            Select::make('group_by')
                ->label('Group by')
                ->options([
                    'Over time' => $timeline,
                    'By category' => $groups,
                ])
                ->in([...array_keys($timeline), ...array_keys($groups)])
                ->native(false)
                ->searchable()
                ->placeholder('Pick a time unit or field...')
                ->helperText('Choose a time unit for trends, or a field for a breakdown.')
                ->required()
                ->live()
                ->visible($isGrouped),

            TextInput::make('limit')
                ->label('Show top')
                ->placeholder('10')
                ->helperText('How many top groups to display (1–50).')
                ->numeric()
                ->minValue(1)
                ->maxValue(50)
                ->default(10)
                ->live(onBlur: true)
                ->visible(fn ($get): bool => $isGrouped($get) && filled($get('group_by')) && ! $isTimeline($get)),

            Toggle::make('show_total')
                ->label('Show total row')
                ->helperText('Displays a summary Total row at the bottom of the table.')
                ->default(true)
                ->visible(fn ($get): bool => $get('type') === 'table' && in_array($get('aggregate'), ['count', 'sum', null, ''], true)),

            Select::make('date_column')
                ->label('Date field')
                ->options($dates)
                ->in(array_keys($dates))
                ->native(false)
                ->searchable()
                ->placeholder('None (all time)')
                ->helperText('Pick a date field to enable time ranges or trends.')
                ->required(fn ($get): bool => $isGrouped($get) && $isTimeline($get))
                ->live()
                ->visible($isData),

            Select::make('range')
                ->label('Time period')
                ->options(InsightQuery::RANGES)
                ->in(array_keys(InsightQuery::RANGES))
                ->native(false)
                ->placeholder('All time')
                ->helperText(fn ($get): string => $isStat($get)
                    ? 'Adds a % comparison against the previous period and a sparkline.'
                    : 'Limits the widget to this window.')
                ->live()
                ->visible(fn ($get): bool => $isData($get) && filled($get('date_column'))),

            ToggleButtons::make('color')
                ->label('Accent color')
                ->helperText('Leave unselected for automatic coloring.')
                ->options(InsightStat::COLORS)
                ->colors([
                    'primary' => 'primary',
                    'info' => 'info',
                    'success' => 'success',
                    'warning' => 'warning',
                    'danger' => 'danger',
                    'gray' => 'gray',
                ])
                ->inline()
                ->live()
                ->visible(fn ($get): bool => ($isData($get) && ! $isRoundChart($get)) || $isSection($get))
                ->columnSpanFull(),

            Select::make('icon')
                ->label('Icon (optional)')
                ->options(InsightStat::ICONS)
                ->in(array_keys(InsightStat::ICONS))
                ->native(false)
                ->searchable()
                ->placeholder('No icon')
                ->visible(fn ($get): bool => $isStat($get) || $isSection($get))
                ->columnSpanFull(),

            Repeater::make('filters')
                ->label('Only include records where... (optional)')
                ->helperText('Narrow this widget to matching records — e.g., Severity is Error. All conditions must match.')
                ->schema($this->filterFields($insights, $filterable))
                ->columns(['default' => 1, 'sm' => 3])
                ->defaultItems(0)
                ->addActionLabel('Add condition')
                ->visible($isData)
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, string>  $filterable
     * @return array<int, mixed>
     */
    protected function filterFields(InsightQuery $insights, array $filterable): array
    {
        $needsValue = fn ($get): bool => ! in_array($get('operator'), ['is_null', 'is_not_null'], true);
        $isMulti = fn ($get): bool => in_array($get('operator'), ['in', 'not_in'], true);
        $isExact = fn ($get): bool => in_array($get('operator'), ['equals', 'not_equals', null, ''], true);
        $hasOptions = fn ($get): bool => filled($get('column')) && $insights->filterValueOptions((string) $get('column')) !== [];

        return [
            Select::make('column')
                ->label('Field')
                ->options($filterable)
                ->in(array_keys($filterable))
                ->native(false)
                ->searchable()
                ->placeholder('Field...')
                ->required()
                ->live()
                ->afterStateUpdated(function ($set): void {
                    $set('value_select', null);
                    $set('value_multi', []);
                    $set('value_text', null);
                    $set('value', null);
                }),

            Select::make('operator')
                ->label('Condition')
                ->options(InsightQuery::FILTER_OPERATORS)
                ->in(array_keys(InsightQuery::FILTER_OPERATORS))
                ->default('equals')
                ->native(false)
                ->required()
                ->live(),

            Select::make('value_select')
                ->label('Value')
                ->options(fn ($get): array => filled($get('column')) ? $insights->filterValueOptions((string) $get('column')) : [])
                ->native(false)
                ->searchable()
                ->placeholder('Pick value...')
                ->required()
                ->live()
                ->visible(fn ($get): bool => $needsValue($get) && $isExact($get) && $hasOptions($get)),

            Select::make('value_multi')
                ->label('Values')
                ->options(fn ($get): array => filled($get('column')) ? $insights->filterValueOptions((string) $get('column')) : [])
                ->multiple()
                ->native(false)
                ->searchable()
                ->placeholder('Pick values...')
                ->required()
                ->live()
                ->visible(fn ($get): bool => $needsValue($get) && $isMulti($get) && $hasOptions($get)),

            TextInput::make('value_text')
                ->label('Value')
                ->placeholder(fn ($get): string => $isMulti($get) ? 'e.g., ERROR, CRITICAL' : 'Enter value...')
                ->required()
                ->live(onBlur: true)
                ->visible(fn ($get): bool => $needsValue($get) && ! (($isExact($get) || $isMulti($get)) && $hasOptions($get))),
        ];
    }

    /**
     * Plain-English live summary + helpful guardrails rendered at the top of the widget form.
     *
     * @param  array<string, string>  $timeline
     * @param  array<string, string>  $custom
     */
    protected function renderPreview(callable $get, InsightQuery $insights, array $timeline, array $custom): HtmlString
    {
        $type = (string) ($get('type') ?? 'stat');
        $spanLabel = self::SPANS[(int) ($get('span') ?? 6)] ?? '½';
        $tip = null;

        if ($type === 'section') {
            $title = filled($get('label')) ? (string) $get('label') : 'Untitled section';
            $mode = (bool) $get('collapsible')
                ? ((bool) $get('collapsed') ? 'Collapsible (starts collapsed)' : 'Collapsible')
                : 'Always open';
            $summary = '<strong>Section</strong> — '.e($title).' · '.e($mode).' · Full width';
        } elseif ($type === 'widget') {
            $widgetClass = (string) ($get('widget') ?? '');
            $widgetName = $custom[$widgetClass] ?? 'a custom widget';
            $summary = 'Custom widget: <strong>'.e($widgetName).'</strong> · Width: '.e($spanLabel);
        } else {
            $aggregate = (string) ($get('aggregate') ?? 'count');
            $aggregateLabel = InsightQuery::AGGREGATES[$aggregate] ?? 'Count of records';
            $column = (string) ($get('column') ?? '');

            $metricText = $aggregate === 'count'
                ? 'Count of records'
                : ($column !== '' ? "{$aggregateLabel} of {$insights->labelFor($column)}" : "{$aggregateLabel} (pick a field)");

            $parts = [];

            if (in_array($type, ['chart', 'table'], true)) {
                $prefix = $type === 'table'
                    ? 'Summary table'
                    : Str::ucfirst((string) ($get('chart') ?? 'bar')).' chart';
                $group = (string) ($get('group_by') ?? '');
                $isTimeline = str_starts_with($group, '@');

                if ($isTimeline) {
                    $unitLabel = strtolower($timeline[$group] ?? substr($group, 1));
                    $parts[] = '<strong>'.e($prefix).'</strong> — '.e($metricText).' per <strong>'.e($unitLabel).'</strong>';

                    if ($type === 'chart' && in_array($get('chart'), ['pie', 'doughnut'], true)) {
                        $tip = 'Tip: Pie and doughnut charts work best for categories — try Line or Bar for trends over time.';
                    }
                } elseif ($group !== '') {
                    $limit = max(1, min(50, (int) ($get('limit') ?: 10)));
                    $parts[] = '<strong>'.e($prefix).'</strong> — '.e($metricText).' by <strong>'.e($insights->labelFor($group))."</strong> (top {$limit})";

                    if ($type === 'chart' && $get('chart') === 'line') {
                        $tip = 'Tip: Line charts work best for trends over time — try Bar, Pie, Doughnut, or Table for categories.';
                    }
                } else {
                    $parts[] = '<strong>'.e($prefix).'</strong> — '.e($metricText);
                }
            } else {
                $parts[] = '<strong>KPI Stat</strong> — '.e($metricText);
            }

            $dateCol = $get('date_column');
            $range = $get('range');
            $parts[] = filled($dateCol) && filled($range)
                ? e(InsightQuery::RANGES[(string) $range] ?? "Last {$range} days")
                : 'All time';

            $normalizedFilters = $this->normalizeFilters($get('filters') ?? []);
            $filterText = $insights->filterSummary(['filters' => $normalizedFilters]);
            if ($filterText !== null) {
                $parts[] = 'Where <strong>'.e($filterText).'</strong>';
            }

            $parts[] = 'Width: '.e($spanLabel);
            $summary = implode(' · ', $parts);
        }

        $tipHtml = $tip !== null
            ? '<div style="margin-top: 0.45rem; font-size: 0.78rem; color: #f59e0b;">'.e($tip).'</div>'
            : '';

        return new HtmlString(
            '<div style="padding: 0.75rem 0.95rem; border-radius: 0.625rem; border: 1px solid color-mix(in srgb, currentColor 16%, transparent); background: color-mix(in srgb, currentColor 5%, transparent); font-size: 0.84rem; line-height: 1.45;">'
            .'<div style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; opacity: 0.65; margin-bottom: 0.25rem;">Live summary</div>'
            .'<div>'.$summary.'</div>'
            .$tipHtml
            .'</div>'
        );
    }

    /**
     * Compact, informative title for collapsed items in the "Manage all" repeater.
     *
     * @param  array<string, mixed>  $state
     */
    protected function repeaterItemLabel(array $state): string
    {
        $title = filled($state['label'] ?? null) ? (string) $state['label'] : 'Untitled widget';
        $type = $state['type'] ?? 'stat';

        if ($type === 'section') {
            return "{$title} · Section";
        }

        $kind = match ($type) {
            'chart' => Str::ucfirst((string) ($state['chart'] ?? 'bar')).' chart',
            'table' => 'Table',
            'widget' => 'Custom widget',
            default => 'KPI Stat',
        };
        $span = self::SPANS[(int) ($state['span'] ?? 6)] ?? '½';

        return "{$title} · {$kind} · {$span}";
    }

    /**
     * Section keys => labels for the "Place in section" picker.
     *
     * @return array<string, string>
     */
    protected function sectionOptions(): array
    {
        $widgets = $this->dashboardWidgets();
        $keys = static::widgetKeys($widgets);
        $options = [];

        foreach ($widgets as $index => $widget) {
            if (($widget['type'] ?? null) === 'section') {
                $options[$keys[$index]] = filled($widget['label'] ?? null)
                    ? (string) $widget['label']
                    : 'Untitled section';
            }
        }

        return $options;
    }

    /**
     * Returns the array index right after the last widget belonging to `$sectionKey`
     * (immediately before the next section, or at the end of the array).
     *
     * @param  list<array<string, mixed>>  $widgets
     */
    protected function sectionInsertIndex(array $widgets, string $sectionKey): ?int
    {
        $start = $this->findWidgetIndex($widgets, $sectionKey);

        if ($start === null || ($widgets[$start]['type'] ?? null) !== 'section') {
            return null;
        }

        $count = count($widgets);
        for ($i = $start + 1; $i < $count; $i++) {
            if (($widgets[$i]['type'] ?? null) === 'section') {
                return $i;
            }
        }

        return $count;
    }

    /**
     * Finds the section key that owns the widget at `$index`, or null if it sits above all sections.
     *
     * @param  list<array<string, mixed>>  $widgets
     */
    protected function sectionKeyForWidget(array $widgets, int $index): ?string
    {
        $keys = static::widgetKeys($widgets);

        for ($i = $index - 1; $i >= 0; $i--) {
            if (($widgets[$i]['type'] ?? null) === 'section') {
                return $keys[$i];
            }
        }

        return null;
    }

    /**
     * Returns the index right before the first section (end of the ungrouped area).
     *
     * @param  list<array<string, mixed>>  $widgets
     */
    protected function ungroupedInsertIndex(array $widgets): int
    {
        foreach ($widgets as $index => $widget) {
            if (($widget['type'] ?? null) === 'section') {
                return $index;
            }
        }

        return count($widgets);
    }

    /**
     * Groups the flat mounted list into visual blocks: an optional leading ungrouped block
     * followed by boxed sections, each holding the widgets that belong to it.
     *
     * @return list<array{section: ?array<string, mixed>, items: list<array<string, mixed>>}>
     */
    public function getDashboardBlocks(): array
    {
        $blocks = [];
        $current = null;

        foreach ($this->getDashboardMounts() as $mount) {
            if (($mount['type'] ?? null) === 'section') {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = ['section' => $mount, 'items' => []];

                continue;
            }

            if ($current === null) {
                $current = ['section' => null, 'items' => []];
            }

            $current['items'][] = $mount;
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     */
    protected function findWidgetIndex(array $widgets, string $key): ?int
    {
        if ($key === '') {
            return null;
        }

        $index = array_search($key, static::widgetKeys($widgets), true);

        return $index === false ? null : $index;
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     */
    protected function persistWidgets(array $widgets, string $toast): void
    {
        InsightDashboard::query()->updateOrCreate(
            ['key' => static::class],
            ['widgets' => array_values($widgets)],
        );

        $this->cachedWidgets = null;

        // Sent from the browser, as in reorderWidgets(): an edited widget remounts in this render and would claim it.
        $this->js('new FilamentNotification().title('.json_encode($toast).').success().send()');
    }

    /**
     * Strips form-only helper keys and normalizes filter rows before storing in JSON.
     *
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    protected function normalizeWidget(array $widget): array
    {
        unset($widget['preset'], $widget['preview'], $widget['section_key'], $widget['placement']);

        if (($widget['type'] ?? null) === 'section') {
            $collapsible = (bool) ($widget['collapsible'] ?? false);

            return array_filter([
                'type' => 'section',
                'label' => trim((string) ($widget['label'] ?? '')),
                'description' => filled($widget['description'] ?? null) ? trim((string) $widget['description']) : null,
                'icon' => filled($widget['icon'] ?? null) ? (string) $widget['icon'] : null,
                'color' => filled($widget['color'] ?? null) ? (string) $widget['color'] : null,
                'collapsible' => $collapsible ?: null,
                'collapsed' => ($collapsible && (bool) ($widget['collapsed'] ?? false)) ? true : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        unset($widget['collapsible'], $widget['collapsed']);

        if (($widget['type'] ?? null) === 'table') {
            $widget['show_total'] = (bool) ($widget['show_total'] ?? true);
        } else {
            unset($widget['show_total']);
        }

        $filters = $this->normalizeFilters($widget['filters'] ?? []);
        if ($filters === []) {
            unset($widget['filters']);
        } else {
            $widget['filters'] = $filters;
        }

        foreach (['description', 'color', 'icon'] as $optional) {
            if (blank($widget[$optional] ?? null)) {
                unset($widget[$optional]);
            }
        }

        return $widget;
    }

    /**
     * @param  array<int|string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function normalizeFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $filter) {
            if (! is_array($filter) || blank($filter['column'] ?? null)) {
                continue;
            }

            $operator = (string) ($filter['operator'] ?? 'equals');
            $entry = [
                'column' => (string) $filter['column'],
                'operator' => $operator,
            ];

            if (! in_array($operator, ['is_null', 'is_not_null'], true)) {
                $value = match (true) {
                    in_array($operator, ['in', 'not_in'], true) && is_array($filter['value_multi'] ?? null) && $filter['value_multi'] !== [] => array_values($filter['value_multi']),
                    array_key_exists('value_select', $filter) && filled($filter['value_select']) => $filter['value_select'],
                    array_key_exists('value_text', $filter) && filled($filter['value_text']) => $filter['value_text'],
                    array_key_exists('value', $filter) && filled($filter['value']) => $filter['value'],
                    default => null,
                };

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                $entry['value'] = $value;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * Populates form-only filter value fields from saved widget JSON when editing.
     *
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    protected function hydrateWidget(array $widget): array
    {
        if (! is_array($widget['filters'] ?? null) || $widget['filters'] === []) {
            return $widget;
        }

        $insights = new InsightQuery(static::getResource(), static::getExcludedColumns());

        $widget['filters'] = array_map(function (array $filter) use ($insights): array {
            $column = (string) ($filter['column'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'equals');
            $value = $filter['value'] ?? null;
            $hasOptions = $column !== '' && $insights->filterValueOptions($column) !== [];

            if (in_array($operator, ['in', 'not_in'], true)) {
                if ($hasOptions) {
                    $filter['value_multi'] = is_array($value)
                        ? array_values(array_map('strval', $value))
                        : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
                } else {
                    $filter['value_text'] = is_array($value) ? implode(', ', $value) : (string) $value;
                }
            } elseif (in_array($operator, ['equals', 'not_equals'], true) && $hasOptions) {
                $filter['value_select'] = $value !== null ? (string) $value : null;
            } elseif (! in_array($operator, ['is_null', 'is_not_null'], true)) {
                $filter['value_text'] = is_array($value) ? implode(', ', $value) : (string) ($value ?? '');
            }

            return $filter;
        }, $widget['filters']);

        return $widget;
    }
}
