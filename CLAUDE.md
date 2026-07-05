# Multioto — Multi Digital Operations Platform

מערכת תפעול אוטומטית של Multi Digital: מונוליט Laravel שמנהל לקוחות, מנויים, חיובים (קארדקום), חשבוניות (לינט), תמיכה אומניצ'אנל (WAHA/מייל), דאנינג, ניטור אתרים ודיוור. מחליף את הניהול הידני בוורדפרס/ווקומרס.

## Stack

- PHP 8.3+, Laravel 12
- PostgreSQL 15+ (dev/tests fall back to SQLite where noted)
- Redis + Laravel Horizon (queues)
- Filament 3 (admin panel, team-only — לא לקוחות קצה)
- External APIs consumed as infrastructure primitives only: Cardcom (charging), Linet (invoicing), WAHA (WhatsApp transport), transactional email provider, hosting panel API

## Commands

```bash
composer install
php artisan migrate --seed         # local dev
php artisan test                   # runs on SQLite in-memory
vendor/bin/pint                    # code style (Laravel preset)
php artisan horizon                # queue worker (requires Redis)
php artisan schedule:work          # scheduler loop (dev)
```

## Architecture rules (חובה)

1. **Money is integer agorot.** Never float. Currency is ILS only. Use the `Money` value object / `*_agorot` columns.
2. **Idempotency on every financial integration.** No charge may ever run twice: per-subscription logical lock during charging, `attempt_number` on every attempt, and every webhook goes through `webhook_events` with a unique `external_id` before processing.
3. **All heavy work runs as queued Jobs** dispatched by the Scheduler. Nothing heavy inside an HTTP request.
4. **No card numbers, ever.** Card capture happens only in Cardcom's hosted Low Profile page; we store only the token reference in `payment_tokens`. PCI scope stays with Cardcom.
5. **External services are thin clients** (`app/Services/*Client.php`) — all business logic lives in our jobs/actions, not in the clients.
6. **Every Cardcom response is recorded** on the `charges` row (response code, transaction id), success or failure.
7. Invoices (Linet) are issued **only after a successful charge**. VAT category comes from the customer's `vat_exempt` flag.
8. Dunning timings live in `config/billing.php` — never hardcode stage days/amounts in jobs.

## תקן פיתוח Multi Digital (מחייב)

התקן המלא: `docs/multi-digital-standard.md`. עיקרי הדין המחייבים בכל שינוי קוד בפרויקט הזה:

### אבטחה
- כל קלט משתמש עובר validation/sanitization (Form Requests); כל פלט עובר escaping (Blade `{{ }}`, לא `{!! !!}` אלא בהצדקה מתועדת).
- Eloquent/Query Builder עם bindings בלבד. אסור SQL concatenation. אסור `DB::raw` עם קלט משתמש.
- כל endpoint שמשנה state מוגן ב-CSRF, auth middleware ובדיקת הרשאה (Policies/Gates). למנוע IDOR — כל שליפה נבדקת מול הרשאות המשתמש.
- כל webhook נכנס: אימות חתימה/סוד, אימות מקור, rate limiting, ורישום ב-`webhook_events`.
- אסור `eval`/`exec`/`shell_exec`/`system`/`passthru` ואסור קוד מוצפן/obfuscated. Secrets רק ב-`.env`/vault — לעולם לא בקוד או בלוגים.
- העלאות קבצים: בדיקת MIME, סיומת, גודל והרשאות; אסור קבצי PHP.
- אין לחשוף stack traces, שגיאות PHP, מזהים פנימיים או נתונים רגישים למשתמש/ללוגים.

### ביצועים
- הפתרון הפשוט והרזה ביותר; בלי ספריות צד ג' כשיכולות הליבה של Laravel מספיקות.
- מינימום שאילתות: eager loading למניעת N+1, `select` רק לעמודות נדרשות, אינדקסים לשדות מסוננים.
- קריאות API חיצוניות — לעולם לא בזמן request אם אפשר ברקע; תוצאות שאינן real-time נשמרות ב-cache.
- עבודה כבדה → Queue/Batch. דיוור המוני → chunks עם throttling.

### איכות קוד
- PHP 8.3+ בלבד, ללא deprecated, ללא warnings/notices. Pint (Laravel preset) חייב לעבור נקי.
- פונקציות קצרות וממוקדות, שמות ברורים, DRY, הפרדת לוגיקה מתצוגה, ללא dead code / debug code (`dd`, `dump`, `var_dump`, `console.log`).
- תיעוד (docblock) לכל רכיב משמעותי — במיוחד סביב כסף ומכונת הדאנינג.

### נגישות (לכל UI שנבנה בפרויקט — טפסים ציבוריים, עמודי תשלום, מיילים)
- עמידה ב-WCAG 2.2 AA ות"י 5568: HTML סמנטי, ניווט מקלדת מלא, focus states, labels לכל שדה, ניגודיות תקינה, תמיכת RTL מלאה (עברית) לצד LTR, `prefers-reduced-motion`.
- הודעות שגיאה בטפסים מקושרות פרוגרמטית (aria-describedby) ונגישות לקורא מסך.

## Data model

See `docs/architecture.md` §3 and the migrations in `database/migrations`. Key relations:
customer 1—* sites / subscriptions / payment_tokens / tickets · subscription 1—* charges · charge 1—0..1 invoice · ticket 1—* ticket_messages · site 1—* monitor_checks / incidents.

## Testing

- Feature tests run against SQLite in-memory (`phpunit.xml`), so migrations must stay SQLite-compatible (no raw Postgres-only DDL without a driver guard).
- Financial flows (charging, dunning transitions, idempotency) must have tests before merge.
