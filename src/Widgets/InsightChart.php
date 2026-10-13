<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Support\RawJs;
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

    protected function getOptions(): array|RawJs
    {
        $currency = InsightQuery::currencyFor($this->config);

        if ($currency === null) {
            return $this->isRound()
                ? ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]]]
                : ['plugins' => ['legend' => ['display' => false]]];
        }

        // Money needs formatter callbacks, so these options are JS. The browser's Intl formats them
        // in the page's language: exact amounts in tooltips, compact ones ("₱10B") on the axis.
        // Single quotes only: this lands inside a double-quoted x-data attribute.
        $money = fn (string $notation): string => sprintf(
            "new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: '%s', currencyDisplay: 'narrowSymbol', notation: '%s' })",
            preg_replace('/[^A-Z]/', '', strtoupper($currency)),
            $notation,
        );

        return RawJs::make($this->isRound()
            ? "{
                scales: { x: { display: false }, y: { display: false } },
                plugins: { tooltip: { callbacks: { label: (context) => context.label + ': ' + {$money('standard')}.format(context.raw) } } },
            }"
            : "{
                scales: { y: { ticks: { callback: (value) => {$money('compact')}.format(value) } } },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (context) => {$money('standard')}.format(context.raw) } },
                },
            }");
    }

    protected function isRound(): bool
    {
        return in_array($this->getType(), ['pie', 'doughnut'], true);
    }
}
