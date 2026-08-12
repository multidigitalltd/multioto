{{-- הפרטים המלאים של כישלון אחד — לפני שמחליטים לנסות שוב או למחוק.
     ה-stack trace מוצג במלואו כי לפעמים הוא התשובה היחידה, אבל הוא בסוף
     ומקופל למסגרת גלילה: השאלה "מה זה היה ומה נשבר" נענית לפניו. --}}
<div class="space-y-3 text-sm" dir="rtl">
    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">מה לא בוצע</div>
        <div class="font-medium">{{ $job->label() }}</div>
        @if ($job->meaning())
            <div class="text-gray-600 dark:text-gray-300">{{ $job->meaning() }}</div>
        @endif
    </div>

    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">מתי</div>
        <div>{{ $job->failed_at?->format('d/m/Y H:i:s') }} · {{ $job->failed_at?->diffForHumans() }}</div>
    </div>

    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">מה נשבר</div>
        <div class="font-medium">{{ $job->shortError() }}</div>
        <div class="mt-1 text-xs {{ $job->looksTransient() ? 'text-amber-600 dark:text-amber-400' : 'text-danger-600 dark:text-danger-400' }}">
            @if ($job->looksTransient())
                נראית כתקלה זמנית (רשת, שירות חיצוני שלא ענה) — ניסיון חוזר בדרך כלל יספיק.
            @else
                אינה נראית זמנית — ניסיון חוזר צפוי להיכשל שוב באותו מקום עד שהסיבה תטופל.
            @endif
        </div>
    </div>

    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">שם טכני · תור</div>
        <div style="unicode-bidi:plaintext">{{ $job->jobClass() }} · {{ $job->queue }}</div>
    </div>

    <details>
        <summary class="cursor-pointer text-xs text-gray-500 dark:text-gray-400">הפירוט הטכני המלא</summary>
        <pre class="mt-2 max-h-72 overflow-auto rounded bg-gray-50 p-2 text-xs dark:bg-gray-900"
             style="direction:ltr;text-align:left;white-space:pre-wrap">{{ $job->exception }}</pre>
    </details>
</div>
