<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\SiteAgentSubscriberResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * אילו טלפונים יכולים לשנות את האתרים של הלקוח הזה.
 *
 * The global subscriber list answers "who uses the product". This answers the
 * question a person actually has while a customer is on the phone — somebody
 * says a page changed and nobody on the team touched it, and the first thing
 * worth knowing is which numbers are allowed to have done that, and which of
 * them are still live.
 *
 * The table is the resource's own, not a copy of it. A second table drifting
 * from the first is how a screen ends up reporting a number as active weeks
 * after the access was taken away.
 */
class SiteAgentSubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'siteAgentSubscribers';

    protected static ?string $title = 'סוכן וואטסאפ לאתר';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    /**
     * Shown only where there is something to show.
     *
     * Every customer page would otherwise carry an empty tab for a product most
     * of them have not bought, which pushes the tabs that do describe them off
     * to the side.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! (auth()->user()?->canAccessModule('management') ?? false)) {
            return false;
        }

        return $ownerRecord->siteAgentSubscribers()->exists();
    }

    // The customer 360° page is a ViewRecord, where relation managers go
    // read-only by default — and the reason this tab is worth opening is the
    // "הסר הרשאה" button on a number that should not have one any more.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return SiteAgentSubscriberResource::table($table)
            // The badge is read off exists-flags that the resource adds in its
            // own query. These rows come from the relationship instead, so the
            // same flags have to be added here or every row reads "אין מנוי".
            ->modifyQueryUsing(fn (Builder $query): Builder => SiteAgentSubscriberResource::withState($query))
            ->recordTitleAttribute('phone')
            // The site is what varies on this screen; the customer never does.
            ->heading('מספרים שמנהלים את האתרים של הלקוח')
            ->emptyStateHeading('אין מספרים מחוברים')
            ->emptyStateDescription('הלקוח אינו משתמש בסוכן הוואטסאפ לניהול האתר.');
    }
}
