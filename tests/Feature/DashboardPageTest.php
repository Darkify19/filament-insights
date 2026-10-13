<?php

use CarlJanzell\FilamentInsights\InsightsServiceProvider;
use CarlJanzell\FilamentInsights\Models\InsightDashboard;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderInsights;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderResource;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrdersOverview;
use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;

afterEach(fn () => OrdersOverview::$shown = true);

it('renders through the panel, arrangeable only for managers', function (bool $admin) {
    signIn($admin);
    dashboard([
        statWidget('Orders'),
        ['type' => 'chart', 'label' => 'By status', 'span' => 6, 'chart' => 'bar', 'aggregate' => 'count', 'group_by' => 'status'],
    ]);

    $response = $this->get(OrderInsights::getUrl())
        ->assertOk()
        ->assertSee('Order insights')
        ->assertSee('fi-insights-grid', escape: false);

    $admin
        ? $response->assertSee('x-sortable-item', escape: false)
        : $response->assertDontSee('x-sortable-item', escape: false);
})->with(['manager' => true, 'viewer' => false]);

it('imports its motion scripts on the insights page and nowhere else', function () {
    signIn();
    dashboard([statWidget('Orders')]);

    $anime = FilamentAsset::getScriptSrc('anime', InsightsServiceProvider::PACKAGE);

    expect($anime)->toContain('js/carljanzell/filament-insights/anime.js');

    $this->get(OrderInsights::getUrl())->assertOk()
        ->assertSee($anime, escape: false)
        ->assertSee(FilamentAsset::getScriptSrc('motion', InsightsServiceProvider::PACKAGE), escape: false);

    $this->get(OrderResource::getUrl('index'))->assertOk()->assertDontSee('filament-insights/anime.js', escape: false);
});

it('says so when a dashboard has no widgets yet', function () {
    signIn();

    $this->get(OrderInsights::getUrl())->assertOk()->assertSee('No widgets yet');
});

it('mounts only the widgets this viewer may see', function () {
    signIn();
    dashboard([
        statWidget('Orders'),
        statWidget('By channel', ['aggregate' => 'count', 'group_by' => 'channel']), // excluded column
        ['type' => 'widget', 'label' => 'Overview', 'widget' => OrdersOverview::class],
        ['type' => 'widget', 'label' => 'Unregistered', 'widget' => 'App\\Widgets\\NotRegistered'],
        ['type' => 'chart', 'label' => 'By status', 'chart' => 'pie', 'group_by' => 'status'],
    ]);

    $classes = fn (): array => array_column(Livewire::test(OrderInsights::class)->instance()->getDashboardMounts(), 'class');

    expect($classes())->toBe([InsightStat::class, OrdersOverview::class, InsightChart::class]);

    OrdersOverview::$shown = false;

    expect($classes())->toBe([InsightStat::class, InsightChart::class]);
});

it('saves a reordered dashboard and keeps hidden widgets in their slots', function () {
    signIn();
    dashboard([statWidget('A'), ['type' => 'widget', 'label' => 'Hidden', 'widget' => 'App\\Nope'], statWidget('B'), statWidget('C')]);

    $page = Livewire::test(OrderInsights::class);
    [$a, $b, $c] = array_column($page->instance()->getDashboardMounts(), 'key');

    $page->call('reorderWidgets', [$c, $a, $b]);

    expect(savedLabels())->toBe(['C', 'Hidden', 'A', 'B'])
        // Keys follow content, not position, so moved widgets keep their mounted components.
        ->and(array_column(Livewire::test(OrderInsights::class)->instance()->getDashboardMounts(), 'key'))->toBe([$c, $a, $b]);
});

it('tells identical widgets apart', function () {
    signIn();
    dashboard([statWidget('A'), statWidget('A'), statWidget('B')]);

    $page = Livewire::test(OrderInsights::class);
    [$first, $copy, $b] = array_column($page->instance()->getDashboardMounts(), 'key');

    expect($first)->not->toBe($copy);

    $page->call('reorderWidgets', [$b, $copy, $first]);

    expect(savedLabels())->toBe(['B', 'A', 'A']);
});

it('ignores an order that is not a reordering of what is on screen', function (Closure $keys) {
    signIn();
    dashboard([statWidget('A'), statWidget('B'), statWidget('C')]);

    $page = Livewire::test(OrderInsights::class);

    $page->call('reorderWidgets', $keys(array_column($page->instance()->getDashboardMounts(), 'key')));

    expect(savedLabels())->toBe(['A', 'B', 'C']);
})->with([
    'missing one' => fn (array $k): array => [$k[2], $k[0]],
    'unknown key' => fn (array $k): array => [$k[2], $k[0], 'stale-1'],
    'repeated key' => fn (array $k): array => [$k[0], $k[0], $k[1]],
    'nested values' => fn (array $k): array => [[$k[0]], $k[1], $k[2]],
    'integers' => fn (array $k): array => [1, 2, 3],
]);

it('forbids reordering without manage rights', function () {
    signIn(admin: false);
    dashboard([statWidget('A'), statWidget('B')]);

    $page = Livewire::test(OrderInsights::class);
    [$a, $b] = array_column($page->instance()->getDashboardMounts(), 'key');

    $page->call('reorderWidgets', [$b, $a])->assertForbidden();

    expect(savedLabels())->toBe(['A', 'B']);
});

it('lets managers save the dashboard from the editor', function () {
    signIn();

    Livewire::test(OrderInsights::class)
        ->callAction('editDashboard', data: ['widgets' => [statWidget('Paid orders', ['span' => 6])]])
        ->assertHasNoActionErrors();

    expect(InsightDashboard::query()->value('widgets')[0])
        ->toMatchArray(['type' => 'stat', 'label' => 'Paid orders', 'aggregate' => 'count', 'span' => 6]);
});

it('rejects a column the editor never offered', function () {
    signIn();

    Livewire::test(OrderInsights::class)
        ->callAction('editDashboard', data: ['widgets' => [statWidget('Secrets', ['aggregate' => 'sum', 'column' => 'secret'])]])
        ->assertHasActionErrors(['widgets.0.column']);

    expect(InsightDashboard::query()->count())->toBe(0);
});

it('hides the editor from viewers', function () {
    signIn(admin: false);

    Livewire::test(OrderInsights::class)->assertActionHidden('editDashboard');
});
