<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasLabel
{
    case TaxInvoiceReceipt = 'tax_invoice_receipt';
    case Receipt = 'receipt';
    case Invoice = 'invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::TaxInvoiceReceipt => 'חשבונית מס/קבלה',
            self::Receipt => 'קבלה',
            self::Invoice => 'חשבונית מס',
        };
    }
}
