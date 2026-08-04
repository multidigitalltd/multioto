<x-filament-panels::page>
    {{-- Outcome of the classic fallback save (redirect + session flash). --}}
    @if (($flash = session('integration_status')) !== null)
        @php
            $flashClasses = match ($flash['variant'] ?? 'success') {
                'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
                'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
                default => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400',
            };
        @endphp
        <div role="status" class="rounded-lg px-4 py-3 text-sm font-medium ring-1 ring-inset {{ $flashClasses }}">
            {{ $flash['text'] ?? '' }}
        </div>
    @endif

    {{-- Removed the moment Livewire boots. If it stays visible, the page's
         JavaScript never loaded — which is exactly why buttons "do nothing". --}}
    <div id="lw-health-warning" class="rounded-lg bg-warning-50 px-4 py-3 text-sm font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400" role="status">
        אם ההודעה הזו נשארת על המסך — ה-JavaScript של העמוד (Livewire) לא נטען, ולכן כפתורי השמירה לא מגיבים.
        נסו לכבות תוספי דפדפן (חוסמי פרסומות/סקריפטים), לרענן עם Ctrl+F5, או להשתמש בטופס הגיבוי שבתחתית העמוד — הוא עובד גם בלי JavaScript.
    </div>
    <script>
        document.addEventListener('livewire:init', function () {
            document.getElementById('lw-health-warning')?.remove();
        });
    </script>

    @if ($statusText)
        @php
            $variantClasses = match ($statusVariant) {
                'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400',
                'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
                default => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
            };
        @endphp
        <div
            role="status"
            aria-live="polite"
            wire:key="integration-status"
            x-data="{ show() { $el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' }) } }"
            x-init="show()"
            x-on:scroll-to-integration-status.window="show()"
            class="rounded-lg px-4 py-3 text-sm font-medium ring-1 ring-inset {{ $variantClasses }}"
        >
            {{ $statusText }}
        </div>
    @endif

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    {{-- Fallback save that needs nothing but HTML: a classic form POST. Saves
         the security keys even when Livewire is broken client-side. --}}
    {{-- NOT collapsible: expanding needs Alpine, and this form exists exactly
         for the case where the page's JavaScript is broken. --}}
    <x-filament::section class="mt-6" icon="heroicon-o-lifebuoy">
        <x-slot name="heading">טופס גיבוי — שמירת מפתחות (עובד גם בלי JavaScript)</x-slot>
        <x-slot name="description">אם כפתור השמירה למעלה לא מגיב, שמרו כאן: טופס רגיל ששולח ישירות לשרת. שדה ריק לא משנה ערך קיים.</x-slot>

        @php
            $fallbackFields = [
                ['name' => 'urlhaus_auth_key', 'label' => 'abuse.ch Auth-Key (URLhaus)', 'help' => 'מפתח חינמי מ-auth.abuse.ch.'],
                ['name' => 'wordfence_api_key', 'label' => 'Wordfence Intelligence API Key', 'help' => 'נדרש לפיד הפגיעויות: wordfence.com → Wordfence Intelligence → API Keys.'],
                ['name' => 'safe_browsing_key', 'label' => 'Google Safe Browsing API Key', 'help' => null],
                ['name' => 'wpscan_token', 'label' => 'WPScan API Token', 'help' => null],
                ['name' => 'google_client_id', 'label' => 'גוגל — Client ID', 'help' => 'מסתיים ב-apps.googleusercontent.com.', 'plain' => true],
                ['name' => 'google_client_secret', 'label' => 'גוגל — Client Secret', 'help' => null],
                ['name' => 'google_allowed_domain', 'label' => 'גוגל — הגבלה לדומיין (אופציונלי)', 'help' => 'ריק = לא משנה את הקיים. לביטול ההגבלה — סמנו את התיבה שמתחת.', 'plain' => true],
            ];
        @endphp

        <form method="POST" action="{{ route('integrations.security-keys.fallback') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf

            @foreach ($fallbackFields as $field)
                <div>
                    <label for="fb-{{ $field['name'] }}" class="mb-1 block text-sm font-medium">{{ $field['label'] }}</label>
                    <input id="fb-{{ $field['name'] }}" name="{{ $field['name'] }}" type="text"
                           @unless ($field['plain'] ?? false) style="-webkit-text-security: disc" @endunless
                           autocomplete="off" spellcheck="false"
                           data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                           class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800"
                           @if ($field['help']) aria-describedby="fb-{{ $field['name'] }}-help" @endif>
                    @if ($field['help'])
                        <p id="fb-{{ $field['name'] }}-help" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $field['help'] }}</p>
                    @endif
                </div>
            @endforeach

            <div class="sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="clear_google_allowed_domain" value="1"
                           class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                    לבטל את הגבלת הדומיין בהתחברות עם גוגל (לאפשר לכל כתובת שכבר רשומה כמשתמש)
                </label>
            </div>

            <div class="sm:col-span-2">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    שמירת המפתחות (טופס גיבוי)
                </x-filament::button>
            </div>
        </form>

        {{-- The connection test, also without Livewire: a separate classic form
             so it works when the panel's "בדיקת חיבור" button does not respond. --}}
        <form method="POST" action="{{ route('integrations.security-keys.test') }}" class="mt-4">
            @csrf
            <x-filament::button type="submit" color="gray" icon="heroicon-o-signal">
                בדיקת חיבור למקורות האבטחה (טופס גיבוי)
            </x-filament::button>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">בודק את URLhaus / Spamhaus / Safe Browsing / פיד הפגיעויות מול המפתחות השמורים ומציג שורה לכל מקור.</p>
        </form>
    </x-filament::section>

    <x-filament::section class="mt-6" icon="heroicon-o-lock-closed">
        <x-slot name="heading">אבטחה</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            כל המפתחות נשמרים <strong>מוצפנים</strong> במסד הנתונים ואינם מוצגים חזרה בטופס.
            לכל אינטגרציה כפתור שמירה משלה — שמירה של ספק אחד לא נוגעת באחרים.
            שדה שנשאר ריק לא משנה את הערך הקיים. ניתן להמשיך ולהגדיר מפתחות גם דרך קובץ ה-<code>.env</code> —
            ערך שמוזן כאן גובר עליו. עם השמירה, המערכת בודקת אוטומטית שהחיבור לספק תקין ומציגה את התוצאה.
        </p>
    </x-filament::section>
</x-filament-panels::page>
