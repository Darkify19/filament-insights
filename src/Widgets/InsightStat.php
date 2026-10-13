<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;

class InsightStat extends StatsOverviewWidget
{
    /** Locked: column names in here reach SQL. */
    #[Locked]
    public array $config = [];

    #[Locked]
    public string $resource = '';

    // Overridden as methods, not properties: v3 declares these differently from v5.
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

        if (InsightQuery::window($this->config)[0] === null) {
            return [$stat->description('All time')];
        }

        $previous = $insights->value($this->config, period: 1);
        $change = $previous == 0 ? null : ($value - $previous) / abs($previous) * 100;
        [, $trend] = $insights->series([...$this->config, 'group_by' => (int) $this->config['range'] > 90 ? '@month' : '@day']);

        return [
            $stat
                ->description(InsightQuery::describe($this->config).($change === null ? '' : sprintf(' · %+.0f%% vs previous', $change)))
                ->descriptionIcon(match (true) {
                    $change > 0 => 'heroicon-m-arrow-trending-up',
                    $change < 0 => 'heroicon-m-arrow-trending-down',
                    default => null,
                })
                ->color(match (true) {
                    $change > 0 => 'success',
                    $change < 0 => 'danger',
                    default => 'gray',
                })
                ->chart($trend),
        ];
    }
}
