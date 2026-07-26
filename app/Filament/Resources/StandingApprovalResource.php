<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\StandingApprovalResource\Pages;
use App\Models\StandingApproval;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Management screen for standing ("always approve") automation grants: what
 * runs automatically, how often it was used, and a kill switch per grant.
 * Creation happens only from a concrete proposal ("אשר תמיד") — there is no
 * create form here, so a grant always traces back to a real reviewed action.
 */
class StandingApprovalResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = StandingApproval::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'אישורים קבועים';

    protected static ?string $modelLabel = 'אישור קבוע';

    protected static ?string $pluralModelLabel = 'אישורים קבועים';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 70;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('פעולה')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('action_key')->label('מפתח')->color('gray')->fontFamily('mono'),
                Tables\Columns\ToggleColumn::make('enabled')->label('פעיל'),
                Tables\Columns\TextColumn::make('uses_count')->label('ביצועים אוטומטיים')->sortable(),
                Tables\Columns\TextColumn::make('last_used_at')->label('שימוש אחרון')->dateTime('d/m/Y H:i')->placeholder('טרם')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('נוצר')->dateTime('d/m/Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('בטל לצמיתות')
                    ->modalDescription('האישור הקבוע יימחק ופעולות מסוג זה יחזרו לדרוש אישור ידני.'),
            ])
            ->emptyStateHeading('אין אישורים קבועים')
            ->emptyStateDescription('כשמגיעה הצעת פעולה, אפשר לבחור "אשר תמיד" (בפאנל או בוואטסאפ) — ופעולות מאותו סוג יבוצעו אוטומטית מכאן והלאה.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStandingApprovals::route('/'),
        ];
    }
}
