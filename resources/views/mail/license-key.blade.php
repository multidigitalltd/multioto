<x-mail::message>
<div dir="rtl">

@if ($isReplacement)
<p>שלום,</p>
<p>הנפקנו לך מפתח רישיון חדש ל<strong>{{ $plugin }}</strong>. <strong>המפתח הקודם כבר אינו פועל</strong> — יש להזין את החדש בכל אתר שבו התוסף מותקן.</p>
@else
<p>שלום,</p>
<p>תודה על הרכישה! להלן מפתח הרישיון שלך ל<strong>{{ $plugin }}</strong>.</p>
@endif

<p style="font-size:20px;font-family:monospace;letter-spacing:2px;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:8px;padding:14px;text-align:center;direction:ltr">
{{ $key }}
</p>

{{-- The one sentence that saves a support ticket a month from now: this email
     is the only copy. We cannot read the key back — that is what keeps it safe
     if our database is ever stolen, and it is also why losing it means
     replacing it. --}}
<p style="font-size:13px;color:#52525b">שמרו את המייל הזה. המפתח אינו נשמר אצלנו וגם אנחנו לא יכולים לשחזר אותו — אם הוא יאבד, נצטרך להנפיק מפתח חדש, והישן יפסיק לעבוד.</p>

<p><strong>איך מפעילים:</strong> בלוח הבקרה של האתר → הגדרות התוסף → רישיון, מדביקים את המפתח ולוחצים "הפעלת רישיון".</p>

@if ($sites)
<p>הרישיון מכסה עד <strong>{{ $sites }}</strong> {{ $sites == 1 ? 'אתר' : 'אתרים' }}. אם העברתם אתר או סגרתם אותו — אפשר לשחרר אותו מתוך הגדרות התוסף באותו אתר, והמקום יתפנה.</p>
@endif

{{-- The way out when the shop is already gone: the plugin cannot release a seat
     from a site that no longer exists, and that is exactly when somebody needs
     the seat back. --}}
<p>באזור האישי — <a href="{{ route('portal.licenses') }}">{{ route('portal.licenses') }}</a> — אפשר לראות באילו אתרים הרישיון פעיל, לשחרר אתר שנמחק או הוחלף, ולהוריד את התוסף שוב. הכניסה בקישור חד-פעמי לכתובת האימייל הזו.</p>

@if ($expires)
<p>הרישיון בתוקף עד <strong>{{ $expires }}</strong>. {{ $license->subscription_id ? 'החידוש אוטומטי — אין צורך לעשות דבר.' : 'נזכיר לכם לפני שהוא פג.' }}</p>
<p style="font-size:13px;color:#52525b">גם אם התוקף יפוג, <strong>התוסף ימשיך לעבוד באתר כרגיל</strong> — רק העדכונים ייעצרו עד לחידוש.</p>
@else
<p>הרישיון ללא הגבלת זמן.</p>
@endif

<p>בברכה,<br>צוות מולטי דיגיטל</p>
</div>
</x-mail::message>
