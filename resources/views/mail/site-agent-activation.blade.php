<x-mail::message>
<div dir="rtl">

<p>שלום,</p>
<p>תודה על הרכישה! <strong>סוכן האתר פעיל עבור {{ $order->domain }}</strong>.</p>

<p><strong>הדבר הראשון:</strong> שלחנו קוד בן 6 ספרות בוואטסאפ למספר
<span dir="ltr">{{ $order->manager_phone }}</span>. יש להשיב עליו באותה שיחה.
עד שהמספר יאומת הוא אינו יכול לעשות דבר באתר.</p>

@if ($installedByUs)
<p><strong>ההתקנה אצלנו.</strong> ביקשתם שנתקין עבורכם — בעמוד שבקישור למטה אפשר למסור לנו
גישה לאתר, ואנחנו נמשיך משם. הדרך המומלצת היא קישור התחברות זמני, שפג מעצמו
ואפשר לבטל אותו בכל רגע.</p>
@else
<p><strong>ההתקנה אצלכם — כחמש דקות.</strong> בעמוד שבקישור למטה יש את קובץ התוסף
ואת שלושת הקודים שצריך להדביק בהגדרות התוסף בוורדפרס. אחרי השמירה, שלחו הודעה
בוואטסאפ ותראו שהסוכן עונה.</p>
@endif

<x-mail::button :url="$link">לעמוד ההפעלה</x-mail::button>

{{-- The codes themselves are deliberately not in this mail. A mailbox is
     forwarded, synced to phones and breached more often than anybody plans for,
     and these are the keys to the customer's own website. The link points at a
     page we can stop serving; an email we cannot take back. --}}
<p style="font-size:13px;color:#52525b">שמרו את הקישור הזה — זה העמוד שבו נמצאים קודי ההתקנה,
ואפשר לחזור אליו בכל עת. לא צירפנו את הקודים עצמם למייל בכוונה: הם המפתחות לאתר שלכם.
אל תעבירו את הקישור לאף אחד שאינכם רוצים שינהל לכם את האתר.</p>

<p>לניהול המנוי, להוספת מספר מנהל נוסף ולחשבוניות —
<a href="{{ route('portal.login') }}">האזור האישי</a>, עם כתובת האימייל הזו.</p>

<p>בברכה,<br>צוות מולטי דיגיטל</p>

</div>
</x-mail::message>
