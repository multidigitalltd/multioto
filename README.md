# Multioto — מערכת התפעול של מולטי דיגיטל

מונוליט Laravel שמחליף את הניהול הידני (וורדפרס + ווקומרס): חיובים מחזוריים בקארדקום, חשבוניות בלינט, תמיכה אומניצ'אנל בוואטסאפ (WAHA) ומייל, מכונת דאנינג אוטומטית עם השעיה/שחזור אתרים, ניטור אתרים ודיוור.

📄 **התוכנית המלאה:** [docs/architecture.md](docs/architecture.md) · **מדריך התקנה ופריסה:** [docs/deployment.md](docs/deployment.md) · **תקן הפיתוח המחייב:** [docs/multi-digital-standard.md](docs/multi-digital-standard.md) · **כללי עבודה לקוד:** [CLAUDE.md](CLAUDE.md)

> **התקנה בפרודקשן:** זו אפליקציית Laravel עצמאית (לא תבנית וורדפרס). פרסו ב-Laravel Forge/Ploi ישירות מ-GitHub, או ידנית לפי [docs/deployment.md](docs/deployment.md). FlyWP מארח את אתרי הלקוחות שהמערכת משעה/משחזרת — לא את הפאנל עצמו.

## התקנה מקומית

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # או PostgreSQL — עדכנו DB_* ב-.env
php artisan migrate --seed
php artisan serve                     # http://localhost:8000/admin — פאנל Filament
```

משתמש אדמין ראשוני נוצר ב-seeder: `admin@multi.digital` (סיסמה: `password`).

בפרודקשן: PostgreSQL 15+, Redis + `php artisan horizon` לתור, ורשומת cron אחת ל-scheduler:

```
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

## מה ממומש

- **מודל נתונים מלא** (§3 בתוכנית) — כל הסכומים באגורות (int), ILS בלבד.
- **מנוע חיוב** — `ChargeSubscriptionJob` עם נעילה פר-מנוי, unique index על ניסיונות, ו-`ExternalUniqueTranId` מול קארדקום. אף חיוב לא רץ פעמיים.
- **חשבוניות** — `IssueInvoiceJob` מפיק מסמך לינט רק אחרי חיוב מוצלח, עם קטגוריית מע"מ פר לקוח.
- **מכונת דאנינג** — 4 שלבים מוגדרים ב-`config/billing.php`: תזכורות וואטסאפ+מייל, ניסיונות חוזרים, השעיית אתר ושחזור אוטומטי אחרי תשלום.
- **קליטת תמיכה בוואטסאפ** — webhook של WAHA פותח/מעדכן כרטיסים, תשובות סוכן חוזרות לערוץ.
- **ניטור אתרים** — בדיקות uptime, פתיחת incidents וכרטיס פנימי, סגירה אוטומטית.
- **דיוור** — broadcasts במייל (chunked) ובוואטסאפ (throttled).
- **פאנל Filament** — `/admin`: לקוחות, תוכניות, אתרים, מנויים, כרטיסים, חיובים, חשבוניות ועוד.
- **שכבת AI Tier-1 (אופציונלי, כבוי כברירת מחדל)** — סיווג פניות וניסוח **טיוטות תשובה** דרך Claude API. הטיוטה נשמרת כהערה פנימית; **שום דבר לא נשלח ללקוח ללא אישור אנושי** ("אישור טיוטת AI" בכרטיס). מופעל עם `AI_ENABLED=true` + `ANTHROPIC_API_KEY`.

## אבטחה

- אין אחסון פרטי כרטיס — קליטת כרטיס רק בדף ה-Low Profile של קארדקום; אצלנו טוקן בלבד.
- כל webhook מאומת (סוד משותף), נרשם ב-`webhook_events` ומעובד פעם אחת (אידמפוטנטיות).
- קישורי לקוח (עדכון כרטיס) הם signed URLs עם rate limiting.
- Secrets ב-`.env` בלבד — ראו `.env.example` לרשימה המלאה.

## בדיקות

```bash
php artisan test    # SQLite in-memory
vendor/bin/pint     # code style
```
