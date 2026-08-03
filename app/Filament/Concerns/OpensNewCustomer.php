<?php

namespace App\Filament\Concerns;

use App\Models\Customer;
use Closure;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;

/**
 * לפתוח לקוח חדש בתוך הטופס שבו צריך אותו, ולא לפניו.
 *
 * מי שמבקש תשלום מלקוח שעוד אינו במערכת נאלץ אחרת לעצור, לעבור למסך לקוחות,
 * לפתוח כרטיס, לחזור ולהתחיל מהתחלה — וזה בדיוק הרגע שבו מוותרים ושולחים
 * בקשת תשלום בוואטסאפ בלי חשבונית עסקה ובלי מעקב.
 *
 * הלוגיקה יושבת במקום אחד כדי ששני המסכים שפותחים לקוח כך יפתחו אותו אותו הדבר:
 * שני מסכים שכל אחד מהם מחליט לבד מתי כתובת מייל היא לקוח קיים ומתי היא חדש —
 * מייצרים כפילויות בכרטיסי לקוח, וכפילות בכרטיס לקוח היא כפילות בחיובים.
 */
trait OpensNewCustomer
{
    protected function newCustomerToggle(): Toggle
    {
        return Toggle::make('new_customer')
            ->label('לקוח חדש (לא קיים במערכת)')
            ->live()
            ->columnSpanFull();
    }

    /**
     * פרטי הלקוח החדש — נפתחים רק כשהמתג דלוק.
     *
     * מייל וטלפון יכולים להיות חובה לפי ההקשר: דרישת תשלום שנשלחת במייל בלי
     * כתובת מייל אינה נשלחת, ועדיף לומר את זה בטופס מאשר אחרי שהלקוח כבר נפתח.
     */
    protected function newCustomerFields(Closure|bool $emailRequired = false, Closure|bool $phoneRequired = false): Grid
    {
        return Grid::make(2)
            ->visible(fn (Get $get): bool => (bool) $get('new_customer'))
            ->schema([
                TextInput::make('new_name')->label('שם הלקוח')->maxLength(120)
                    ->required(fn (Get $get): bool => (bool) $get('new_customer')),
                TextInput::make('new_email')->label('אימייל')->email()->maxLength(150)
                    ->required($emailRequired),
                TextInput::make('new_phone')->label('טלפון')->tel()->maxLength(30)
                    ->required($phoneRequired),
                TextInput::make('new_business_number')->label('ח.פ / עוסק')->maxLength(30),
                Toggle::make('new_vat_exempt')->label('פטור ממע״מ'),
            ]);
    }

    /**
     * הלקוח שנבחר, או לקוח חדש שנפתח עכשיו.
     *
     * כתובת מייל שכבר קיימת במערכת מחזירה את הלקוח הקיים במקום לפתוח שני כרטיסים
     * לאותו אדם — מי שממלא טופס אינו יודע, ואינו אמור לדעת, שהוא כבר שם.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCustomer(array $data): ?Customer
    {
        if (empty($data['new_customer'])) {
            return filled($data['customer_id'] ?? null) ? Customer::find($data['customer_id']) : null;
        }

        $name = trim((string) ($data['new_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $email = filled($data['new_email'] ?? null) ? (string) $data['new_email'] : null;

        return ($email ? Customer::where('email', $email)->first() : null)
            ?? Customer::create([
                'name' => $name,
                'email' => $email,
                'phone' => $data['new_phone'] ?? null,
                'business_number' => $data['new_business_number'] ?? null,
                'vat_exempt' => (bool) ($data['new_vat_exempt'] ?? false),
            ]);
    }
}
