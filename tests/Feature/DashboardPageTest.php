<?php

use CarlJanzell\FilamentInsights\InsightsServiceProvider;
use CarlJanzell\FilamentInsights\Models\InsightDashboard;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderInsights;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderResource;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrdersOverview;
use CarlJanzell\FilamentInsights\Widgets\InsightChart;
use CarlJanzell\FilamentInsights\Widgets\InsightStat;
use CarlJanzell\FilamentInsights\Widgets\InsightTable;
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

    Livewire::test(OrderInsights::class)
        ->assertActionHidden('editDashboard')
        ->assertActionHidden('addWidget');
});

it('lets managers add, edit, duplicate, resize and remove individual widgets', function () {
    signIn();

    // Add a widget with filters, subtitle, accent color and icon.
    Livewire::test(OrderInsights::class)
        ->callAction('addWidget', data: [
            'type' => 'stat',
            'label' => 'Paid orders',
            'description' => 'Completed sales only',
            'span' => 4,
            'aggregate' => 'count',
            'color' => 'success',
            'icon' => 'heroicon-m-check-circle',
            'filters' => [
                ['column' => 'status', 'operator' => 'equals', 'value_select' => 'paid'],
            ],
        ])
        ->assertHasNoActionErrors();

    $saved = InsightDashboard::query()->value('widgets');
    expect($saved)->toHaveCount(1)
        ->and($saved[0])->toMatchArray([
            'type' => 'stat',
            'label' => 'Paid orders',
            'description' => 'Completed sales only',
            'span' => 4,
            'color' => 'success',
            'icon' => 'heroicon-m-check-circle',
            'filters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'paid']],
        ])
        ->and($saved[0]['filters'][0])->not->toHaveKey('value_select');

    // Edit that single widget in place.
    $page = Livewire::test(OrderInsights::class);
    $key = $page->instance()->getDashboardMounts()[0]['key'];

    $page->callAction('editWidget', data: [
        'type' => 'stat',
        'label' => 'Confirmed orders',
        'span' => 6,
        'aggregate' => 'count',
    ], arguments: ['key' => $key])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Confirmed orders']);

    // Resize directly from the card toolbar.
    $page = Livewire::test(OrderInsights::class);
    $key = $page->instance()->getDashboardMounts()[0]['key'];
    $page->call('resizeWidget', $key, 3);
    expect(InsightDashboard::query()->value('widgets')[0]['span'])->toBe(3);

    // Duplicate directly from the card toolbar.
    $page = Livewire::test(OrderInsights::class);
    $key = $page->instance()->getDashboardMounts()[0]['key'];
    $page->call('duplicateWidget', $key);
    expect(savedLabels())->toBe(['Confirmed orders', 'Confirmed orders (copy)']);

    // Remove one widget directly from the card toolbar.
    $page = Livewire::test(OrderInsights::class);
    $copyKey = $page->instance()->getDashboardMounts()[1]['key'];
    $page->callAction('deleteWidget', arguments: ['key' => $copyKey])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Confirmed orders']);
});

it('forbids single-widget mutations without manage rights', function () {
    signIn(admin: false);
    dashboard([statWidget('Orders')]);

    $key = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts()[0]['key'];

    Livewire::test(OrderInsights::class)->call('duplicateWidget', $key)->assertForbidden();
    Livewire::test(OrderInsights::class)->call('resizeWidget', $key, 12)->assertForbidden();

    expect(savedLabels())->toBe(['Orders'])
        ->and(InsightDashboard::query()->value('widgets')[0]['span'])->toBe(3);
});

it('skips widgets whose filters reference an excluded column', function () {
    signIn();
    dashboard([
        statWidget('Visible'),
        statWidget('Filtered by channel', [
            'filters' => [['column' => 'channel', 'operator' => 'equals', 'value' => 'web']],
        ]),
    ]);

    $mounts = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts();

    expect(array_column($mounts, 'label'))->toBe(['Visible']);
});

it('renders card action toolbars only for managers', function (bool $admin) {
    signIn($admin);
    dashboard([statWidget('Orders')]);

    $response = $this->get(OrderInsights::getUrl())->assertOk();

    $admin
        ? $response->assertSee('aria-label="Widget actions"', escape: false)
        : $response->assertDontSee('aria-label="Widget actions"', escape: false);
})->with(['manager' => true, 'viewer' => false]);

it('lets managers toggle viewer preview mode to hide toolbars and drag handles', function () {
    signIn();
    dashboard([statWidget('Orders')]);

    Livewire::test(OrderInsights::class)
        ->assertActionVisible('togglePreview')
        ->assertActionVisible('addWidget')
        ->assertSee('aria-label="Widget actions"', escape: false)
        ->callAction('togglePreview')
        ->assertSet('previewing', true)
        ->assertActionHidden('addWidget')
        ->assertActionHidden('editDashboard')
        ->assertDontSee('aria-label="Widget actions"', escape: false)
        ->assertDontSee('x-sortable-item', escape: false)
        ->callAction('togglePreview')
        ->assertSet('previewing', false)
        ->assertActionVisible('addWidget')
        ->assertSee('aria-label="Widget actions"', escape: false);
});

it('hides the preview toggle from viewers', function () {
    signIn(admin: false);
    dashboard([statWidget('Orders')]);

    Livewire::test(OrderInsights::class)
        ->assertActionHidden('togglePreview')
        ->assertActionHidden('addSection');
});

it('lets managers add sections, place widgets inside them, reorder across sections, and remove sections safely', function () {
    signIn();

    // Add first section at the bottom (empty dashboard).
    Livewire::test(OrderInsights::class)
        ->callAction('addSection', data: [
            'label' => 'Breakdowns',
            'description' => 'Detailed grouped charts and tables',
            'icon' => 'heroicon-m-chart-bar',
            'color' => 'info',
            'collapsible' => true,
            'collapsed' => false,
        ])
        ->assertHasNoActionErrors();

    // Add a second section at the top of the dashboard.
    Livewire::test(OrderInsights::class)
        ->callAction('addSection', data: [
            'label' => 'Overview',
            'description' => 'High-level KPIs',
            'placement' => 'top',
        ])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Overview', 'Breakdowns']);

    // Insert a widget directly into the "Overview" section (should land before "Breakdowns").
    $overviewKey = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts()[0]['key'];

    Livewire::test(OrderInsights::class)
        ->callAction('addWidget', data: [
            'type' => 'stat',
            'label' => 'Total Orders',
            'span' => 3,
            'aggregate' => 'count',
            'section_key' => $overviewKey,
        ])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Overview', 'Total Orders', 'Breakdowns']);

    // Add a grouped table widget into "Breakdowns" via action argument (as clicked from section '+' button).
    $breakdownsKey = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts()[2]['key'];

    Livewire::test(OrderInsights::class)
        ->callAction('addWidget', data: [
            'type' => 'table',
            'label' => 'Orders by Status',
            'span' => 6,
            'aggregate' => 'count',
            'group_by' => 'status',
            'limit' => 10,
        ], arguments: ['section_key' => $breakdownsKey])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Overview', 'Total Orders', 'Breakdowns', 'Orders by Status']);

    // Verify table widget mounts as InsightTable and sections render with titles & descriptions.
    $mounts = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts();
    expect($mounts[0]['type'])->toBe('section')
        ->and($mounts[3]['class'])->toBe(InsightTable::class);

    $this->get(OrderInsights::getUrl())
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('High-level KPIs')
        ->assertSee('Breakdowns')
        ->assertSee('Detailed grouped charts and tables')
        ->assertSee('aria-label="Section actions"', escape: false);

    // Drag "Total Orders" across the section boundary into "Breakdowns".
    $keys = array_column($mounts, 'key');
    Livewire::test(OrderInsights::class)->call('reorderWidgets', [$keys[0], $keys[2], $keys[1], $keys[3]]);
    expect(savedLabels())->toBe(['Overview', 'Breakdowns', 'Total Orders', 'Orders by Status']);

    // Removing the "Breakdowns" section keeps its widgets on the dashboard.
    $updatedMounts = Livewire::test(OrderInsights::class)->instance()->getDashboardMounts();
    Livewire::test(OrderInsights::class)
        ->callAction('deleteWidget', arguments: ['key' => $updatedMounts[1]['key']])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Overview', 'Total Orders', 'Orders by Status']);
});

it('hides empty sections from viewers while showing them to managers', function () {
    dashboard([
        ['type' => 'section', 'label' => 'Empty Top'],
        ['type' => 'section', 'label' => 'Excluded Only'],
        ['type' => 'table', 'label' => 'By Channel', 'group_by' => 'channel'],
        ['type' => 'section', 'label' => 'Visible Section', 'description' => 'Has real widgets'],
        statWidget('Orders'),
        ['type' => 'section', 'label' => 'Empty Trailing'],
    ]);

    signIn(admin: true);
    expect(array_column(Livewire::test(OrderInsights::class)->instance()->getDashboardMounts(), 'label'))
        ->toBe(['Empty Top', 'Excluded Only', 'Visible Section', 'Orders', 'Empty Trailing']);

    signIn(admin: false);
    expect(array_column(Livewire::test(OrderInsights::class)->instance()->getDashboardMounts(), 'label'))
        ->toBe(['Visible Section', 'Orders']);
});

it('hides section toolbars in preview mode while keeping section titles and collapse toggles', function () {
    signIn();
    dashboard([
        ['type' => 'section', 'label' => 'Overview', 'description' => 'Key numbers', 'collapsible' => true],
        statWidget('Orders'),
    ]);

    Livewire::test(OrderInsights::class)
        ->assertSee('aria-label="Section actions"', escape: false)
        ->assertSee('1 widget')
        ->callAction('togglePreview')
        ->assertDontSee('aria-label="Section actions"', escape: false)
        ->assertDontSee('1 widget')
        ->assertSee('Overview')
        ->assertSee('Key numbers')
        ->assertSee('Toggle Overview', escape: false);
});

it('groups widgets into boxed section blocks and lets managers move widgets between sections when editing', function () {
    signIn();
    dashboard([
        statWidget('Ungrouped Stat'),
        ['type' => 'section', 'label' => 'Overview', 'description' => 'Top metrics'],
        statWidget('Revenue'),
        ['type' => 'section', 'label' => 'Details'],
    ]);

    $page = Livewire::test(OrderInsights::class);
    $blocks = $page->instance()->getDashboardBlocks();

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]['section'])->toBeNull()
        ->and(array_column($blocks[0]['items'], 'label'))->toBe(['Ungrouped Stat'])
        ->and($blocks[1]['section']['label'])->toBe('Overview')
        ->and(array_column($blocks[1]['items'], 'label'))->toBe(['Revenue'])
        ->and($blocks[2]['section']['label'])->toBe('Details')
        ->and($blocks[2]['items'])->toBe([]);

    // Move "Ungrouped Stat" into the "Overview" section via editWidget.
    $ungroupedKey = $blocks[0]['items'][0]['key'];
    $overviewKey = $blocks[1]['section']['key'];

    $page->callAction('editWidget', data: [
        'type' => 'stat',
        'label' => 'Ungrouped Stat',
        'span' => 3,
        'aggregate' => 'count',
        'section_key' => $overviewKey,
    ], arguments: ['key' => $ungroupedKey])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Overview', 'Revenue', 'Ungrouped Stat', 'Details']);

    // Move "Revenue" back outside sections (section_key = null).
    $updatedBlocks = Livewire::test(OrderInsights::class)->instance()->getDashboardBlocks();
    $revenueKey = $updatedBlocks[0]['items'][0]['key'];

    Livewire::test(OrderInsights::class)
        ->callAction('editWidget', data: [
            'type' => 'stat',
            'label' => 'Revenue',
            'span' => 3,
            'aggregate' => 'count',
            'section_key' => null,
        ], arguments: ['key' => $revenueKey])
        ->assertHasNoActionErrors();

    expect(savedLabels())->toBe(['Revenue', 'Overview', 'Ungrouped Stat', 'Details']);
});
