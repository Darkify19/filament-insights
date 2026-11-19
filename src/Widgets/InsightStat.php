<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;

class InsightStat extends StatsOverviewWidget
{
    public const COLORS = [
        'primary' => 'Primary',
        'info' => 'Cyan',
        'success' => 'Green',
        'warning' => 'Amber',
        'danger' => 'Red',
        'gray' => 'Slate',
    ];

    public const ICONS = [
        'heroicon-m-chart-bar' => 'Chart',
        'heroicon-m-users' => 'Users',
        'heroicon-m-exclamation-triangle' => 'Alert',
        'heroicon-m-check-circle' => 'Check',
        'heroicon-m-clock' => 'Clock',
        'heroicon-m-banknotes' => 'Money',
        'heroicon-m-document-text' => 'Document',
        'heroicon-m-fire' => 'Urgent',
        'heroicon-m-bolt' => 'Activity',
        'heroicon-m-folder' => 'Records',
        'heroicon-m-shield-check' => 'Security',
        'heroicon-m-sparkles' => 'Highlight',
    ];

    /** Locked: column names in here reach SQL. */
    #[Locked]
    public array $config = [];

    #[Locked]
    public string $resource = '';

    // Overridden as methods, not properties: v3 declares these differently from v4 and v5.
    protected function getPollingInterval(): ?string
    {
        return null;
    }

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $insights = new InsightQuery($this->resource);
        $value = $insights->value($this->config);
        $currency = InsightQuery::currencyFor($this->config);
        $stat = Stat::make($this->config['label'] ?? '', $currency ? Number::currency($value, $currency) : Number::format($value, maxPrecision: 2));

        $icon = $this->config['icon'] ?? null;
        if (is_string($icon) && array_key_exists($icon, self::ICONS)) {
            $stat->icon($icon);
        }

        $note = trim((string) ($this->config['description'] ?? ''));
        $filterSummary = $insights->filterSummary($this->config);
        $customColor = $this->config['color'] ?? null;
        $color = is_string($customColor) && array_key_exists($customColor, self::COLORS) ? $customColor : null;

        if (InsightQuery::window($this->config)[0] === null) {
            $description = implode(' · ', array_filter([$note !== '' ? $note : null, 'All time', $filterSummary]));

            return [$color ? $stat->description($description)->color($color) : $stat->description($description)];
        }

        $previous = $insights->value($this->config, period: 1);
        $change = $previous == 0 ? null : ($value - $previous) / abs($previous) * 100;
        [, $trend] = $insights->series([...$this->config, 'group_by' => InsightQuery::sparklineBucket($this->config)]);

        $periodText = InsightQuery::describe($this->config).($change === null ? '' : sprintf(' · %+.0f%% vs previous', $change));
        $description = implode(' · ', array_filter([$note !== '' ? $note : null, $periodText, $filterSummary]));

        return [
            $stat
                ->description($description)
                ->descriptionIcon(match (true) {
                    $change > 0 => 'heroicon-m-arrow-trending-up',
                    $change < 0 => 'heroicon-m-arrow-trending-down',
                    default => null,
                })
                ->color($color ?? match (true) {
                    $change > 0 => 'success',
                    $change < 0 => 'danger',
                    default => 'gray',
                })
                ->chart($trend),
        ];
    }
}
