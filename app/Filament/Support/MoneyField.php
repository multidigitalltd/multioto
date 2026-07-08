<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * A shekel money input bound to an integer-agorot column.
 *
 * Money is stored as integer agorot (architecture rule), but the operator
 * enters and sees plain shekels with up to two decimals — e.g. 1.50 = שקל וחצי,
 * 1.90 = שקל ותשעים אגורות. Conversion happens only at the form boundary:
 * agorot → shekels when the field loads, shekels → agorot (rounded int) on save.
 */
class MoneyField
{
    /**
     * @param  string  $agorotColumn  the underlying *_agorot column name
     */
    public static function make(string $agorotColumn, string $label): TextInput
    {
        return TextInput::make($agorotColumn)
            ->label($label)
            ->numeric()
            ->prefix('₪')
            ->step('0.01')
            ->minValue(0)
            ->inputMode('decimal')
            // Stored agorot → shekels for display (e.g. 190 → "1.90").
            ->formatStateUsing(fn ($state) => filled($state) ? number_format(((int) $state) / 100, 2, '.', '') : $state)
            // Shekels → integer agorot for storage (e.g. "1.90" → 190).
            ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round(((float) $state) * 100) : $state);
    }
}
