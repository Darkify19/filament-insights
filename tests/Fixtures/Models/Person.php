<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $guarded = [];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->surname}");
    }
}
