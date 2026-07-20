<?php

namespace Tests\Feature;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Tests\TestCase;

/**
 * Every panel date/time picker opens Sunday-first with Hebrew names — the
 * Israeli work week — configured globally in AppServiceProvider::boot().
 */
class DatePickerLocaleTest extends TestCase
{
    public function test_date_time_pickers_start_on_sunday_in_hebrew(): void
    {
        $picker = DateTimePicker::make('due_at');

        // native(false) is essential: the native HTML input ignores these.
        $this->assertFalse($picker->isNative());
        $this->assertSame(7, $picker->getFirstDayOfWeek()); // 7 = Sunday (ISO)
        $this->assertSame('he', $picker->getLocale());
    }

    public function test_date_pickers_start_on_sunday_in_hebrew(): void
    {
        $picker = DatePicker::make('starts_on');

        $this->assertFalse($picker->isNative());
        $this->assertSame(7, $picker->getFirstDayOfWeek());
        $this->assertSame('he', $picker->getLocale());
    }
}
