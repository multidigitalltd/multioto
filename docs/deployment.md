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

## רשימת בדיקה אחרי פריסה

- [ ] `/admin` נטען ואפשר להתחבר (משתמש ה-seeder: `admin@multi.digital` / `password` — **שנו סיסמה מיד**).
- [ ] Horizon רץ (`/horizon` מציג worker פעיל).
- [ ] `php artisan schedule:list` מציג את המשימות המתוזמנות.
- [ ] מפתחות האינטגרציות הוזנו (בפאנל או ב-`.env`).
- [ ] webhooks מוגדרים אצל הספקים עם ה-secret: `/webhooks/cardcom`, `/webhooks/waha`, `/webhooks/email`.
- [ ] דומיין המייל עם SPF + DKIM + DMARC; MX ל-inbound parsing.
- [ ] גיבוי אוטומטי מוגדר ורץ — ראו "גיבוי ושחזור" למטה.

## גיבוי ושחזור

המערכת לוקחת בעצמה גיבוי לילי של **כל** הנתונים (כל שורות בסיס הנתונים + הקבצים
שהועלו) ושומרת אותו ביעד אחסון חיצוני. מנוהל ממסך **הגדרות ← גיבוי ושחזור**.

מה צריך להגדיר פעם אחת:

1. דלי S3 (או כל ספק תואם S3) **פרטי** — הארכיון מכיל שמות, טלפונים, כתובות
   והיסטוריית תשלומים של לקוחות. אל תאפשרו גישה ציבורית.
2. ב-`.env`: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
   `AWS_BUCKET` (ו-`AWS_ENDPOINT` אצל ספק שאינו אמזון), ואז `BACKUP_DISK=s3`.
3. במסך: לוודא שהמתג דלוק, לבחור שעה וחלון שמירה, וללחוץ **גבה עכשיו** כדי
   לוודא שהיעד באמת עובד — לפני שסומכים על הגיבוי הלילי.

הערות:

- הגיבוי רץ כעבודת תור, ולכן **חייב** worker פעיל (Horizon / `queue:work`)
  בנוסף ל-scheduler. `retry_after` בתור חייב להישאר גבוה מזמן הריצה המרבי של
  הגיבוי והשחזור (1800 שניות) — אחרת עובד שני מקבל את אותה משימה בזמן שהראשון
  עדיין רץ, ומסמן ככישלון פעולה שממש עכשיו מחליפה נתונים.
- **נוהל השחזור — להשתיק את המערכת, לוודא שהשתתקה, ורק אז לשחזר:**

  ```bash
  php artisan down                                    # אין בקשות חדשות
  php artisan horizon:terminate                       # בקשת עצירה — לא עצירה מיידית
  until php artisan horizon:status 2>&1 | grep -q inactive; do sleep 5; done
  # ולהמתין גם לבקשות ה-web שכבר רצות: עד שאין php-fpm/worker פעיל
  php artisan backup:restore <מזהה הגיבוי>
  php artisan up
  ```

  **שתי שורות ההמתנה אינן קישוט.** `horizon:terminate` מבקש עצירה מסודרת ומחזיר
  שליטה מיד, בעוד המשימות שכבר רצות ממשיכות עד שיסתיימו — ו-`down` מונע בקשות
  **חדשות** אבל אינו קוטע בקשה שכבר באמצע. גם התור וגם בקשת web יכולים להיות
  באותו רגע בתוך קריאה לקארדקום או ללינט: השער כבר נעבר, הכסף יורד או המסמך
  מונפק, ואם השחזור מתחיל באותו רגע — השורה שרושמת את זה נמחקת או נכתבת על נתונים
  משוחזרים. לכן ממתינים עד ש-Horizon מדווח `inactive` (בעצירת container: עד
  שהוא באמת יצא) ועד שאין בקשות web פעילות. הפקודה `backup:restore` בודקת בעצמה
  אם המערכת במצב תחזוקה וכמה משימות נותרו בתור, ומבקשת אישור אם לא.

  עוצרים את ה-worker כי חיובים והנפקת חשבוניות נעצרים מעצמם כל עוד שחזור רץ,
  אבל משימה שכבר באמצע קריאה לקארדקום/לינט אי אפשר להחזיר — הכסף יורד או המסמך
  מונפק, והשורה שרושמת אותם מוחלפת באותו רגע. ומריצים מהשורה כי **כפתור השחזור
  במסך מעביר את העבודה לתור** — בלי worker אין מי שיבצע אותה, והמסך היה מדווח על
  שחזור שהתחיל בעוד שדבר לא קורה. הפקודה מבצעת את השחזור בחזית, מבקשת את אותה
  מילת אישור, ומשתלטת גם על תביעה שכבר נעשתה מהמסך ולא רצה (מזהה הניסיון מתחלף,
  כך שאם ההודעה שבתור תגיע בכל זאת — היא תיעצר). בסיום מפעילים את ה-worker חזרה.
- כפתור השחזור במסך מתאים כשה-worker פעיל — למשל שחזור לסביבת בדיקות. ההודעה
  שמוצגת אחרי הלחיצה כוללת את הפקודה להרצה אם התור מושבת.
- שחזור מוחק את כל הנתונים הקיימים ומחליף אותם. הוא דורש הקלדת מילת אישור,
  רץ בטרנזקציה אחת, ונחסם אם הגיבוי נלקח ממבנה בסיס נתונים אחר (גרסה שונה).
  לפני שחזור — קחו גיבוי טרי.
- אם הגיבוי הלילי נכשל, נשלח מייל לכתובת התראות הצוות והשורה מסומנת "נכשל"
  במסך. אל תתעלמו מזה.
- בנוסף, כל בוקר ב-09:00 נבדק אם בכלל הושלם גיבוי ב-36 השעות האחרונות
  (`BACKUP_STALE_AFTER_HOURS`). זה תופס את המקרה שלא משאיר שורה כושלת בכלל:
  התור קיבל את המשימה אבל אין worker שיריץ אותה.
- **הבדיקה הזו אינה יכולה לדווח על מתזמן שנעצר** — היא עצמה רצה מתוכו. לכן
  אותה בדיקה מוצגת גם כהתראה בראש מסך "גיבוי ושחזור" בכל טעינה, בלי תלות
  בשום תהליך רקע. למתזמן עצמו צריך ניטור חיצוני: הפנו שירות uptime חיצוני
  (למשל Better Stack / Healthchecks.io) שיצפה שהמערכת מדווחת, או לכל הפחות
  היכנסו למסך אחרי כל דיפלוי. תהליך שנעצר אינו יכול להתריע על עצמו.
- המתג "גיבוי אוטומטי יומי פעיל" חל על הריצה הלילית בלבד; **גבה עכשיו** עובד
  גם כשהוא כבוי.
- שחזור שנתבע ולא התחיל בפועל (התור קיבל את המשימה ואיש לא הריץ אותה) ניתן
  להשתלטות אחרי `BACKUP_RESTORE_CLAIM_MINUTES` דקות; מזהה הניסיון מתחלף, כך
  שאם ההודעה האבודה תגיע בכל זאת — היא תיעצר.
- **התאוששות מאובדן מלא של השרת:** מתקינים מחדש, מריצים `php artisan migrate`,
  מגדירים את אותם `AWS_*` ו-`BACKUP_DISK`, נכנסים למסך ולוחצים **חפש גיבויים
  ביעד** (הסריקה רצה כעבודת תור — צריך worker פעיל). רשימת הגיבויים עצמה אינה נכללת בארכיון (שחזור שלה היה מוחק את הרשימה
  באמצע הפעולה), ולכן היא נבנית מחדש מהקבצים שביעד — ומשם משחזרים כרגיל.
