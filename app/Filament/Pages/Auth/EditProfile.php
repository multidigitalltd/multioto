<?php

namespace App\Filament\Pages\Auth;

use App\Support\WebPush;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * The panel's profile screen, plus a "browser notifications" section so a member
 * can turn Web Push on/off on the current device (the control reflects the real
 * browser state). The section is shown only when Web Push is configured.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                Section::make('התראות דפדפן')
                    ->description('קבלת התראה שקופצת על שולחן העבודה על פנייה חדשה ותגובת לקוח — גם כשלשונית הפאנל ברקע. ההגדרה היא לכל דפדפן/מכשיר בנפרד.')
                    ->schema([
                        View::make('webpush.profile-toggle'),
                    ])
                    ->visible(fn (): bool => WebPush::enabled()),
            ]);
    }
}
