<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CustomerResource;
use App\Services\Customers\DuplicateFinder;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * כרטיסים כפולים — לקוחות שנפתחו פעמיים.
 *
 * המיזוג קיים כבר, אבל שום דבר לא הצביע על מה למזג: את הכפילויות מצאו רק
 * כשנתקלו בהן. כרטיס שנפתח פעמיים אינו נדיר — הרשמה תחת כתובת פרטית וחשבונית
 * תחת החברה, טלפון שנקלט לפני מייל — וכל חצי אוסף מאז היסטוריה משלו.
 *
 * מזהים בלבד, לעולם לא שמות: שני עסקים ששניהם נקראים "מספרה" אינם אותו לקוח,
 * ורשימה שצועקת כפילות על כל מילה משותפת היא רשימה שאיש לא פותח פעמיים.
 */
class DuplicateCustomers extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'כרטיסים כפולים';

    protected static ?string $title = 'כרטיסים כפולים — לקוחות שנפתחו פעמיים';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.duplicate-customers';

    /** Amber count in the nav — nothing when there is nothing to merge. */
    public static function getNavigationBadge(): ?string
    {
        $count = self::groups()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, array{reason: string, value: string, customers: Collection}> */
    public function duplicates(): Collection
    {
        return self::groups();
    }

    /** The link that opens the surviving card, where the merge button lives. */
    public function cardUrl(int $customerId): string
    {
        return CustomerResource::getUrl('view', ['record' => $customerId]);
    }

    /**
     * Asked fresh each time. A static memo would survive the request under a
     * long-running worker and keep reporting duplicates that were merged an
     * hour ago — a stale answer about data whose whole point is that it changed.
     *
     * @return Collection<int, array{reason: string, value: string, customers: Collection}>
     */
    private static function groups(): Collection
    {
        return app(DuplicateFinder::class)->groups();
    }
}
