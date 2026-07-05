# אבטחה — Multioto

אבטחה נבנתה לתוך המערכת מהיסוד. המסמך הזה מסכם מה כבר מוגן, ומה עליך להגדיר בפרודקשן.

## מה כבר מוגן בקוד

| נושא | מימוש |
|---|---|
| **כרטיסי אשראי (PCI)** | אף פעם לא נשמרים אצלנו. קליטת כרטיס רק בדף Low Profile של קארדקום; אצלנו טוקן בלבד. |
| **סודות ומפתחות** | מוצפנים ב-DB (Laravel Crypt) או ב-`.env`. לא נכתבים בלוגים ולא מוצגים חזרה בטופס. |
| **Webhooks** | אימות סוד משותף (`hash_equals`, fail-closed), רישום אידמפוטנטי (`webhook_events`), rate limiting. |
| **קישורי לקוח** | Signed URLs חתומים — לא ניתן לנחש/למספֵּר מזהי לקוחות; rate limiting. |
| **CSRF** | על כל טופס; פטור רק ל-webhooks (שמאומתים בסוד). |
| **קלט/פלט** | Form Requests לוולידציה; Blade escaping (`{{ }}`); Eloquent bindings (בלי SQL concatenation). |
| **חיובים כפולים** | נעילה פר-מנוי + unique index + מפתח אידמפוטנטיות לקארדקום. |
| **AI** | לא שולח ללקוח כלום — טיוטות בלבד לאישור אנושי. |
| **כותרות אבטחה** | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, ו-HSTS ב-HTTPS (SecurityHeaders middleware). |
| **HTTPS** | כפיית סכימת https לכל הקישורים כשהמערכת מאחורי TLS. |
| **תלויות** | `composer audit` נקי; CI מריץ בדיקות + Pint על כל PR. |

## מה להגדיר בפרודקשן (רשימת בדיקה)

- [ ] **`APP_DEBUG=false`** ו-`APP_ENV=production` — קריטי. אחרת נחשפים stack traces.
- [ ] **`APP_URL=https://app.multidigital.co.il`** — מפעיל כפיית HTTPS.
- [ ] **HTTPS בתוקף** — Caddy עושה זאת אוטומטית (Let's Encrypt), או ספק ה-hosting.
- [ ] **שנה את סיסמת האדמין** של ה-seeder (`admin@multi.digital` / `password`) מיד.
- [ ] **הרשאות צוות בלבד** ל-`/admin` — אין רישום עצמי; משתמשים נוצרים ידנית.
- [ ] **גיבוי PostgreSQL יומי** (למשל `pg_dump` מתוזמן + העתק מחוץ לשרת).
- [ ] **secrets** — הזן מפתחות בעמוד "מפתחות אינטגרציות" (מוצפן) או ב-`.env` עם הרשאות קובץ `600`.
- [ ] **firewall** — חשוף רק 80/443. אל תחשוף את פורט 8000 של האפליקציה או את PostgreSQL/Redis לאינטרנט.
- [ ] **webhook secrets** — ערכים אקראיים חזקים (`CARDCOM_WEBHOOK_SECRET`, `WAHA_WEBHOOK_SECRET`, `EMAIL_WEBHOOK_SECRET`).
- [ ] **גיבוי `APP_KEY`** — בלעדיו לא ניתן לפענח את המפתחות המוצפנים ב-DB.

## המלצות נוספות (אופציונלי, מומלץ)

- **2FA לפאנל** — Filament תומך; להפעיל לכל משתמשי הצוות.
- **Activity log** — תיעוד פעולות צוות (שלב הקשחה עתידי ב-Backlog).
- **סקירת אבטחה תקופתית** — להריץ `composer audit` וסריקת קוד לפני כל שחרור.

> אם תרצה, אפשר להריץ **סקירת אבטחה מעמיקה** על הקוד (זרימות כסף, webhooks, IDOR, הזרקות) ולתקן ממצאים לפני עלייה לאוויר.
