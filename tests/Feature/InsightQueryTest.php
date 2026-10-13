<?php

use CarlJanzell\FilamentInsights\Support\InsightQuery;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\Customer;

it('offers only real, visible columns that suit each role', function () {
    $insights = insights();

    expect($insights->options('numeric'))
        ->toHaveKeys(['total', 'quantity'])
        // Keys and foreign keys are never summed.
        ->not->toHaveKeys(['id', 'customer_id']);

    expect($insights->options('date'))->toHaveKeys(['ordered_at', 'created_at']);

    expect($insights->options('group'))
        ->toHaveKeys(['status', 'customer_id', 'is_paid'])
        // Free text and amounts make one group per row; dates have their own buckets.
        ->not->toHaveKeys(['id', 'notes', 'total', 'ordered_at'])
        ->and($insights->options('group')['customer_id'])->toBe('Customer');

    foreach (['numeric', 'date', 'group'] as $kind) {
        // Hidden, encrypted and page-excluded columns are never offered.
        expect($insights->options($kind))->not->toHaveKeys(['internal_code', 'secret', 'channel']);
    }
});

it('refuses a column that is not offered before it reaches SQL', function (array $config) {
    expect(fn () => insights()->series($config))->toThrow(InvalidArgumentException::class);
})->with([
    'injected sum' => [['aggregate' => 'sum', 'column' => 'total) from users --', 'group_by' => 'status']],
    'encrypted sum' => [['aggregate' => 'sum', 'column' => 'secret', 'group_by' => 'status']],
    'summed key' => [['aggregate' => 'sum', 'column' => 'id', 'group_by' => 'status']],
    'injected group' => [['group_by' => 'status; drop table orders']],
    'hidden group' => [['group_by' => 'internal_code']],
    'excluded group' => [['group_by' => 'channel']],
    'text as date' => [['group_by' => '@day', 'date_column' => 'notes', 'range' => 30]],
]);

it('counts only the records the list page shows', function () {
    order();
    order();
    order(['archived' => true]); // hidden by the resource's getEloquentQuery()
    order(['status' => 'void']); // hidden by the table's modifyQueryUsing()

    expect(insights()->value(['aggregate' => 'count']))->toBe(2.0);
});

it('sums, averages and finds the extremes of a field', function () {
    order(['total' => 100]);
    order(['total' => 250]);
    order(['total' => 50]);

    expect(insights()->value(['aggregate' => 'sum', 'column' => 'total']))->toBe(400.0)
        ->and(round(insights()->value(['aggregate' => 'avg', 'column' => 'total']), 2))->toBe(133.33)
        ->and(insights()->value(['aggregate' => 'min', 'column' => 'total']))->toBe(50.0)
        ->and(insights()->value(['aggregate' => 'max', 'column' => 'total']))->toBe(250.0);
});

it('limits a value to its period and measures the one before it', function () {
    $this->travelTo('2026-03-31 12:00');

    order(['ordered_at' => '2026-03-30']);
    order(['ordered_at' => '2026-03-15']);
    order(['ordered_at' => '2026-02-20']); // previous 30 days
    order(['ordered_at' => '2025-12-01']); // outside both

    $config = ['aggregate' => 'count', 'date_column' => 'ordered_at', 'range' => 30];

    expect(insights()->value($config))->toBe(2.0)
        ->and(insights()->value($config, period: 1))->toBe(1.0)
        ->and(InsightQuery::describe($config))->toBe('Last 30 days')
        ->and(InsightQuery::describe(['aggregate' => 'count']))->toBe('All time');
});

it('labels groups with record titles, enum labels and yes or no', function () {
    $ada = Customer::create(['name' => 'Ada']);
    $lin = Customer::create(['name' => 'Lin']);

    order(['customer_id' => $ada->id, 'is_paid' => true]);
    order(['customer_id' => $ada->id, 'is_paid' => true]);
    order(['customer_id' => $lin->id, 'status' => 'pending']);
    order(['status' => 'pending']);

    $grouped = fn (string $column): array => array_combine(...insights()->series(['group_by' => $column]));

    expect($grouped('customer_id'))->toEqual(['Ada' => 2.0, 'Lin' => 1.0, '(none)' => 1.0])
        ->and($grouped('status'))->toEqual(['Paid' => 2.0, 'Pending' => 2.0])
        ->and($grouped('is_paid'))->toEqual(['Yes' => 2.0, 'No' => 2.0]);
});

it('keeps a top-N chart between 1 and 50 groups', function () {
    order(['quantity' => 1]);
    order(['quantity' => 2]);
    order(['quantity' => 3]);

    expect(insights()->series(['group_by' => 'quantity', 'limit' => 2])[0])->toHaveCount(2)
        ->and(insights()->series(['group_by' => 'quantity', 'limit' => 0])[0])->toHaveCount(1)
        ->and(insights()->series(['group_by' => 'quantity', 'limit' => 999])[0])->toHaveCount(3);
});

it('zero-fills every bucket of a timeline', function () {
    $this->travelTo('2026-03-10 12:00');

    order(['ordered_at' => '2026-03-08 09:00']);
    order(['ordered_at' => '2026-03-08 17:00']);
    order(['ordered_at' => '2026-03-10 08:00']);

    [$labels, $values] = insights()->series(['group_by' => '@day', 'date_column' => 'ordered_at', 'range' => 7]);

    expect($labels)->toBe(['Mar 4', 'Mar 5', 'Mar 6', 'Mar 7', 'Mar 8', 'Mar 9', 'Mar 10'])
        ->and($values)->toBe([0.0, 0.0, 0.0, 0.0, 2.0, 0.0, 1.0]);
});

it('plots only buckets with data when an all-time timeline spans centuries', function () {
    order(['ordered_at' => '0004-01-15']); // a typo'd year
    order(['ordered_at' => '2026-03-01']);

    [$labels] = insights()->series(['group_by' => '@month', 'date_column' => 'ordered_at']);

    expect($labels)->toBe(['Jan 0004', 'Mar 2026']);
});

it('lists every column a widget reads', function () {
    expect(InsightQuery::columnsIn(['column' => 'total', 'date_column' => 'ordered_at', 'group_by' => 'status']))
        ->toBe(['total', 'ordered_at', 'status'])
        ->and(InsightQuery::columnsIn(['group_by' => '@month', 'date_column' => 'ordered_at']))
        ->toBe(['ordered_at']);
});

it('shows money in the plugin currency only when a widget asks for it', function () {
    expect(InsightQuery::currencyFor(['format' => 'money']))->toBe('PHP')
        ->and(InsightQuery::currencyFor(['format' => 'number']))->toBeNull()
        ->and(InsightQuery::currencyFor([]))->toBeNull();
});
