<?php

namespace CarlJanzell\FilamentInsights\Tests\Fixtures\Models;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Void = 'void';

    public function getLabel(): ?string
    {
        return ucfirst($this->value);
    }
}
