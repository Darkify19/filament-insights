<?php

use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderResource;
use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use CarlJanzell\FilamentInsights\Widgets\InsightTable;
use Filament\Support\RawJs;
use Illuminate\Support\Number;
use Livewire\Livewire;

/**
 * @param  class-string  $widget
 * @param  array<string, mixed>  $config
 */
function widget(string $widget, array $config): object
{
    return Livewire::test($widget, ['config' => $config, 'resource' => OrderResource::class])->instance();
}

/**
 * Calls one of the widget's protected methods, the way Filament does when it renders.
 */
function invoke(object $widget, string $method): mixed
{
    return (fn () => $this->{$method}())->call($widget);
}

it('shows a stat with its change against the previous period', function () {
    $this->travelTo('2026-03-31 12:00');

    order(['ordered_at' => '2026-03-30']);
    order(['ordered_at' => '2026-03-20']);
    order(['ordered_at' => '2026-02-20']);

    Livewire::test(InsightStat::class, [
        'config' => ['label' => 'Orders', 'aggregate' => 'count', 'date_column' => 'ordered_at', 'range' => 30],
        'resource' => OrderResource::class,
    ])
        ->assertSee('Orders')
        ->assertSee('Last 30 days · +100% vs previous');
});

it('shows an amount as money in the plugin currency', function () {
    order(['total' => 1250.5]);

    Livewire::test(InsightStat::class, [
        'config' => ['label' => 'Revenue', 'aggregate' => 'sum', 'column' => 'total', 'format' => 'money'],
        'resource' => OrderResource::class,
    ])
        ->assertSee(Number::currency(1250.5, 'PHP'))
        ->assertSee('All time');
});

it('charts each group, coloured slice by slice when round', function () {
    order();
    order();
    order(['status' => 'pending']);

    $data = invoke(widget(InsightChart::class, ['label' => 'By status', 'chart' => 'pie', 'group_by' => 'status']), 'getData');

    expect($data['labels'])->toBe(['Paid', 'Pending'])
        ->and($data['datasets'][0]['data'])->toBe([2.0, 1.0])
        ->and($data['datasets'][0]['backgroundColor'])->toHaveCount(2);
});

it('falls back to a bar chart for an unknown chart type', function () {
    expect(invoke(widget(InsightChart::class, ['chart' => '"><script>', 'group_by' => 'status']), 'getType'))->toBe('bar');
});

it('formats money axes and tooltips in the browser', function () {
    $options = invoke(widget(InsightChart::class, ['chart' => 'bar', 'aggregate' => 'sum', 'column' => 'total', 'format' => 'money', 'group_by' => 'status']), 'getOptions');

    expect($options)->toBeInstanceOf(RawJs::class)
        ->and((string) $options)->toContain("currency: 'PHP'");
});

it('renders stat notes, filters, custom colors and icons', function () {
    order(['status' => 'paid']);
    order(['status' => 'pending']);

    Livewire::test(InsightStat::class, [
        'config' => [
            'label' => 'Paid orders',
            'description' => 'Settled only',
            'aggregate' => 'count',
            'color' => 'success',
            'icon' => 'heroicon-m-check-circle',
            'filters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'paid']],
        ],
        'resource' => OrderResource::class,
    ])
        ->assertSee('Paid orders')
        ->assertSee('1')
        ->assertSee('Settled only · All time · Status is Paid');
});

it('applies accent colors and filters on charts', function () {
    order(['status' => 'paid', 'total' => 100]);
    order(['status' => 'pending', 'total' => 50]);

    $chart = widget(InsightChart::class, [
        'label' => 'Paid totals',
        'description' => 'Approved only',
        'chart' => 'bar',
        'color' => 'danger',
        'aggregate' => 'sum',
        'column' => 'total',
        'group_by' => 'status',
        'filters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'paid']],
    ]);

    $data = invoke($chart, 'getData');

    expect($data['labels'])->toBe(['Paid'])
        ->and($data['datasets'][0]['data'])->toBe([100.0])
        ->and($data['datasets'][0]['borderColor'])->toBe('#ef4444')
        ->and($chart->getDescription())->toBe('Approved only · All time · Status is Paid');
});

it('renders a grouped summary table with ranks, shares, currency, filters and totals', function () {
    order(['status' => 'paid', 'total' => 300]);
    order(['status' => 'paid', 'total' => 100]);
    order(['status' => 'pending', 'total' => 100]);

    Livewire::test(InsightTable::class, [
        'config' => [
            'label' => 'Revenue by Status',
            'description' => 'Active orders',
            'aggregate' => 'sum',
            'column' => 'total',
            'format' => 'money',
            'group_by' => 'status',
            'color' => 'success',
        ],
        'resource' => OrderResource::class,
    ])
        ->assertSee('Revenue by Status')
        ->assertSee('Active orders · All time')
        ->assertSee('Paid')
        ->assertSee('Pending')
        ->assertSee(Number::currency(400, 'PHP'))
        ->assertSee(Number::currency(100, 'PHP'))
        ->assertSee('80.0%')
        ->assertSee('20.0%')
        ->assertSee('Total')
        ->assertSee(Number::currency(500, 'PHP'));

    Livewire::test(InsightTable::class, [
        'config' => [
            'label' => 'Status Summary',
            'aggregate' => 'count',
            'group_by' => 'status',
            'show_total' => false,
        ],
        'resource' => OrderResource::class,
    ])
        ->assertSee('Paid')
        ->assertSee('Pending')
        ->assertDontSee('<tfoot>', escape: false);
});

it('renders a timeline table and an empty state when no records match', function () {
    $this->travelTo('2026-03-10 12:00');
    order(['ordered_at' => '2026-03-10 09:00', 'status' => 'paid']);

    $table = widget(InsightTable::class, [
        'label' => 'Daily Orders',
        'aggregate' => 'count',
        'group_by' => '@day',
        'date_column' => 'ordered_at',
        'range' => 7,
    ]);

    $data = invoke($table, 'getTableData');
    expect($data['showRank'])->toBeFalse()
        ->and($data['groupHeader'])->toBe('Day')
        ->and($data['rows'])->toHaveCount(7);

    Livewire::test(InsightTable::class, [
        'config' => [
            'label' => 'Pending Only',
            'aggregate' => 'count',
            'group_by' => 'status',
            'filters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'pending']],
        ],
        'resource' => OrderResource::class,
    ])->assertSee('No matching records');
});
