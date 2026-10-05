@php
    /**
     * מדריך ההתקנה של תוסף הסוכן — אותו מדריך בכל מקום שלקוח מקבל את הקודים.
     *
     * One partial for the page after payment and for the personal area, so the
     * two can never drift: the page after payment used to name a settings menu
     * the plugin does not have ("Multioto"), and a customer who cannot find the
     * menu the guide names concludes the installation is broken.
     *
     * Expects:
     *   $codes        ['panel_url', 'mcp_secret', 'update_token'] — the keys to the site
     *   $downloadUrl  where the plugin zip is downloaded from
     *   $checkSlot    optional HTML-free string: what to do after saving (varies by page)
     *   $helpUrl      optional link for "we'll do it for you"
     *
     * Self-contained styles under .install-guide, so it reads the same inside
     * either page's own stylesheet, light or dark, RTL.
     */
    $checkSlot ??= 'שלחו הודעה לבוט בוואטסאפ ותראו שהוא עונה.';
    $helpUrl ??= null;
@endphp

<section class="install-guide" aria-labelledby="install-guide-title">
    <h2 id="install-guide-title">התקנת התוסף וחיבור האתר — 4 שלבים</h2>
    <p class="ig-lead">כ־5 דקות. צריך משתמש מנהל בוורדפרס של האתר.</p>

    <ol class="ig-steps">
        <li>
            <strong>מורידים את התוסף.</strong>
            <a class="ig-btn" href="{{ $downloadUrl }}">הורדת קובץ התוסף (ZIP)</a>
            <span class="ig-hint">אל תפתחו את קובץ ה־ZIP — וורדפרס מתקין אותו כמו שהוא.</span>
        </li>

        <li>
            <strong>מתקינים ומפעילים.</strong> בלוח הבקרה של וורדפרס:
            <span class="ig-path">תוספים ← הוספת תוסף ← העלאת תוסף</span>,
            בוחרים את הקובץ, <span class="ig-path">התקנה</span> ואז <span class="ig-path">הפעלה</span>.
        </li>

        <li>
            <strong>מדביקים את הקודים.</strong> בתפריט:
            <span class="ig-path">הגדרות ← Multi Digital Agent</span>.
            מעתיקים כל ערך לשדה שבאותו שם, ולוחצים <span class="ig-path">שמירת שינויים</span>.

            <div class="ig-codes">
                @foreach ([
                    'panel_url' => 'כתובת הפאנל',
                    'mcp_secret' => 'מפתח MCP',
                    'update_token' => 'טוקן עדכון',
                ] as $key => $label)
                    <div class="ig-code">
                        <span class="ig-k" id="ig-{{ $key }}-label">{{ $label }}</span>
                        @if (filled($codes[$key] ?? null))
                            <span class="ig-v" id="ig-{{ $key }}" dir="ltr">{{ $codes[$key] }}</span>
                            <button type="button" class="ig-copy" data-copy="ig-{{ $key }}"
                                    aria-describedby="ig-{{ $key }}-label">העתקה</button>
                        @else
                            {{-- A token that exists but cannot be shown again. Leaving
                                 the box blank reads as "paste nothing"; saying what
                                 to do instead does not. --}}
                            <span class="ig-v ig-missing">לא ניתן להציג — כתבו לנו ונפיק חדש. אפשר להמשיך בלעדיו; הוא נדרש רק לעדכונים אוטומטיים.</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="ig-status" role="status" aria-live="polite"></p>
        </li>

        <li>
            <strong>בודקים.</strong> {{ $checkSlot }}
        </li>
    </ol>

    <p class="ig-warn">
        🔒 הקודים האלה הם המפתחות לאתר שלכם. אל תשלחו אותם באימייל או בצ'אט, ואל תפרסמו אותם —
        מי שמחזיק בהם יכול לשנות את האתר.
    </p>

    <details class="ig-trouble">
        <summary>החיבור לא עובד? הבעיות הנפוצות ופתרונן</summary>

        <h3>האתר מאחורי Cloudflare</h3>
        <p>
            Cloudflare עלול להציג לפאנל מסך "Just a moment" שרק דפדפן יודע לעבור.
            ב־Cloudflare של האתר: <span class="ig-path">Security ← WAF ← Custom rules</span>, כלל חדש
            עם הנתיב <code dir="ltr">/wp-json/md-agent/</code> ופעולה <span class="ig-path">Skip</span>.
            אין צורך לכבות את Cloudflare.
        </p>

        <h3>תוסף אבטחה חוסם (Wordfence, Solid Security וכדומה)</h3>
        <p>
            אם תוסף אבטחה חוסם את ה־REST API של וורדפרס, אשרו בו את הנתיב
            <code dir="ltr">/wp-json/md-agent/</code>.
        </p>

        <h3>"המפתח שגוי" אף שהעתקתם נכון</h3>
        <p>
            ודאו שלא נכנס רווח בתחילת הערך או בסופו. בחלק מהשרתים נדרשת גם השורה הבאה בקובץ
            <code dir="ltr">.htaccess</code> (בראש הקובץ):
        </p>
        <pre dir="ltr"><code>RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]</code></pre>

        <h3>האתר לא בוורדפרס, או שאין לכם גישת מנהל</h3>
        <p>
            הבוט עובד עם אתרי וורדפרס בלבד. אם אין לכם גישת מנהל — בקשו אותה ממי שבנה את האתר,
            או תנו לנו להתקין עבורכם.
        </p>

        @if ($helpUrl)
            <p><a href="{{ $helpUrl }}">נתקעתם? כתבו לנו ונתקין עבורכם</a></p>
        @endif
    </details>
</section>

<style>
    .install-guide { margin-top: 1.5rem; }
    .install-guide h2 { margin-bottom: .25rem; }
    .install-guide h3 { font-size: 1rem; margin: 1rem 0 .3rem; }
    .install-guide .ig-lead, .install-guide .ig-hint { color: var(--muted, #6b7280); }
    .install-guide .ig-hint { display: block; font-size: .9rem; margin-top: .3rem; }
    .install-guide .ig-steps { padding-inline-start: 1.3rem; }
    .install-guide .ig-steps > li { margin-bottom: 1.1rem; }
    .install-guide .ig-path { font-weight: 700; white-space: nowrap; }
    .install-guide .ig-btn {
        display: inline-block; margin-top: .5rem; padding: .6rem 1rem; border-radius: 8px;
        background: var(--brand, #1c5fd6); color: var(--brand-fg, #fff); font-weight: 700; text-decoration: none;
    }
    .install-guide .ig-codes { display: grid; gap: .6rem; margin-top: .6rem; }
    .install-guide .ig-code {
        display: grid; grid-template-columns: 1fr auto; gap: .2rem .6rem; align-items: center;
        border: 1px solid var(--border, #c2c8d0); border-radius: 10px; padding: .55rem .75rem;
    }
    .install-guide .ig-k { grid-column: 1 / -1; font-size: .85rem; color: var(--muted, #6b7280); }
    .install-guide .ig-v {
        font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; word-break: break-all;
        text-align: left; user-select: all;
    }
    .install-guide .ig-missing { font-family: inherit; text-align: start; user-select: auto; }
    .install-guide .ig-copy {
        border: 1px solid var(--brand, #1c5fd6); background: transparent; color: var(--brand, #1c5fd6);
        border-radius: 8px; padding: .3rem .7rem; font: inherit; cursor: pointer;
    }
    .install-guide .ig-warn {
        border: 1px solid #f59e0b; background: rgb(245 158 11 / .1); border-radius: 10px; padding: .7rem .9rem;
    }
    .install-guide .ig-trouble summary { cursor: pointer; font-weight: 700; padding: .4rem 0; }
    .install-guide pre { overflow-x: auto; background: rgb(0 0 0 / .15); padding: .6rem; border-radius: 8px; }
    .install-guide :focus-visible { outline: 3px solid var(--brand, #1c5fd6); outline-offset: 2px; }
    .install-guide .ig-status:empty { display: none; }
</style>

<script>
    // Copy buttons are a convenience only: every value is also selectable as
    // text, so the page works fully without this script.
    document.querySelectorAll('.install-guide .ig-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = document.getElementById(button.dataset.copy);
            var status = button.closest('li').querySelector('.ig-status');
            if (!value || !navigator.clipboard) { return; }
            navigator.clipboard.writeText(value.textContent.trim()).then(function () {
                status.textContent = 'הועתק: ' + document.getElementById(button.dataset.copy + '-label').textContent;
            });
        });
    });
</script>
