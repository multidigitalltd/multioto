<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BusinessType: string implements HasLabel
{
    case ExemptDealer = 'exempt_dealer';
    case LicensedDealer = 'licensed_dealer';
    case Company = 'company';
    case Nonprofit = 'nonprofit';

    public function getLabel(): string
    {
        return match ($this) {
            self::ExemptDealer => 'עוסק פטור',
            self::LicensedDealer => 'עוסק מורשה',
            self::Company => 'חברה בע״מ',
            self::Nonprofit => 'עמותה (ע.ר.)',
        };
    }
}
