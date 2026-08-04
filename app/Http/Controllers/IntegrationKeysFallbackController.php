<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Services\Health\IntegrationHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Classic (non-Livewire) fallback for saving the security/reputation API keys.
 * The Livewire settings page has a history of environment-specific client-side
 * failures (extensions, blocked JS) where clicking "save" silently does nothing;
 * this plain form POST works with nothing but HTML, and doubles as a diagnostic:
 * if this saves and the Livewire button doesn't, the problem is client-side JS.
 *
 * Same rules as the Livewire path: admin only, values trimmed, a value equal to
 * the operator's panel password is rejected as browser autofill, nothing logged
 * but field names.
 */
class IntegrationKeysFallbackController extends Controller
{
    /**
     * The keys this fallback may touch.
     *
     * An explicit list rather than "whatever was posted": this endpoint exists
     * for the moment the page's JavaScript is broken, and a route that writes
     * any setting it is handed would be a much larger door than the problem it
     * solves.
     */
    private const ALLOWED_KEYS = [
        'wpscan_token' => 'security.wpscan_token',
        'safe_browsing_key' => 'security.safe_browsing_key',
        'urlhaus_auth_key' => 'security.urlhaus_auth_key',
        'wordfence_api_key' => 'security.wordfence_api_key',
        'google_client_id' => 'google.client_id',
        'google_client_secret' => 'google.client_secret',
        'google_allowed_domain' => 'google.allowed_domain',
    ];

    public function save(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        $validated = $request->validate([
            'wpscan_token' => ['nullable', 'string', 'max:500'],
            'safe_browsing_key' => ['nullable', 'string', 'max:500'],
            'urlhaus_auth_key' => ['nullable', 'string', 'max:500'],
            'wordfence_api_key' => ['nullable', 'string', 'max:500'],
            'google_client_id' => ['nullable', 'string', 'max:500'],
            'google_client_secret' => ['nullable', 'string', 'max:500'],
            'google_allowed_domain' => ['nullable', 'string', 'max:255'],
            'clear_google_allowed_domain' => ['nullable'],
        ]);

        $saved = [];
        $rejected = false;

        // Emptying the domain fence is an instruction ("any address that is
        // already a user"), and a blank field cannot say it — blank means
        // "leave alone" everywhere else on this form. So it is said explicitly.
        if ($request->boolean('clear_google_allowed_domain')) {
            Setting::put('google.allowed_domain', '');
            $saved[] = 'google.allowed_domain';
        }

        foreach (self::ALLOWED_KEYS as $field => $key) {
            $raw = (string) ($validated[$field] ?? '');
            $value = trim($raw);

            if ($value === '') {
                continue; // Blank = leave the stored value unchanged.
            }

            // Same autofill guard as the Livewire save: never store (or ship to
            // a third party) a value that is actually the panel login password.
            if ($request->user()->enteredOwnPassword($raw)) {
                $rejected = true;

                continue;
            }

            Setting::put($key, $value);
            $saved[] = $key;
        }

        Log::info('IntegrationKeysFallback: save', [
            'saved' => $saved,
            'autofill_rejected' => $rejected,
        ]);

        if ($rejected) {
            return back()->with('integration_status', [
                'variant' => 'danger',
                'text' => 'שדה לא נשמר — הערך שהוזן זהה לסיסמת הכניסה שלך לפאנל (כנראה מילוי אוטומטי של הדפדפן). הדביקו את המפתח האמיתי ונסו שוב.',
            ]);
        }

        if ($saved === []) {
            return back()->with('integration_status', [
                'variant' => 'warning',
                'text' => 'לא הוזן אף ערך — שום שדה לא עודכן. הדביקו מפתח לשדה המתאים ולחצו שמירה.',
            ]);
        }

        $stored = Setting::map();
        $states = collect(self::ALLOWED_KEYS)->map(function (string $key) use ($saved, $stored): string {
            $label = ManageIntegrations::SECRET_LABELS[$key] ?? $key;

            return $label.': '.(in_array($key, $saved, true)
                ? 'עודכן עכשיו ✓'
                : (filled($stored[$key] ?? null) ? 'שמור מקודם' : 'עדיין ריק'));
        });

        return back()->with('integration_status', [
            'variant' => 'success',
            'text' => 'המפתחות נשמרו והוצפנו (בטופס הגיבוי). '.$states->implode(' · ').'. עכשיו לחצו "בדיקת חיבור" לאימות.',
        ]);
    }

    /**
     * Run the security-sources connection test without Livewire — the same
     * IntegrationHealth check the panel button runs, delivered as a redirect +
     * flash so it works when the page's JavaScript is broken.
     */
    public function test(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        Log::info('IntegrationKeysFallback: connection test');

        try {
            $result = app(IntegrationHealth::class)->check('security');
        } catch (\Throwable $e) {
            return back()->with('integration_status', [
                'variant' => 'warning',
                'text' => 'בדיקת החיבור לא הושלמה: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 150),
            ]);
        }

        return back()->with('integration_status', [
            'variant' => $result->ok ? 'success' : 'danger',
            'text' => ($result->ok ? 'החיבור למקורות האבטחה תקין ✓ — ' : 'בדיקת החיבור למקורות האבטחה: ').$result->message,
        ]);
    }
}
