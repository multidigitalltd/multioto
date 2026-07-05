# מולטי דיגיטל — תוכנית עבודה לפיתוח מערכת תפעול אוטומטית

מסמך מסירה למפתח. המערכת מחליפה את הניהול הידני הנוכחי (וורדפרס + ווקומרס + טיפול ידני) בשירות Laravel אחד בבעלות מלאה, שמחבר בין קארדקום (חיובים), לינט (חשבוניות), WAHA (וואטסאפ) וספק מייל טרנזקציוני.

**מטרת-על:** לצמצם למינימום טיפול ידני בגבייה, בקריאות שירות ובתקלות, כדי שאפשר יהיה לקלוט לקוחות נוספים בלי לגייס עובדים.

---

## 1. עקרונות ארכיטקטורה

- **מונוליט Laravel אחד** בבעלות מלאה. אין n8n, אין תוספי CRM/וורדפרס, אין SaaS ניהולי חיצוני.
- כל תלות חיצונית היא **פרימיטיב תשתית שנצרך כ-API** בלבד: סליקה (קארדקום), חשבוניות (לינט), טרנספורט וואטסאפ (WAHA), מייל טרנזקציוני.
- הקוד שלנו הוא **הדבק והלוגיקה**: מודל הנתונים, מנוע החיוב, מכונת הדאנינג, הכרטוס, הניטור והדיוור.
- כל תהליך רקע רץ כ-**Job בתור**, נשלט ע"י ה-Scheduler. שום פעולה כבדה לא רצה בתוך request.
- **אידמפוטנטיות** בכל אינטגרציה כספית: אף חיוב לא מתבצע פעמיים, כל webhook נרשם ומעובד פעם אחת.

## 2. סטאק טכני

| רכיב | בחירה | הערה |
|---|---|---|
| שפה/פריימוורק | PHP 8.3+, Laravel 12 (שודרג מ-11 — תיקוני אבטחה) | Scheduler, Queue, Eloquent, Mail מובנים |
| DB | PostgreSQL 15+ | |
| תור + ניטור תור | Redis + Laravel Horizon | |
| ממשק ניהול פנימי | **Filament 3** | פאנל CRUD על גבי המודלים |
| הרשאות admin | Laravel auth | משתמשי צוות בלבד, לא לקוחות קצה |
| Deploy | לפי העדפה (Forge/Ploi/Docker) | |

## 3. מודל נתונים

כל סכומי הכסף נשמרים כ**מספרים שלמים באגורות** (לא float). מטבע = ILS בלבד.

הטבלאות: `customers`, `payment_tokens`, `sites`, `plans`, `subscriptions`, `charges`, `invoices`, `dunning_events`, `tickets`, `ticket_messages`, `canned_responses`, `broadcasts`, `monitor_checks`, `incidents`, `webhook_events` — הסכמה המלאה במיגרציות תחת `database/migrations` והמודלים תחת `app/Models`.

**קשרים עיקריים:** customer 1—∗ sites / subscriptions / payment_tokens / tickets · subscription 1—∗ charges · charge 1—0..1 invoice · ticket 1—∗ ticket_messages · site 1—∗ monitor_checks / incidents.

## 4. אינטגרציות חיצוניות

### 4.1 קארדקום — סליקה (מודול אסימונים / טוקן)
- שמירת כרטיס כטוקן וחיוב יזום מהשרת שלנו בכל מחזור, כדי שרצף הגבייה והתזכורות יהיו בשליטה מלאה שלנו.
- **דרישת חשבון:** מסוף ללא חובת CVV + מודול אסימונים פעיל.
- **קליטת כרטיס:** דרך דף Low Profile של קארדקום (iframe/redirect). פרטי כרטיס **לעולם** לא עוברים אצלנו; נשמר רק טוקן ב-`payment_tokens`.
- **מימוש:** `App\Services\Cardcom\CardcomClient` — `createTokenLowProfile()`, `chargeToken()`, `getTransactionInfo()`. כל תשובה נרשמת ב-`charges`.

### 4.2 לינט — חשבוניות
- הפקת מסמך רק לאחר חיוב מוצלח; קטגוריית מע"מ לפי `customers.vat_exempt`.
- מספר הקצאה: רלוונטי רק לחשבוניות מעל התקרה (10,000 ₪ לפני מע"מ נכון לינואר 2026) — נשמר אם הוחזר.
- **מימוש:** `App\Services\Linet\LinetClient` — `issueDocument()`, `getDocument()`; `IssueInvoiceJob`.

### 4.3 WAHA — וואטסאפ (לא רשמי)
- נכנס: webhook → `WahaWebhookController` → `IngestWhatsappMessageJob` → התאמה ללקוח → כרטיס + הודעות.
- יוצא: `WahaClient::sendMessage()` / `sendMedia()`; `sessionStatus()` לניטור ניתוקים.
- דיוור המוני בוואטסאפ — סיכון חסימה: throttling אגרסיבי, פילוחים קטנים בלבד (§7).

### 4.4 מייל טרנזקציוני
- ספק ייעודי (Postmark / SES / Resend) לכל מייל תפעולי. SPF + DKIM + DMARC חובה. מייל החשבונית יוצא מלינט.

### 4.5 אחסון — השעיה/שחזור
- `App\Services\Hosting\HostingClient` (interface) — `suspendSite()`, `restoreSite()`. הדרייבר הנוכחי `log` בלבד עד להחלטה על פאנל האחסון (§13); ההחלפה ב-`AppServiceProvider`.

## 5. מנוע החיוב + מכונת המצבים של הדאנינג

### זרימת חיוב תקין
Scheduler (`routes/console.php`) מזהה מנוי עם `next_charge_at <= now` → `ChargeSubscriptionJob`:
1. חיוב הטוקן בקארדקום, רישום `charges`.
2. הצלחה → `IssueInvoiceJob` → קידום תקופה ו-`next_charge_at` → איפוס `dunning_stage=0` (+שחזור אתר אם היה מושעה).
3. כשל → `DunningMachine::handleFailure()`.

### רצף דאנינג — מוגדר ב-`config/billing.php` (לא בקוד)
| שלב | יום | פעולה |
|---|---|---|
| 1 | 0 | הודעת "החיוב לא עבר" בוואטסאפ + מייל, קישור חתום לעדכון כרטיס. ניסיון חוזר +2 |
| 2 | 2 | תזכורת שנייה, ניסיון +3 |
| 3 | 5 | **אזהרת השעיה**, ניסיון +3 |
| 4 | 8 | `SuspendSiteJob` + הודעת "האתר הושעה". סטטוס `suspended`, אין ניסיונות נוספים |

### התאוששות
עדכון כרטיס/תשלום בדף Low Profile → webhook → `ProcessCardcomLowProfileJob`: שמירת טוקן חדש, הפנייתו לכל המנויים, חיוב מיידי למנויים בדאנינג → הצלחה מפעילה `RestoreSiteJob` ואיפוס המכונה.

### כללי ברזל (ממומשים)
- נעילת cache פר-מנוי בזמן חיוב + בדיקת due בתוך הנעילה + unique index על (subscription, period, attempt) + `ExternalUniqueTranId` לקארדקום.
- כל webhook עובר דרך `WebhookEvent::record()` עם `external_id` ייחודי.

## 6. מערכת כרטיסים וקליטה אומניצ'אנל
- שלושת הערוצים ממומשים end-to-end דרך `TicketIntake` (שירות מרכזי, DRY): **וואטסאפ** (`IngestWhatsappMessageJob`), **מייל נכנס** (`EmailWebhookController` → `IngestEmailMessageJob`, שרשור לפי שולח+נושא מנורמל), **טופס אתר** (`SupportFormController`, נגיש WCAG 2.2 AA + honeypot + rate limit).
- התאמה ללקוח לפי JID/מייל/טלפון; אי-התאמה → כרטיס "לא מזוהה". הודעה חדשה בכרטיס פתור פותחת אותו מחדש.
- תשובת סוכן: RelationManager "שיחה" ב-Filament (thread מלא + תבניות מענה מהיר) → `SendTicketReplyJob` מנתב לערוץ המקורי; הערה פנימית לא נשלחת.

## 7. דיוור המוני ותקלות
- `SendBroadcastJob`: מייל בצ'אנקים (ברירת מחדל), וואטסאפ עם throttle של 30ש' בין הודעות ולפילוחים קטנים בלבד.

## 8. ניטור אתרים
- `MonitorSiteJob` כל 5 דק' לאתרים עם `monitor_enabled`; פתיחת incident + כרטיס פנימי אחרי N כשלים רצופים; סגירה אוטומטית בהתאוששות.

## 9. שכבת AI — שלב מתקדם (אופציונלי)
- `ClassifyTicketJob` / `DraftReplyJob` דרך Claude API — אחרי ששאר הלולאות יציבות.

## 10. אבטחה ותאימות
- PCI: אין אחסון מספרי כרטיס; קליטה רק ב-Low Profile.
- אימות סוד על webhooks (hash_equals), rate limiting, קישורי לקוח חתומים (signed URLs), secrets ב-`.env`.
- גיבויי Postgres יומיים + activity log — שלב הקשחה.

## 11. שלבי עבודה

- **שלב 0 — תשתית** ✅ שלד, מיגרציות, Filament, seeders.
- **שלב 1 — ליבת החיוב** ✅ CardcomClient, LinetClient, ChargeSubscriptionJob, IssueInvoiceJob, Scheduler.
- **שלב 2 — דאנינג ומחזור חיים** ✅ DunningMachine, תזכורות, התאוששות, השעיה/שחזור (דרייבר log עד החלטת פאנל).
- **שלב 3 — קליטת תמיכה** ✅ שלושת הערוצים (וואטסאפ + מייל נכנס + טופס) → כרטיסים דרך `TicketIntake`; UI שיחה עם מענה חוזר ותבניות ב-Filament.
- **שלב 4 — ניטור ודיוור** ✅ ליבה ממומשת; נותר: דיוור ללקוח מושפע בתקלה.
- **שלב 5 — AI Tier-1** ☐ אופציונלי.

## 12. משימות המשך (Backlog)
1. החלטת פאנל אחסון ומימוש דרייבר אמיתי ל-`HostingClient`.
2. Sandbox קארדקום: אימות פורמט payload אמיתי של Low Profile / Transactions מול המימוש.
3. אימות פורמט API של לינט מול חשבון אמיתי (חשבונית פטור/חייב).
4. אימות פורמט inbound-parse של ספק המייל בפועל (מיפוי שדות ב-`IngestEmailMessageJob`).
5. ניטור session של WAHA + התראת re-scan QR.
6. דיוור אוטומטי ללקוחות מושפעים בתקלה (incident → broadcast).
7. הקשחה: activity log, גיבויים, אימות דומיין מייל.
8. הגירת נתונים מווקומרס/קארדקום (בדיקה מה ניתן לשמר מהטוקנים הקיימים).
9. שלב 5 — AI Tier-1 (סיווג/טיוטות תשובה) אופציונלי.

## 13. פתוחות והחלטות שנדרשות
- **פאנל האחסון:** איזה API להשעיה/שחזור (cPanel/WHM? Plesk?) — קובע את מימוש `HostingClient`.
- **הגדרות קארדקום:** מודול אסימונים + מסוף ללא חובת CVV.
- **מפתחות לינט:** זוג מפתחות API + זמינות הפקה פר-חיוב במסלול.
- **ספק מייל + אימות דומיין:** בחירה + SPF/DKIM/DMARC + warmup.
- **הגירת נתונים:** ייבוא לקוחות/מנויים; ייתכן שטוקנים קיימים לא יעברו — לתכנן מהלך החלפת כרטיס חלק.
- **WAHA:** אירוח, יציבות session, מדיניות דיוור זהירה.
