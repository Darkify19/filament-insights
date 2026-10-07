<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

class InsightChart extends ChartWidget
{
    protected const PALETTE = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'];

    /** Locked: column names in here reach SQL. */
    #[Locked]
    public array $config = [];

    #[Locked]
    public string $resource = '';

    public function getHeading(): string
    {
        return $this->config['label'] ?? '';
    }

    public function getDescription(): string
    {
        return InsightQuery::describe($this->config);
    }

    // Overridden as methods, not properties: v3 declares these static, v5 doesn't.
    protected function getPollingInterval(): ?string
    {
        return null;
    }

    protected function getMaxHeight(): ?string
    {
        return '280px';
    }

    protected function getType(): string
    {
        return in_array($type = $this->config['chart'] ?? 'bar', ['bar', 'line', 'pie', 'doughnut'], true) ? $type : 'bar';
    }

    protected function getData(): array
    {
        [$labels, $values] = (new InsightQuery($this->resource))->series($this->config);

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => $this->getHeading(),
                'data' => $values,
                ...($this->isRound() ? [
                    'backgroundColor' => array_map(fn (int $i): string => self::PALETTE[$i % count(self::PALETTE)], array_keys($values)),
                ] : []),
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return $this->isRound()
            ? ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]]]
            : ['plugins' => ['legend' => ['display' => false]]];
    }

    protected function isRound(): bool
    {
        return in_array($this->getType(), ['pie', 'doughnut'], true);
    }
}
