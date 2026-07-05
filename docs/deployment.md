# מדריך התקנה ופריסה — Multioto

Multioto הוא **אפליקציית Laravel עצמאית** (לא תבנית/תוסף וורדפרס). היא צריכה סביבת PHP עם תור, scheduler ומסד נתונים. המדריך הזה מסביר איך להתקין אותה מ-GitHub, ומה התפקיד של FlyWP במערכת.

---

## 🟢 הדרך הכי פשוטה — בחרו אחת משתיים

אם ההתקנה הידנית נראית מסובכת, **אל תתקינו ידנית**. יש שתי דרכים בלי כאב:

### א׳ — Laravel Cloud (מנוהל, בלי שרתים בכלל) — הכי פשוט
1. היכנסו ל-[cloud.laravel.com](https://cloud.laravel.com) והתחברו עם GitHub.
2. בחרו את הריפו `multidigitalltd/multioto` והברנץ' `main`.
3. Laravel Cloud מקים לבד PostgreSQL + Redis + תור + scheduler + SSL. אין שרת לנהל.
4. ממלאים את משתני הסביבה במסך (או משאירים ומזינים מפתחות בפאנל אחר כך).

זו האפשרות המומלצת אם אתה לא רוצה להתעסק עם שרתים.

### ב׳ — פקודה אחת עם Docker (על כל מחשב/שרת עם Docker)
```bash
git clone https://github.com/multidigitalltd/multioto.git
cd multioto
cp .env.example .env
docker compose up -d --build
```
זהו. הפקודה מקימה **הכל יחד** — אפליקציה, מסד נתונים, Redis, תור ו-scheduler.
אחרי דקה־שתיים היכנסו ל-`http://localhost:8000/admin`.

ליצירת משתמש אדמין ונתוני דמו (פעם אחת):
```bash
docker compose exec app php artisan db:seed --force
```
משתמש ברירת מחדל: `admin@multi.digital` / `password` — **שנו סיסמה מיד**.

> שאר המדריך (Forge/Ploi, VPS ידני) הוא למי שרוצה שליטה מלאה. אם בחרתם א׳ או ב׳ — אתם מסודרים; דלגו ל"רשימת בדיקה אחרי פריסה".

---

## מה המערכת צריכה כדי לרוץ

| רכיב | דרישה |
|---|---|
| PHP | 8.3+ עם ההרחבות: `pdo_pgsql`, `redis`, `mbstring`, `bcmath`, `intl`, `gd` |
| מסד נתונים | PostgreSQL 15+ (בפרודקשן). SQLite רק לפיתוח/בדיקות |
| תור + cache | Redis |
| תהליך תור | `php artisan horizon` (worker רץ תמיד) |
| Scheduler | רשומת cron אחת שמריצה `php artisan schedule:run` כל דקה |
| Web server | Nginx/Apache שמצביע ל-`public/` |
| Composer + Node | להתקנת תלויות ובניית assets |

> **חשוב:** אי אפשר להריץ את זה כתבנית וורדפרס — וורדפרס אין לו תור/scheduler/Filament. זו אפליקציה נפרדת שמחליפה את הניהול הידני בוורדפרס.

---

## איפה לפרוס את פאנל Multioto (Laravel)

### מומלץ — Laravel Forge או Ploi (פריסה מ-GitHub בכמה קליקים)

שניהם ילידיים ל-Laravel, מתחברים ל-GitHub, ומגדירים אוטומטית תור + scheduler + SSL.

1. חברו את חשבון ה-GitHub ובחרו את הריפו `multidigitalltd/multioto` והברנץ' `main`.
2. הגדירו שרת עם PHP 8.3, PostgreSQL, Redis.
3. הדביקו את **Deploy Script** (ראו `deploy.sh` בריפו, או ההוראות למטה).
4. ב-Forge/Ploi: הפעילו **Horizon** (Daemon) ו-**Scheduler** בלחיצה.
5. מלאו את משתני ה-`.env` (ראו למטה) — או השאירו ריקים והזינו את מפתחות האינטגרציות דרך הפאנל (עמוד "מפתחות אינטגרציות").

### חלופה — VPS ידני

ראו "התקנה ידנית" למטה.

### FlyWP — לא לפאנל, אלא לאתרי הלקוחות

FlyWP מנהל אתרי **וורדפרס**, לא אפליקציות Laravel — לכן הוא **לא** המקום לפאנל Multioto עצמו. התפקיד שלו במערכת: לארח את **אתרי הלקוחות** שאותם Multioto משעה/משחזר במצב תחזוקה בסוף רצף הדאנינג (§4.5). כדי לחבר: הגדירו `HOSTING_DRIVER=flywp` והזינו את ה-API Token ו-Server ID בעמוד "מפתחות אינטגרציות".

---

## התקנה ידנית (VPS)

```bash
# 1. משיכת הקוד
git clone https://github.com/multidigitalltd/multioto.git
cd multioto

# 2. תלויות
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. סביבה
cp .env.example .env
php artisan key:generate
# ערכו את .env — לפחות: APP_URL, DB_*, REDIS_*, MAIL_*

# 4. מסד נתונים
php artisan migrate --force
php artisan db:seed --force        # אופציונלי — יוצר משתמש אדמין ונתוני דמו

# 5. assets של Filament + אופטימיזציה
php artisan filament:assets
php artisan optimize
php artisan storage:link

# 6. הרשאות
chown -R www-data:www-data storage bootstrap/cache
```

### תור ו-scheduler (חובה)

```bash
# תור — עדיף כ-systemd service / supervisor שרץ תמיד:
php artisan horizon

# scheduler — רשומת cron אחת:
* * * * * cd /path/to/multioto && php artisan schedule:run >> /dev/null 2>&1
```

---

## הגדרת `.env` — עיקרי

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.multidigital.co.il

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=multioto
DB_USERNAME=multioto
DB_PASSWORD=...

REDIS_HOST=127.0.0.1
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# מייל דרך Postmark (יוצא + נכנס)
MAIL_MAILER=postmark
POSTMARK_TOKEN=...              # או להזין בפאנל "מפתחות אינטגרציות"
MAIL_FROM_ADDRESS=support@multidigital.co.il
SUPPORT_EMAIL=support@multidigital.co.il
EMAIL_WEBHOOK_SECRET=...        # מוסיפים כ-?secret= ל-URL של ה-inbound webhook
```

מפתחות קארדקום / לינט / FlyWP / WAHA / Postmark — אפשר להזין ב-`.env` **או** בעמוד "מפתחות אינטגרציות" בפאנל (נשמרים מוצפנים, גוברים על `.env`).

### Postmark — יוצא ונכנס באותו חשבון

Postmark משמש לשני הכיוונים:

- **יוצא:** `MAIL_MAILER=postmark` + `POSTMARK_TOKEN`. הגדירו את הדומיין ב-Postmark עם **SPF + DKIM** (ו-DMARC בדומיין), ואמתו אותו. כל המיילים התפעוליים (דאנינג, תשובות תמיכה, דיוור) יוצאים דרכו.
- **נכנס (Inbound):** ב-Postmark → Server → **Inbound**, הגדירו את ה-Webhook URL ל-`https://app.multidigital.co.il/webhooks/email?secret=<EMAIL_WEBHOOK_SECRET>`. כדי שלקוחות יוכלו להשיב לכתובת שלכם, הפנו רשומת **MX** (למשל של `reply.multidigital.co.il`) ל-`inbound.postmarkapp.com` — או השתמשו בכתובת ה-inbound hash ש-Postmark מספק. הפורמט ש-Postmark שולח (`From` / `Subject` / `TextBody` / `MessageID`) כבר נתמך ב-`EmailWebhookController` — הפנייה הופכת לכרטיס אוטומטית.

---

## רשימת בדיקה אחרי פריסה

- [ ] `/admin` נטען ואפשר להתחבר (משתמש ה-seeder: `admin@multi.digital` / `password` — **שנו סיסמה מיד**).
- [ ] Horizon רץ (`/horizon` מציג worker פעיל).
- [ ] `php artisan schedule:list` מציג את המשימות המתוזמנות.
- [ ] מפתחות האינטגרציות הוזנו (בפאנל או ב-`.env`).
- [ ] webhooks מוגדרים אצל הספקים עם ה-secret: `/webhooks/cardcom`, `/webhooks/waha`, `/webhooks/email`.
- [ ] דומיין המייל עם SPF + DKIM + DMARC; MX ל-inbound parsing.
- [ ] גיבוי PostgreSQL יומי מוגדר.
