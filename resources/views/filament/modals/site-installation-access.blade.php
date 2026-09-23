{{--
    פרטי הגישה של הלקוח, מוצגים פעם אחת ביודעין.

    Shown only inside the confirmation modal, never on the table, and never in
    an audit payload. The wording around it is part of the control: somebody who
    knows this value is wiped at the end of the install is somebody who does not
    paste it into a note "just in case".
--}}
<div class="space-y-3 text-sm">
    <div>
        <div class="text-gray-500 dark:text-gray-400">אתר</div>
        <div dir="ltr" class="text-end font-mono">{{ $installation->domain }}</div>
    </div>

    <div>
        <div class="text-gray-500 dark:text-gray-400">סוג הגישה</div>
        <div>
            {{ $installation->access_method === \App\Models\SiteInstallation::ACCESS_TEMP_LOGIN
                ? 'קישור התחברות זמני'
                : 'שם משתמש וסיסמה' }}
        </div>
    </div>

    <div>
        <div class="text-gray-500 dark:text-gray-400">הפרטים</div>
        {{-- select-all so it is copied in one gesture rather than dragged over
             and half-copied, which is how a password becomes a failed login and
             a second reveal. --}}
        <div dir="ltr"
             class="text-start font-mono whitespace-pre-wrap break-all select-all rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">{{ $installation->access_secret }}</div>
    </div>

    @if (filled($installation->access_note))
        <div>
            <div class="text-gray-500 dark:text-gray-400">הערה מהלקוח</div>
            <div>{{ $installation->access_note }}</div>
        </div>
    @endif

    @if ($installation->access_expires_at !== null)
        <div @class(['text-danger-600 dark:text-danger-400' => $installation->accessExpired()])>
            <div class="text-gray-500 dark:text-gray-400">תוקף</div>
            <div>
                {{ $installation->access_expires_at->format('d/m/Y H:i') }}
                @if ($installation->accessExpired())
                    — פג. סביר שהגישה כבר אינה עובדת; בקשו מהלקוח קישור חדש.
                @endif
            </div>
        </div>
    @endif
</div>
