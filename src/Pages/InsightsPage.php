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
use Filament\Notifications\Notification;
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

    /** @var list<array<string, mixed>>|null */
    protected ?array $cachedWidgets = null;

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
     * Shares one blade view across Filament v3 and v5 (v3 declares `$view` static, v5 doesn't).
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

        return collect($this->dashboardWidgets())
            ->map(function (array $widget, int $index) use ($custom): ?array {
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
                    // Keyed by content, so an edited widget remounts instead of keeping stale numbers.
                    'key' => $index.'-'.md5(json_encode($widget)),
                    'span' => (int) ($widget['span'] ?? 6),
                ];
            })
            ->filter()
            ->values()
            ->all();
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

                    Notification::make()->title('Dashboard saved')->success()->send();
                }),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function widgetFields(): array
    {
        $insights = new InsightQuery(static::getResource());
        $custom = collect(FilamentInsightsPlugin::get()->getWidgets())
            ->mapWithKeys(fn (string $widget): array => [$widget => Str::headline(class_basename($widget))])
            ->all();

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
                ->options($insights->options('numeric'))
                ->required()
                ->visible(fn ($get): bool => $isData($get) && $get('aggregate') !== 'count'),
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
                    'Over time' => ['@day' => 'Day', '@month' => 'Month', '@year' => 'Year'],
                    'By field' => $insights->options('group'),
                ])
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
                ->options($insights->options('date'))
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
