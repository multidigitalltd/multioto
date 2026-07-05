תקן פיתוח Multi Digital – חובה בכל פרויקט WordPress

יש לפעול לפי כל הסעיפים הבאים ללא חריגות אלא אם אושר אחרת מראש.

ביצועים ומהירות  
לכתוב את הפתרון הפשוט והקל ביותר מבחינת משאבים.  
להימנע מספריות צד ג' כאשר ניתן להשתמש ביכולות הליבה של WordPress או JavaScript טהור.  
לא לטעון CSS או JavaScript שאינם נדרשים בעמוד הנוכחי.  
טעינת קבצים מותנית לפי עמוד, פוסט, מוצר או שורטקוד.  
להימנע מטעינת קבצים גלובלית על כל האתר.  
לצמצם את מספר בקשות HTTP ככל האפשר.  
לצמצם את גודל קבצי CSS ו-JS.  
לא להכניס CSS ו-JS כפולים.  
להשתמש ב-Minify לקבצים.  
להשתמש ב-Defer או Async לקבצי JavaScript כאשר אפשר.  
להימנע מ-jQuery אם אין צורך אמיתי.  
להשתמש ב-Vanilla JavaScript ככל האפשר.  
למנוע Render Blocking Resources.  
להשתמש ב-Lazy Load לתמונות.  
להשתמש ב-Lazy Load ל-Iframes.  
לא לבצע קריאות API בכל טעינת עמוד.  
כל קריאת API חייבת להיות במטמון כאשר ניתן.  
להשתמש ב-Transient Cache לכל מידע שאינו חייב להיות בזמן אמת.  
להשתמש ב-Object Cache כאשר מתאים.  
להשתמש ב-Persistent Cache כאשר זמין.  
להימנע מחישובים כבדים בכל טעינת עמוד.  
להעביר פעולות כבדות ל-Cron או Background Processing.  
לבצע שאילתות SQL מינימליות בלבד.  
למנוע N+1 Queries.  
לבצע Select רק לעמודות הנדרשות.  
לא לבצע SELECT \* כאשר אין צורך.  
להשתמש באינדקסים לשדות נפוצים בחיפוש.  
להימנע מלולאות מסד נתונים מיותרות.  
להשתמש ב-get\_posts במקום WP\_Query כאשר אין צורך בכל יכולות WP\_Query.  
להשתמש ב-no\_found\_rows כאשר אין צורך בפאג'ינציה.  
להגדיר update\_post\_meta\_cache=false כאשר לא נדרש.  
להגדיר update\_post\_term\_cache=false כאשר לא נדרש.  
לצמצם שימוש ב-Autoload Options.  
לא לשמור מידע גדול ב-options autoload.  
לנקות Transients ישנים.  
לנקות נתונים זמניים שאינם בשימוש.  
לא ליצור טבלאות חדשות אם ניתן להשתמש במבנה WordPress.  
להימנע משמירת נתונים כפולים.  
להשתמש בתמונות WebP או AVIF כאשר ניתן.  
לטעון גרסאות תמונה מתאימות לפי גודל מסך.  
להימנע מטעינת סרטונים אוטומטית.  
להימנע מהפעלת Cron כבד בכל ביקור.  
כל תהליך ארוך חייב לכלול Queue או Batch Processing.  
להימנע מ-Recursive Loops.  
כל AJAX חייב להיות יעיל ומהיר.  
כל Endpoint חייב להחזיר רק את המידע הנדרש.  
להימנע מחישובים מיותרים בצד הלקוח.  
הפתרון חייב לעבוד באופן תקין עם:  
LiteSpeed Cache  
Redis  
Memcached  
Cloudflare  
Object Cache Pro  
WP Rocket  
כל פיתוח חייב להיות ידידותי ל-Core Web Vitals.  
יעד ביצועים:  
Mobile PageSpeed מעל 90  
Desktop PageSpeed מעל 95  
LCP מתחת ל-2.5 שניות  
CLS מתחת ל-0.1  
INP תקין  
אבטחה  
כל קלט משתמש חייב לעבור Sanitization.  
כל פלט חייב לעבור Escaping.  
אין להציג נתוני משתמש ללא Escape מתאים.  
להשתמש ב:  
sanitize\_text\_field  
sanitize\_email  
sanitize\_key  
sanitize\_textarea\_field  
absint  
wp\_kses\_post  
להשתמש ב:  
esc\_html  
esc\_attr  
esc\_url  
wp\_kses\_post  
כל פעולה המשנה נתונים חייבת לכלול Nonce.  
כל AJAX חייב לכלול Nonce.  
כל REST API Endpoint חייב לכלול permission\_callback.  
כל פעולה חייבת לבדוק הרשאות.  
להשתמש ב-current\_user\_can לפני פעולות רגישות.  
לא לסמוך על נתונים מהדפדפן.  
לא לסמוך על Hidden Fields.  
לא לסמוך על JavaScript לבדיקות אבטחה.  
כל בדיקה חייבת להתבצע גם בצד השרת.

כל שאילתת SQL חייבת להשתמש ב:

$wpdb-\>prepare()  
אין לבצע SQL Concatenation.  
למנוע SQL Injection.  
למנוע XSS.  
למנוע CSRF.  
למנוע Privilege Escalation.  
למנוע IDOR (גישה לנתונים של משתמש אחר).  
כל פעולה חייבת לוודא שהמשתמש רשאי לגשת לנתון.  
אין לחשוף מזהים פנימיים ללא צורך.  
אין לחשוף נתונים רגישים ב-HTML.  
אין לחשוף API Keys.  
אין לחשוף סיסמאות.  
אין לחשוף Tokens.  
אין לשמור סיסמאות בטקסט גלוי.  
להשתמש במנגנוני ההצפנה של WordPress.  
להשתמש בסיסמאות אפליקציה כאשר אפשר.

אין להשתמש ב:

eval()

אין להשתמש ב:

exec()

אלא באישור מפורש.

אין להשתמש ב:

shell\_exec()

אלא באישור מפורש.

אין להשתמש ב:

system()

אלא באישור מפורש.

אין להשתמש ב:

passthru()

אלא באישור מפורש.

אין להסתיר קוד באמצעות:

base64\_encode()

אין להסתיר קוד באמצעות:

base64\_decode()  
אין להכניס קוד מוצפן או Obfuscated.  
כל העלאת קובץ חייבת לכלול:  
בדיקת סוג קובץ  
בדיקת MIME Type  
בדיקת הרשאות  
בדיקת גודל  
לא לאפשר העלאת PHP.  
לא לאפשר קבצים מסוכנים.  
למנוע Directory Traversal.  
למנוע File Inclusion.  
למנוע Remote Code Execution.  
למנוע פתיחת Redirect חיצוני לא מבוקר.  
להשתמש ב-wp\_safe\_redirect.  
לא לחשוף Stack Trace למשתמש.  
לא לחשוף שגיאות PHP למשתמש.  
לוגים חייבים להישמר בצד השרת בלבד.  
מידע רגיש לא יופיע בלוגים.  
API Keys יישמרו מחוץ לקוד כאשר אפשר.  
להשתמש ב-Environment Variables כאשר אפשר.  
להשתמש ב-Capability ולא ב-Role.  
כל REST Endpoint חייב להיות מוגן.  
כל Webhook חייב לכלול חתימת אימות.  
כל Webhook חייב לבצע אימות מקור.  
כל Webhook חייב לבצע Rate Limiting כאשר אפשר.  
למנוע Spam בטפסים.  
להשתמש ב-Honeypot כאשר אפשר.  
להשתמש ב-ReCAPTCHA רק אם נדרש.  
למנוע Brute Force בפעולות רגישות.  
להוסיף Rate Limiting לפעולות ציבוריות.  
כל פיתוח חייב לעבור סריקת אבטחה לפני מסירה.  
איכות קוד  
תאימות מלאה ל-WordPress Coding Standards.  
תאימות מלאה ל-PHP 8.3+.  
ללא Deprecated Functions.  
ללא Warning.  
ללא Notice.  
ללא Fatal Errors.  
קוד קריא וברור.  
פונקציות קצרות וממוקדות.  
שמות ברורים למשתנים.  
שמות ברורים לפונקציות.  
הפרדת לוגיקה ותצוגה.  
מניעת כפילות קוד.  
שימוש ב-DRY.  
שימוש ב-SOLID בפרויקטים מורכבים.  
תיעוד לכל פונקציה משמעותית.  
ללא קוד מת.  
ללא Debug Code.  
ללא var\_dump.  
ללא print\_r.  
ללא console.log בייצור.  
נגישות  
עמידה בתקן WCAG 2.1 AA.  
ניווט מלא במקלדת.  
תמיכה בקוראי מסך.  
Alt לכל תמונה.  
Label לכל שדה.  
ARIA כאשר נדרש.  
ניגודיות צבעים תקינה.  
HTML סמנטי.  
Focus States תקינים.  
SEO  
HTML סמנטי.  
H1 יחיד בעמוד.  
היררכיית כותרות תקינה.  
Open Graph.  
Schema כאשר רלוונטי.  
URL ידידותיים.  
תמיכה ב-SEO Plugins.  
Lazy Load לתמונות.  
מניעת תוכן כפול.  
תאימות  
WordPress Latest.  
WooCommerce Latest.  
Elementor Latest.  
JetEngine Latest.  
JetFormBuilder Latest.  
PHP 8.3 ומעלה.  
Cloudflare.  
LiteSpeed Cache.  
Redis.

Create a fully accessible website or mobile/web application that complies with Israeli Accessibility Standard Israeli Standards Institute ת"י 5568 and the international accessibility guidelines World Wide Web Consortium WCAG 2.2 Level AA (and wherever possible AAA best practices).  
The system must generate clean, semantic, responsive, keyboard-accessible, screen-reader-friendly code and UI components by default.

Accessibility requirements:

Use semantic HTML5 elements correctly (header, nav, main, section, article, footer, button, form, etc.).

Ensure full RTL and LTR language support, including Hebrew and English layouts.

All text must meet minimum contrast ratios according to WCAG AA:

Normal text: 4.5:1 minimum

Large text: 3:1 minimum

All interactive elements must be fully operable using keyboard only:

Visible focus indicators

Logical tab order

Skip-to-content link

Keyboard traps must not exist

Every image must contain meaningful alt text.

Decorative images/icons must use aria-hidden="true" or empty alt attributes.

Every form field must include:

Proper \<label\>

Placeholder is NOT a replacement for label

ARIA descriptions when needed

Error messages connected programmatically

Accessible validation

All buttons and links must contain accessible names.

Use ARIA only when semantic HTML is insufficient.

All dialogs/modals must:

Trap focus correctly

Support ESC close

Return focus to triggering element

Be screen-reader accessible

Menus, dropdowns, tabs, accordions, sliders, and carousels must support:

Keyboard navigation

ARIA states

Screen-reader announcements

Videos must support:

Captions/subtitles

Pause/stop controls

Accessible media controls

Audio must not autoplay automatically.

Avoid flashing animations or content that may trigger seizures.

Respect prefers-reduced-motion.

Ensure accessible typography:

Minimum readable font sizes

Scalable text up to 200% without layout breaking

Proper line-height and spacing

Ensure responsive accessibility on:

Desktop

Tablet

Mobile devices

Provide accessibility toolbar features:

Increase/decrease text size

High contrast mode

Invert colors

Grayscale mode

Underline links

Readable font

Stop animations

Highlight headings

Highlight links

Reading guide

Keyboard navigation helper

Store accessibility preferences locally using cookies or localStorage.

Ensure compatibility with:

Screen readers (NVDA, JAWS, VoiceOver, TalkBack)

Keyboard-only users

Voice navigation systems

All dynamic updates must announce changes using ARIA live regions where necessary.

Avoid inaccessible CAPTCHAs. Use accessible alternatives.

Generate accessible tables with proper headers and scope attributes.

Generate accessible charts with text alternatives or summaries.

Ensure error prevention for important forms (payments, registration, legal forms).

Include an accessibility statement page compliant with Israeli regulations.

Optimize performance without harming accessibility.

Maintain SEO best practices while preserving accessibility.

Do not generate inaccessible custom components when native accessible elements already exist.

All generated code must pass automated accessibility testing tools such as:

Lighthouse Accessibility

axe DevTools

WAVE

Target WCAG principles:

Perceivable

Operable

Understandable

Robust

Development rules:

Accessibility must be built-in by default, not added later.

Never sacrifice accessibility for visual effects.

Generate reusable accessible components.

Use accessible color palettes automatically.

Maintain accessibility in dark mode and light mode.

Ensure all generated pages achieve at least 95–100 accessibility score in Lighthouse.

If generating React, Vue, Flutter, WordPress, Elementor, WooCommerce, or mobile applications:

Preserve accessibility in all generated widgets/components.

Maintain ARIA attributes during dynamic rendering.

Ensure Elementor or page-builder widgets remain keyboard and screen-reader accessible.

Ensure WooCommerce/cart/checkout flows are fully accessible.

Ensure modals, popups, menus, and forms remain WCAG compliant after AJAX updates.

Before final output:

Automatically run accessibility validation checks.

Detect and fix accessibility violations.

Output only accessibility-compliant code and UI structures.

יש לפתח לפי תקן Multi Digital. הפתרון חייב להיות מאובטח, מהיר, רזה, נגיש, תואם WordPress Coding Standards, תואם PHP 8.3+, ללא פונקציות Deprecated, ללא Warning/Notice, מותאם ל-LiteSpeed Cache ו-Cloudflare, בעל מינימום שאילתות למסד הנתונים, ללא ספריות מיותרות, עם Sanitization ו-Escaping מלאים, Nonce והרשאות בכל פעולה רגישה, ותיעוד ברור של כל רכיב משמעותי.  
