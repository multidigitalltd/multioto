<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VatCategory: string implements HasLabel
{
    case Taxable = 'taxable';
    case Exempt = 'exempt';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
