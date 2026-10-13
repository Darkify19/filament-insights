<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Filament;

use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\Order;
use CarlJanzell\FilamentInsights\Tests\Fixtures\Models\OrderStatus;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Two scopes, one at each level an insights query has to respect:
 * the resource hides archived orders, the list table hides void ones.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('archived', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('status')])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Void));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'insights' => OrderInsights::route('/insights'),
        ];
    }
}
