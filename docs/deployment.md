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
cp .env.docker .env          # הגדרות Docker מוכנות (PostgreSQL/Redis/דומיין)
docker compose up -d --build
```
זהו. הפקודה מקימה **הכל יחד** — אפליקציה, מסד נתונים, Redis, תור ו-scheduler.
אחרי דקה־שתיים היכנסו ל-`http://localhost:8000/admin`.

ליצירת משתמש אדמין ונתוני דמו (פעם אחת):
```bash
docker compose exec app php artisan app:create-admin
```
הפקודה תשאל שם / מייל / סיסמה ותיצור משתמש אדמין. (בפרודקשן משתמשים ב-`app:create-admin` ולא ב-`db:seed`, כי ה-seeder כולל נתוני דמו התלויים ב-faker שאינו מותקן ב-`--no-dev`.)

> שאר המדריך (Forge/Ploi, VPS ידני) הוא למי שרוצה שליטה מלאה. אם בחרתם א׳ או ב׳ — אתם מסודרים; דלגו ל"רשימת בדיקה אחרי פריסה".

---

## דומיין + HTTPS (app.multidigital.co.il)

1. הפנו רשומת **A** של `app.multidigital.co.il` ל-IP של השרת.
2. ב-`.env`: `APP_URL=https://app.multidigital.co.il` ו-`APP_DOMAIN=app.multidigital.co.il`.
3. הריצו עם ה-reverse proxy המובנה (Caddy — תעודת SSL אוטומטית מ-Let's Encrypt):
   ```bash
   docker compose --profile proxy up -d --build
   ```
   Caddy מאזין ב-80/443 ומפנה ל-`app:8000` עם HTTPS אוטומטי. זהו — הכתובת עולה מאובטחת.

> **אם כבר יש reverse proxy בשרת** (nginx/Traefik/פאנל אחר) — אל תפעילו את פרופיל `proxy`; פשוט הפנו את ה-proxy הקיים ל-`http://127.0.0.1:8000`.

---

## הרצה לצד אפליקציית Docker אחרת שכבר על השרת

**לא צריך "Docker נפרד".** Docker אחד מריץ כמה מערכות במקביל — כל `docker compose` הוא פרויקט מבודד משלו (רשת + volumes נפרדים). רק שימו לב לשניים:

- **התנגשות פורטים:** אם 80/443 כבר תפוסים ע"י המערכת האחרת, אל תפעילו את פרופיל `proxy` של Multioto. במקום זה חברו את ה-proxy הקיים (או ה-Caddy/Traefik המשותף) אל `app:8000`, או שנו את המיפוי ב-`docker-compose.yml` (למשל `"8001:8000"`).
- **שם פרויקט:** הריצו מתוך תיקיית `multioto` (שם הפרויקט נגזר מהתיקייה) כדי שה-volumes לא יתנגשו עם האפליקציה האחרת.

בקצרה: אותו Docker, פרויקט נפרד. אין צורך בשרת/דוקר שני.

---

## עדכון גרסה מ-GitHub (בלי למחוק תכנים)

יש סקריפט עדכון בטוח: `update.sh`. הוא מושך את הקוד העדכני ומריץ **רק מיגרציות חדשות** — אף פעם לא מוחק טבלאות או נתונים. הנתונים (PostgreSQL) והקבצים שהועלו נשמרים ב-Docker volumes (`pgdata`, `storage`) ושורדים כל עדכון ו-rebuild.

```bash
cd multioto
./update.sh            # התקנת Docker
# או:
./update.sh --native   # התקנת Forge/Ploi/VPS ללא Docker
```

מכיוון שאתם מתכננים הרבה עדכונים — זה התהליך שתריצו בכל פעם. **בלי `migrate:fresh` ובלי `db:seed`** בעדכון, ולכן שום תוכן לא נמחק.

> **חיבור הוואטסאפ שורד את העדכון.** ה-session של WAHA נשמר ב-named volumes (`waha_sessions`, `waha_media`) ולכן `update.sh` (שמריץ `docker compose up -d --build`) **לא מנתק** את המספר המחובר ולא דורש סריקת QR מחדש. ראו סעיף WAHA Plus למטה.

> טיפ: אפשר להריץ `./update.sh` דרך cron או webhook של GitHub Actions לפריסה אוטומטית בכל push ל-`main`.

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

## WhatsApp — WAHA Plus (חיבור שלא מתנתק בעדכון)

הקמת Docker (אפשרות ב׳) כוללת שירות **`waha`** מובנה ב-`docker-compose.yml`. הוא רץ על **WAHA Plus** דווקא (לא Core), וזה מה שמשאיר את המספר מחובר גם אחרי עדכון גרסה:

- **מנוע NOWEB + volume קבוע** (`waha_sessions`, `waha_media`) — נתוני ה-session נשמרים בדיסק ולא בתוך הקונטיינר, ולכן שורדים כל `up --build` / `./update.sh`.
- **`WHATSAPP_RESTART_ALL_SESSIONS=True`** — בכל הפעלה מחדש ה-session נטען ומתחבר אוטומטית, בלי סריקת QR חוזרת.
- **Webhook פנימי** — WAHA שולח הודעות נכנסות ישירות ל-`http://app:8000/webhooks/waha?secret=<WAHA_WEBHOOK_SECRET>` ברשת הפנימית של Docker.

### הפעלה ראשונה

1. **התחברות ל-registry הפרטי של Plus** (פעם אחת, עם המפתח שקיבלתם):
   ```bash
   docker login -u devlikeapro -p <YOUR_WAHA_PLUS_KEY>
   ```
   (אם קיבלתם תג אחר — עדכנו `WAHA_IMAGE` ב-`.env`.)
2. מלאו ב-`.env`: `WAHA_API_KEY` (מפתח לשרת WAHA) ו-`WAHA_WEBHOOK_SECRET`.
3. `docker compose up -d --build` ואז היכנסו לדשבורד WAHA ב-`http://127.0.0.1:3000` וסרקו QR **פעם אחת**. מכאן והלאה החיבור נשמר.

> אם אתם מריצים WAHA במקום אחר (מנוהל/שרת נפרד) — אל תפעילו את שירות `waha` של compose; פשוט הצביעו `WAHA_BASE_URL` אל אותו instance. שם ודאו בעצמכם persistence של ה-session + `WHATSAPP_RESTART_ALL_SESSIONS=True`, אחרת הוא ינותק בכל restart.

---

## רשימת בדיקה אחרי פריסה

- [ ] `/admin` נטען ואפשר להתחבר (משתמש ה-seeder: `admin@multi.digital` / `password` — **שנו סיסמה מיד**).
- [ ] Horizon רץ (`/horizon` מציג worker פעיל).
- [ ] `php artisan schedule:list` מציג את המשימות המתוזמנות.
- [ ] מפתחות האינטגרציות הוזנו (בפאנל או ב-`.env`).
- [ ] webhooks מוגדרים אצל הספקים עם ה-secret: `/webhooks/cardcom`, `/webhooks/waha`, `/webhooks/email`.
- [ ] דומיין המייל עם SPF + DKIM + DMARC; MX ל-inbound parsing.
- [ ] גיבוי PostgreSQL יומי מוגדר.
