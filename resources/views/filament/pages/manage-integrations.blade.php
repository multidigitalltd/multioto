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
        <x-slot name="heading">טופס גיבוי — שמירת מפתחות אבטחה (עובד גם בלי JavaScript)</x-slot>
        <x-slot name="description">אם כפתור השמירה למעלה לא מגיב, שמרו כאן: טופס רגיל ששולח ישירות לשרת. שדה ריק לא משנה ערך קיים.</x-slot>

        <form method="POST" action="{{ route('integrations.security-keys.fallback') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf

            <div>
                <label for="fb-urlhaus" class="mb-1 block text-sm font-medium">abuse.ch Auth-Key (URLhaus)</label>
                <input id="fb-urlhaus" name="urlhaus_auth_key" type="text" autocomplete="off" spellcheck="false"
                       data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                       class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800"
                       aria-describedby="fb-urlhaus-help">
                <p id="fb-urlhaus-help" class="mt-1 text-xs text-gray-500 dark:text-gray-400">מפתח חינמי מ-auth.abuse.ch.</p>
            </div>

            <div>
                <label for="fb-sb" class="mb-1 block text-sm font-medium">Google Safe Browsing API Key</label>
                <input id="fb-sb" name="safe_browsing_key" type="text" autocomplete="off" spellcheck="false"
                       data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                       class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
            </div>

            <div>
                <label for="fb-wpscan" class="mb-1 block text-sm font-medium">WPScan API Token</label>
                <input id="fb-wpscan" name="wpscan_token" type="text" autocomplete="off" spellcheck="false"
                       data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                       class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
            </div>

            <div class="sm:col-span-3">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    שמירת המפתחות (טופס גיבוי)
                </x-filament::button>
            </div>
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
