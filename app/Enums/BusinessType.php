<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BusinessType: string implements HasLabel
{
    case ExemptDealer = 'exempt_dealer';
    case LicensedDealer = 'licensed_dealer';
    case Company = 'company';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
