<?php

use CarlJanzell\FilamentInsights\Models\InsightDashboard;
use CarlJanzell\FilamentInsights\Support\InsightQuery;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderInsights;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Filament\OrderResource;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\Order;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\User;
use CarlJanzell\FilamentInsights\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function order(array $attributes = []): Order
{
    return Order::create(['status' => 'paid', ...$attributes]);
}

function signIn(bool $admin = true): User
{
    $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret', 'is_admin' => $admin]);

    test()->actingAs($user);

    return $user;
}

function insights(): InsightQuery
{
    return new InsightQuery(OrderResource::class, OrderInsights::getExcludedColumns());
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function statWidget(string $label, array $config = []): array
{
    return ['type' => 'stat', 'label' => $label, 'span' => 3, 'aggregate' => 'count', ...$config];
}

/**
 * @param  list<array<string, mixed>>  $widgets
 */
function dashboard(array $widgets): void
{
    InsightDashboard::query()->updateOrCreate(['key' => OrderInsights::class], ['widgets' => $widgets]);
}

/**
 * @return list<string>
 */
function savedLabels(): array
{
    return array_column(InsightDashboard::query()->where('key', OrderInsights::class)->value('widgets') ?? [], 'label');
}
