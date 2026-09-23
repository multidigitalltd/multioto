@php
    /**
     * מה שקורה אחרי התשלום.
     *
     * Three completely different pages wearing one address, and which one it is
     * depends on facts that arrive seconds apart. Cardcom returns the buyer
     * before its webhook reaches us, so "not paid yet" and "did not pay" look
     * identical here and must not be said the same way — the first is a page
     * that refreshes, the second is a page with a way to try again.
     *
     * Once it IS paid this is the only place the connection codes are shown, so
     * the address has to keep working: it is printed on the page and mailed, and
     * a buyer who closed the tab is not a buyer who lost their purchase.
     */
    $paid = $order->isFulfilled();
@endphp
    <!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>סוכן האתר — {{ $paid ? 'הפעלה' : 'סטטוס ההזמנה' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #2b2b30; --card: #34343a; --fg: #f4f5f7; --muted: #b4bac4;
            --border: #4a4a52; --field: #e9e4dd; --field-fg: #16181d;
            --brand: #ec4899; --brand-fg: #ffffff; --error: #ff8a8a; --ok: #4ade80;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main { background: var(--card); width: 100%; max-width: 42rem; border-radius: 16px;
               padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 32px rgb(0 0 0 / .3); }
        h1 { font-size: clamp(1.4rem, 5vw, 1.9rem); margin: 0 0 .25rem; }
        h2 { font-size: 1.05rem; margin: 1.75rem 0 .5rem; }
        p.lead { color: var(--muted); margin: 0 0 1.25rem; }
        ol { padding-inline-start: 1.3rem; }
        ol li { margin-bottom: .9rem; }
        .codes { display: grid; gap: .75rem; margin: .5rem 0 0; }
        .code { border: 1px solid var(--border); border-radius: 10px; padding: .6rem .8rem;
                background: rgb(0 0 0 / .18); }
        .code .k { font-size: .85rem; color: var(--muted); display: block; }
        .code .v { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
                   font-size: .95rem; word-break: break-all; display: block; direction: ltr;
                   text-align: left; user-select: all; }
        .ok { background: rgb(74 222 128 / .12); border: 1px solid var(--ok);
              border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .warn { background: rgb(255 138 138 / .12); border: 1px solid var(--error);
                border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .btn { display: block; width: 100%; text-align: center; padding: .9rem 1rem; border: 0;
               border-radius: 10px; font: inherit; font-weight: 700; background: var(--brand);
               color: var(--brand-fg); cursor: pointer; text-decoration: none; margin-top: 1rem; }
        .btn:hover { filter: brightness(1.08); }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        input[type=text], input[type=datetime-local], textarea {
            width: 100%; padding: .7rem .8rem; border-radius: 10px; border: 1px solid var(--border);
            background: var(--field); color: var(--field-fg); font: inherit;
        }
        textarea { min-height: 5rem; resize: vertical; }
        input:focus-visible, button:focus-visible, a:focus-visible, textarea:focus-visible {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .hint { color: var(--muted); font-size: .9rem; margin-top: .3rem; }
        .error { color: var(--error); font-size: .9rem; margin-top: .3rem; }
        .opt { display: flex; gap: .7rem; align-items: flex-start; border: 1px solid var(--border);
               border-radius: 12px; padding: .8rem 1rem; margin-bottom: .6rem; cursor: pointer; }
        .opt input { margin-top: .35rem; width: 1.15rem; height: 1.15rem; flex: none; }
        .opt:has(input:checked) { border-color: var(--brand); background: rgb(236 72 153 / .08); }
        .foot { color: var(--muted); font-size: .9rem; margin-top: 1.5rem; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
<main>

    @if (! $paid)
        <h1>ההזמנה נקלטה</h1>
        {{-- Never "the payment failed". The webhook is usually seconds behind the
             browser, and telling a customer who just paid that they did not is
             how a completed sale becomes a support call and a chargeback. --}}
        <p class="lead">
            אנחנו ממתינים לאישור מחברת הסליקה. זה לוקח בדרך כלל כמה שניות —
            רעננו את העמוד בעוד רגע.
        </p>
        <div class="warn">
            <strong>אם שילמתם:</strong> השירות ייפתח מעצמו והוראות ההפעלה יישלחו לאימייל
            {{ $order->buyer_email }}. אין צורך לשלם שוב.<br>
            <strong>אם ביטלתם או שהתשלום נדחה:</strong> אפשר להתחיל מחדש מעמוד הרכישה.
        </div>
        <p class="lead">שמרו את הקישור הזה — הוא הכתובת של ההזמנה שלכם:</p>
        <div class="code"><span class="v">{{ route('store.agent.done', ['reference' => $order->reference]) }}</span></div>
        <a class="btn" href="{{ route('store.agent') }}">חזרה לעמוד הרכישה</a>

    @else
        <h1>השירות פעיל 🎉</h1>
        <p class="lead">
            סוכן האתר הופעל עבור <strong dir="ltr">{{ $order->domain }}</strong>.
        </p>

        <div class="ok">
            <strong>עכשיו בדקו את הוואטסאפ במספר {{ $order->manager_phone }}</strong> —
            שלחנו לשם קוד בן 6 ספרות. השיבו עליו באותה שיחה, וזהו: המספר מאומת.
        </div>

        @if (session('handover'))
            <div class="ok">{{ session('handover') }}</div>
        @endif

        @if ($order->wantsUsToInstall() && ! ($order->installation?->hasAccess() ?? false) && ! ($order->installation?->isClosed() ?? false))
            <h2>נשאר רק לתת לנו גישה</h2>
            <p class="lead">
                ביקשתם שנתקין עבורכם. כדי להתקין את התוסף באתר אנחנו צריכים גישת מנהל אליו.
            </p>

            {{-- The recommended option is first and it is the weaker one on
                 purpose: a temporary link expires by itself and can be revoked
                 without changing anything the customer uses. Asking for their own
                 password when a safer option exists is asking for more than the
                 job needs. --}}
            <p class="lead">
                <strong>הדרך המומלצת:</strong> התקינו באתר תוסף של התחברות זמנית
                (למשל "Temporary Login Without Password"), צרו קישור למנהל ל־7 ימים, והדביקו אותו כאן.
                הקישור פג מעצמו ואפשר לבטל אותו בכל רגע.
            </p>

            @if ($errors->any())
                <div class="warn" role="alert">
                    <ul style="margin:0;padding-inline-start:1.1rem">
                        @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('store.agent.access', ['reference' => $order->reference]) }}" novalidate>
                @csrf

                <label class="opt" for="m-temp" style="font-weight:400">
                    <input type="radio" name="access_method" id="m-temp" value="temp_login" required
                           @checked(old('access_method', 'temp_login') === 'temp_login')>
                    <span>
                        <strong>קישור התחברות זמני</strong><br>
                        <span class="hint">מומלץ. פג מעצמו, ואפשר לבטל בכל רגע.</span>
                    </span>
                </label>

                <label class="opt" for="m-cred" style="font-weight:400">
                    <input type="radio" name="access_method" id="m-cred" value="credentials"
                           @checked(old('access_method') === 'credentials')>
                    <span>
                        <strong>שם משתמש וסיסמה</strong><br>
                        <span class="hint">פִתחו משתמש מנהל חדש במיוחד עבורנו — לא את המשתמש שלכם.</span>
                    </span>
                </label>

                <label for="access_secret">פרטי הגישה <span aria-hidden="true">*</span></label>
                <textarea id="access_secret" name="access_secret" required dir="ltr"
                          aria-describedby="secret-hint @error('access_secret') secret-error @enderror"></textarea>
                {{-- Not repopulated from old() anywhere on this page, deliberately:
                     a validation error must not paint a customer's admin password
                     back onto a screen that may be shared, cached or screenshotted. --}}
                <p class="hint" id="secret-hint">
                    הדביקו כאן את הקישור הזמני, או את שם המשתמש והסיסמה. הפרטים נשמרים מוצפנים,
                    נחשפים רק לאיש הצוות שמבצע את ההתקנה, ונמחקים אצלנו מיד בסיומה.
                </p>
                @error('access_secret')<p class="error" id="secret-error">{{ $message }}</p>@enderror

                <label for="access_expires_at">עד מתי הגישה בתוקף</label>
                <input id="access_expires_at" type="datetime-local" name="access_expires_at"
                       value="{{ old('access_expires_at') }}">
                <p class="hint">אופציונלי. נמחק אצלנו גם אם נשכח — לא נשמור גישה שפג תוקפה.</p>
                @error('access_expires_at')<p class="error">{{ $message }}</p>@enderror

                <label for="access_note">הערה לצוות</label>
                <input id="access_note" type="text" name="access_note" maxlength="500" value="{{ old('access_note') }}">
                <p class="hint">למשל: כתובת כניסה לא רגילה, או שעה שנוחה לכם. אל תכתבו כאן סיסמאות.</p>

                <button class="btn" type="submit">שליחת הגישה</button>
            </form>

        @elseif ($order->wantsUsToInstall())
            <h2>ההתקנה אצלנו</h2>
            <div class="ok">
                קיבלנו את הגישה. נתקין את התוסף ונעדכן אתכם — בדרך כלל באותו יום עסקים.
                אין צורך לעשות דבר נוסף.
            </div>
        @endif

        @if ($codes !== null && ! $order->wantsUsToInstall())
            <h2>התקנת התוסף — 3 שלבים</h2>
            <ol>
                <li>
                    הורידו את קובץ התוסף והתקינו אותו באתר:
                    <strong>תוספים ← הוסף תוסף ← העלאת תוסף</strong>, ואז <strong>הפעל</strong>.
                    <a class="btn" href="{{ route('store.agent.plugin', ['reference' => $order->reference]) }}">הורדת קובץ התוסף</a>
                </li>
                <li>
                    בתפריט וורדפרס: <strong>הגדרות ← Multioto</strong>, והדביקו את שלושת הערכים:
                    <div class="codes">
                        <div class="code"><span class="k">כתובת הפאנל</span><span class="v">{{ $codes['panel_url'] }}</span></div>
                        <div class="code"><span class="k">מפתח MCP</span><span class="v">{{ $codes['mcp_secret'] }}</span></div>
                        <div class="code"><span class="k">טוקן עדכונים</span><span class="v">{{ $codes['update_token'] }}</span></div>
                    </div>
                </li>
                <li>
                    שמרו. זהו — שלחו הודעה לוואטסאפ ותראו שהסוכן עונה.
                </li>
            </ol>

            <div class="warn">
                שלושת הערכים האלה הם המפתחות לאתר שלכם. אל תשלחו אותם באימייל ואל תפרסמו אותם —
                מי שמחזיק בהם יכול לשנות את האתר.
            </div>
        @endif

        <h2>הקישור הזה</h2>
        <p class="lead">
            שמרו אותו. זה העמוד שבו נמצאים קודי ההתקנה, ואפשר לחזור אליו בכל עת:
        </p>
        <div class="code"><span class="v">{{ route('store.agent.done', ['reference' => $order->reference]) }}</span></div>

        <p class="foot">
            לניהול המנוי, להוספת מספר נוסף ולחשבוניות —
            <a href="{{ route('portal.login') }}">האזור האישי</a>, עם הכתובת {{ $order->buyer_email }}.
        </p>
    @endif

</main>
</body>
</html>
