<?php

namespace App\Filament\Pages\Auth;

use App\Support\WebPush;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\HtmlString;

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
                        View::make('webpush.profile-toggle')
                            ->visible(fn (): bool => WebPush::enabled()),
                        // Unconfigured, the toggle used to vanish and the section
                        // with it — so somebody waiting for notifications that
                        // never came had nothing on screen to explain why. The
                        // one-time server step is named here instead.
                        Placeholder::make('push_not_configured')
                            ->hiddenLabel()
                            ->visible(fn (): bool => ! WebPush::enabled())
                            ->content(fn (): HtmlString => new HtmlString(
                                '<p class="text-sm text-gray-600 dark:text-gray-300">התראות דפדפן אינן מופעלות בשרת הזה עדיין, ולכן אין כאן מה להפעיל.</p>'
                                .'<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">הפעלה חד-פעמית בשרת (מנהל מערכת):</p>'
                                .'<pre class="mt-1 overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-xs" dir="ltr">bash docker/setup-push.sh</pre>'
                            )),
                    ]),
            ]);
    }
}
