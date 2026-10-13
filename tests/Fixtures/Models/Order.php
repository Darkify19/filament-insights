<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $guarded = [];

    protected $hidden = ['internal_code'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'is_paid' => 'boolean',
            'archived' => 'boolean',
            'secret' => 'encrypted',
            'ordered_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
