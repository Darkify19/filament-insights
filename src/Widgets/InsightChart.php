<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

class InsightChart extends ChartWidget
{
    protected const PALETTE = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'];

    /** Semantic accent => [stroke/border, bar fill, area fill]. */
    protected const ACCENTS = [
        'primary' => ['#3b82f6', 'rgba(59, 130, 246, 0.78)', 'rgba(59, 130, 246, 0.20)'],
        'info' => ['#06b6d4', 'rgba(6, 182, 212, 0.78)', 'rgba(6, 182, 212, 0.20)'],
        'success' => ['#10b981', 'rgba(16, 185, 129, 0.78)', 'rgba(16, 185, 129, 0.20)'],
        'warning' => ['#f59e0b', 'rgba(245, 158, 11, 0.78)', 'rgba(245, 158, 11, 0.20)'],
        'danger' => ['#ef4444', 'rgba(239, 68, 68, 0.78)', 'rgba(239, 68, 68, 0.20)'],
        'gray' => ['#64748b', 'rgba(100, 116, 139, 0.78)', 'rgba(100, 116, 139, 0.20)'],
    ];

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
        $note = trim((string) ($this->config['description'] ?? ''));
        $period = InsightQuery::describe($this->config);
        $filters = $this->resource !== '' ? (new InsightQuery($this->resource))->filterSummary($this->config) : null;

        return implode(' · ', array_filter([$note !== '' ? $note : null, $period, $filters]));
    }

    // Overridden as methods, not properties: v3 declares these static, v4 and v5 don't.
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
        $type = $this->getType();

        $dataset = [
            'label' => $this->getHeading(),
            'data' => $values,
        ];

        if ($this->isRound()) {
            $dataset['backgroundColor'] = array_map(fn (int $i): string => self::PALETTE[$i % count(self::PALETTE)], array_keys($values));
            $dataset['borderWidth'] = 0;
        } else {
            $colorKey = is_string($this->config['color'] ?? null) && isset(self::ACCENTS[$this->config['color']])
                ? $this->config['color']
                : 'primary';
            [$border, $barFill, $areaFill] = self::ACCENTS[$colorKey];

            $dataset['borderColor'] = $border;
            $dataset['backgroundColor'] = $type === 'line' ? $areaFill : $barFill;

            if ($type === 'bar') {
                $dataset['borderWidth'] = 1;
                $dataset['borderRadius'] = 6;
                $dataset['maxBarThickness'] = 44;
            } else {
                $dataset['borderWidth'] = 2;
                $dataset['fill'] = true;
                $dataset['tension'] = 0.3;
                $dataset['pointRadius'] = 3;
                $dataset['pointHoverRadius'] = 5;
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [$dataset],
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
