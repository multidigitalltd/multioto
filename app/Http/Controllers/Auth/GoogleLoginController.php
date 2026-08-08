<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * התחברות עם גוגל — לחשבונות שכבר קיימים במערכת בלבד.
 *
 * זו ההחלטה היחידה שחשובה כאן, והיא נאמרת מראש: הכניסה הזו **אינה פותחת
 * משתמשים**. לכל אדם בעולם יש חשבון גוגל, וכניסה שפותחת משתמש למי שהצליח
 * להתחבר היא בדיוק דלת פתוחה לפאנל שמנהל כסף של לקוחות. מי שאינו כבר ברשימת
 * המשתמשים מקבל סירוב, גם אם גוגל אישרה אותו בשמחה.
 *
 * כניסה דרך גוגל נחשבת כאן גם כאימות דו-שלבי, ולכן לא נדרש קוד חד-פעמי אחריה.
 * זו החלטה מודעת עם מחיר שכדאי לדעת: גוגל אימתה מי אתה, אך לא בהכרח בשני
 * גורמים — אם חשבון הגוגל עצמו מוגן רק בסיסמה, סיסמת הגוגל היא כעת הדבר היחיד
 * שעומד בין תוקף לפאנל. במילים אחרות, הגורם השני עבר לאחריות של גוגל, וההנחה
 * היא שאימות דו-שלבי מופעל שם על חשבונות הצוות.
 *
 * כניסה בסיסמה מקומית ממשיכה לדרוש קוד כרגיל — שם לא השתנה דבר.
 */
class GoogleLoginController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! self::configured()) {
            return redirect()->route('filament.admin.auth.login')
                ->with('error', 'התחברות עם גוגל אינה מוגדרת. יש למלא את פרטי היישום בהגדרות.');
        }

        return self::google()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! self::configured()) {
            return $this->refuse('התחברות עם גוגל אינה מוגדרת.');
        }

        try {
            $account = self::google()->user();
        } catch (\Throwable $e) {
            Log::warning('google login failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return $this->refuse('ההתחברות מול גוגל לא הושלמה. נסו שוב.');
        }

        $email = mb_strtolower(trim((string) $account->getEmail()));

        // גוגל מוסרת גם כתובות שלא אומתו. כתובת כזו אינה ראיה לכלום — מי
        // שמחזיק בחשבון גוגל יכול לרשום בו כל כתובת, וכניסה לפיה הופכת את
        // רשימת המשתמשים לרשימת הצעות.
        if ($email === '' || ($account->user['email_verified'] ?? false) !== true) {
            return $this->refuse('כתובת המייל בחשבון גוגל אינה מאומתת.');
        }

        if (! self::domainAllowed($email)) {
            return $this->refuse('התחברות עם גוגל מוגבלת לכתובות של הארגון.');
        }

        $user = User::where('email', $email)->first();

        // לא נפתח משתמש. חשבון גוגל תקין הוא הוכחה לזהות, לא הרשאה להיכנס.
        if ($user === null) {
            Log::info('google login refused for an unknown address', ['email' => $email]);

            return $this->refuse('הכתובת אינה רשומה במערכת. יש לבקש ממנהל להוסיף אותה.');
        }

        // הקישור נשמר בפעם הראשונה ונבדק מכאן ואילך: כתובת מייל אפשר להעביר
        // בין חשבונות, מזהה החשבון אצל גוגל — לא.
        $googleId = (string) $account->getId();

        if (filled($user->google_id) && $user->google_id !== $googleId) {
            Log::warning('google login refused — the address is linked to another google account', ['user' => $user->id]);

            return $this->refuse('הכתובת מקושרת לחשבון גוגל אחר.');
        }

        if (blank($user->google_id)) {
            $user->forceFill(['google_id' => $googleId])->save();
        }

        AuditLog::record(
            'auth.google.login',
            'התחברות עם גוגל'.($user->requiresTwoFactor() ? ' (במקום קוד חד-פעמי)' : ''),
            actor: $user,
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // גוגל היא כאן הגורם המאמת, ולכן אין קוד חד-פעמי אחריה. נכתב אחרי
        // regenerate כדי שהסימון ישב על המושב החדש ולא על זה שהוחלף.
        $request->session()->put('two_factor.confirmed', true);

        return redirect()->intended(route('filament.admin.pages.dashboard'));
    }

    /**
     * סירוב אחיד — בלי לרמוז מי רשום במערכת ומי לא.
     *
     * מי שיצא לגוגל ממסך האימות הדו-שלבי חוזר לשם ולא למסך ההתחברות: הוא כבר
     * מחובר בסיסמה, ומסך ההתחברות יחזיר אותו הלאה בגלגול הפניות שבסופו הסיבה
     * לסירוב כבר לא קיימת בסשן — כלומר מסך שלא אומר דבר. הוא אינו מנותק כאן:
     * ניסיון כניסה שנכשל בגוגל אינו סיבה לבטל התחברות תקפה שכבר קיימת.
     */
    private function refuse(string $reason): RedirectResponse
    {
        $user = Auth::user();

        $midTwoFactor = $user !== null
            && $user->requiresTwoFactor()
            && ! session()->get('two_factor.confirmed', false);

        return redirect()
            ->route($midTwoFactor ? 'two-factor.challenge' : 'filament.admin.auth.login')
            ->with('error', $reason);
    }

    /**
     * הדרייבר, עם כתובת החזרה שנגזרת מהנתיב שבאמת מקבל אותה.
     *
     * כתובת שנכתבת ביד בשני מקומות מתפצלת, והתסמין הוא שגיאת OAuth שאיש אינו
     * יודע לקרוא.
     */
    private static function google(): GoogleProvider
    {
        return Socialite::driver('google')->redirectUrl(route('auth.google.callback'));
    }

    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * הגבלה אופציונלית לדומיין של הארגון.
     *
     * ריק פירושו "כל כתובת שכבר רשומה כמשתמש" — וזו כבר הגבלה אמיתית, כי
     * הרשימה הזו נשלטת על ידכם.
     */
    private static function domainAllowed(string $email): bool
    {
        $domain = mb_strtolower(trim((string) config('auth.google.allowed_domain')));

        return $domain === '' || str_ends_with($email, '@'.$domain);
    }
}
