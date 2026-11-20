<?php

namespace CarlJanzell\FilamentInsights\Widgets;

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class InsightTable extends Widget
{
    /** Semantic accent => hex for inline share progress bars. */
    protected const ACCENTS = [
        'primary' => '#3b82f6',
        'info' => '#06b6d4',
        'success' => '#10b981',
        'warning' => '#f59e0b',
        'danger' => '#ef4444',
        'gray' => '#64748b',
    ];

    /** Locked: column names in here reach SQL. */
    #[Locked]
    public array $config = [];

    #[Locked]
    public string $resource = '';

    public function getHeading(): string
    {
        return (string) ($this->config['label'] ?? '');
    }

    public function getDescription(): string
    {
        $note = trim((string) ($this->config['description'] ?? ''));
        $period = InsightQuery::describe($this->config);
        $filters = $this->resource !== '' ? (new InsightQuery($this->resource))->filterSummary($this->config) : null;

        return implode(' · ', array_filter([$note !== '' ? $note : null, $period, $filters]));
    }

    // Overridden as a method rather than declaring `$view`: Filament 3 declares `$view` static, 4 and 5 don't.
    public function render(): View
    {
        return view('filament-insights::widgets.table', $this->getViewData());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return $this->getTableData();
    }

    /**
     * @return array{
     *     heading: string,
     *     description: string,
     *     groupHeader: string,
     *     valueHeader: string,
     *     showRank: bool,
     *     showShare: bool,
     *     showTotal: bool,
     *     totalFormatted: string,
     *     accent: string,
     *     rows: list<array{rank: int, label: string, value: float, formatted: string, share: ?float}>
     * }
     */
    protected function getTableData(): array
    {
        $insights = new InsightQuery($this->resource);
        [$labels, $values] = $insights->series($this->config);
        $currency = InsightQuery::currencyFor($this->config);
        $aggregate = (string) ($this->config['aggregate'] ?? 'count');
        $group = (string) ($this->config['group_by'] ?? '');
        $isTimeline = str_starts_with($group, '@');

        $format = fn (float $value): string => $currency
            ? Number::currency($value, $currency)
            : Number::format($value, maxPrecision: 2);

        $isAdditive = in_array($aggregate, ['count', 'sum'], true);
        $sum = $isAdditive ? array_sum($values) : 0.0;
        $showShare = $isAdditive && $sum > 0 && $values !== [];
        $showTotal = (bool) ($this->config['show_total'] ?? true) && $isAdditive && count($values) > 1;

        $groupHeader = $isTimeline
            ? Str::ucfirst(substr($group, 1))
            : ($group !== '' ? $insights->labelFor($group) : 'Group');

        $column = (string) ($this->config['column'] ?? '');
        $valueHeader = match ($aggregate) {
            'count' => 'Records',
            'count_distinct' => $column !== '' ? 'Unique '.$insights->labelFor($column) : 'Unique values',
            default => (InsightQuery::AGGREGATES[$aggregate] ?? Str::ucfirst($aggregate))
                .($column !== '' ? ' ('.$insights->labelFor($column).')' : ''),
        };

        $rows = [];
        foreach ($labels as $index => $label) {
            $value = (float) ($values[$index] ?? 0.0);
            $rows[] = [
                'rank' => $index + 1,
                'label' => $label,
                'value' => $value,
                'formatted' => $format($value),
                'share' => $showShare ? round(($value / $sum) * 100, 1) : null,
            ];
        }

        $colorKey = is_string($this->config['color'] ?? null) && isset(self::ACCENTS[$this->config['color']])
            ? $this->config['color']
            : 'primary';

        return [
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'groupHeader' => $groupHeader,
            'valueHeader' => $valueHeader,
            'showRank' => ! $isTimeline,
            'showShare' => $showShare,
            'showTotal' => $showTotal,
            'totalFormatted' => $format($sum),
            'accent' => self::ACCENTS[$colorKey],
            'rows' => $rows,
        ];
    }
}
