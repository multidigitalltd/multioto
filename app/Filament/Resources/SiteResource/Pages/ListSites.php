<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Services\Cloudflare\CloudflareClient;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->countryRuleAction(),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * A Cloudflare country rule applied to EVERY zone at once — the team's rules
     * overlap, so one change covers all sites. Uses the saved Cloudflare token
     * (or a one-time token typed here). Admin-only.
     */
    private function countryRuleAction(): Actions\Action
    {
        return Actions\Action::make('countryRule')
            ->label('כלל מדינה ב-Cloudflare')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading('כלל מדינה ב-Cloudflare — לכל האתרים')
            ->modalDescription('הכלל יוחל על כל הזונים בחשבון ה-Cloudflare בבת אחת (IP Access Rule לפי מדינה). נדרשת הרשאת Firewall Services · Edit בטוקן.')
            ->modalSubmitActionLabel('החל על כל האתרים')
            ->form([
                Forms\Components\TextInput::make('country')
                    ->label('קוד מדינה (ISO, שתי אותיות)')
                    ->required()->maxLength(2)->placeholder('US')
                    ->helperText('למשל US, RU, CN, IL. אות גדולה/קטנה לא משנה.'),
                Forms\Components\Select::make('mode')
                    ->label('פעולה')->required()->native(false)->default('managed_challenge')
                    ->options([
                        'managed_challenge' => 'אתגר גישה מנוהל (Managed Challenge)',
                        'js_challenge' => 'אתגר JavaScript',
                        'block' => 'חסימה',
                        'whitelist' => 'מעבר חופשי (Allow)',
                        'remove' => 'הסרת הכלל',
                    ]),
                Forms\Components\TextInput::make('api_token')
                    ->label('Cloudflare API Token')->password()->autocomplete('new-password')
                    ->required(fn (): bool => blank(config('billing.cloudflare.api_token')))
                    ->helperText(filled(config('billing.cloudflare.api_token'))
                        ? 'קיים טוקן שמור בהגדרות — השאירו ריק כדי להשתמש בו.'
                        : 'טוקן עם הרשאת Zone·Read + Firewall Services·Edit. אפשר גם לשמור אותו בהגדרות ← אינטגרציות.'),
            ])
            ->action(function (array $data): void {
                $token = trim((string) ($data['api_token'] ?? '')) ?: trim((string) config('billing.cloudflare.api_token'));

                $result = app(CloudflareClient::class)->applyCountryRuleEverywhere(
                    $token,
                    (string) ($data['country'] ?? ''),
                    (string) ($data['mode'] ?? ''),
                    'Multi Digital — country rule',
                );

                Notification::make()
                    ->title('כללי מדינה ב-Cloudflare')
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }
}
