<x-filament-panels::page>
    @php $missing = $this->missing(); @endphp

    {{--
        What is missing, before the fields that fix it. A settings page that
        opens on a form invites the reader to assume the form is already right;
        this product's failure mode is that everything looks right and the
        customer's phone never beeps.
    --}}
    @if ($missing !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">השירות עדיין לא מוכן לעבודה</x-slot>
            <x-slot name="description">
                עד שכל אלה יוגדרו, המוצר לא פועל במלואו — ובחלק מהמקרים בלי שום שגיאה שתראו במסך.
            </x-slot>

            <ul class="space-y-2">
                @foreach ($missing as $item)
                    <li class="flex items-start gap-2 text-sm">
                        <x-filament::badge color="danger">חסר</x-filament::badge>
                        <span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item['label'] }}</span>
                            <span class="text-gray-600 dark:text-gray-400">— {{ $item['detail'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
            <x-slot name="heading">השירות מוגדר ופעיל</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                המספר יכול לשלוח ולקבל, והתבניות מוגדרות. מכאן ואילך מפעילים לקוח חדש
                במסך <strong>הפעלת בוט ניהול אתר</strong>.
            </p>
        </x-filament::section>
    @endif

    {{--
        מה שאף שדה בטופס אינו יכול לומר: האם משהו בכלל מגיע ממטא.

        מסירה נכנסת עוברת שני שלבים שאיש כאן אינו רואה — מטא מחליטה אם לשלוח
        בכלל, והחתימה שלנו מחליטה אם לקבל. שני הכישלונות נראים זהים מבחוץ: הלקוח
        כותב ולא קורה כלום. ההבחנה ביניהם היא כל הערך של הקטע הזה, כי התיקון שונה
        לחלוטין — האחד אצל מטא, השני בשדה אחד במסך הזה.
    --}}
    @php $inbound = $this->inboundHealth(); @endphp

    <x-filament::section
        class="mt-6"
        :icon="match ($inbound['verdict']) { 'ok' => 'heroicon-o-check-circle', 'rejected' => 'heroicon-o-shield-exclamation', 'unready' => 'heroicon-o-ellipsis-horizontal-circle', 'foreign' => 'heroicon-o-arrows-right-left', default => 'heroicon-o-signal-slash' }"
        :icon-color="match ($inbound['verdict']) { 'ok' => 'success', 'rejected' => 'danger', 'unready' => 'gray', default => 'warning' }">
        <x-slot name="heading">הודעות נכנסות ממטא</x-slot>

        @if ($inbound['verdict'] === 'unready')
            {{-- לא אומרים "שום דבר לא הגיע" על מוצר שעדיין לא מוגדר: זה היה
                 שולח את מנהל המערכת לחפש אצל מטא שדה שריק כאן, במסך הזה. --}}
            <p class="text-sm text-gray-600 dark:text-gray-400">
                הבדיקה הזאת תתחיל לעבוד אחרי שהשירות יופעל וההגדרות יושלמו.
                מה שחסר מופיע למעלה, במצב המוצר.
            </p>
        @elseif ($inbound['verdict'] === 'ok')
            @if ($inbound['accepted'] !== null)
                <p class="text-sm">
                    המסירה האחרונה התקבלה ואומתה
                    <strong>{{ $inbound['accepted']->diffForHumans() }}</strong>
                    ({{ $inbound['accepted']->format('d/m/Y H:i') }}). הערוץ עובד.
                </p>
            @else
                {{-- יומן ה-webhooks נמחק אחרי תקופת השמירה, ולכן "אין רשומה"
                     אינו "לא עבד מעולם": מספר שאומת ענה בוואטסאפ, וזו הוכחה
                     שאינה נמחקת. --}}
                <p class="text-sm">
                    הערוץ עבד — מספר אומת בתשובה שהגיעה דרכו. המסירה האחרונה
                    מוקדמת מתקופת שמירת יומן ה-webhooks, ולכן אין לה תאריך מדויק כאן.
                </p>
            @endif
            @if ($inbound['rejected'] !== null)
                {{-- מסירה תקינה שהגיעה אחרי הדחייה היא הוכחה חיה שהסוד שבשימוש
                     עכשיו הוא הנכון. אזהרה שאומרת "הסוד כנראה אינו תואם" מעל
                     חיווי שאומר "הערוץ עובד" היא מסך שסותר את עצמו בכתב. --}}
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    לידיעה: מסירה נדחתה ב-{{ $inbound['rejected']->format('d/m/Y H:i') }}, ומאז התקבלה
                    מסירה שעברה אימות — כלומר הסוד שמוגדר כאן תקין, והדחייה ההיא כבר אינה תקלה פתוחה.
                </p>
            @endif
        @elseif ($inbound['verdict'] === 'rejected')
            <p class="text-sm text-danger-700 dark:text-danger-400">
                <strong>הודעות מגיעות — ואנחנו דוחים אותן.</strong>
                הדחייה האחרונה: {{ $inbound['rejected']->diffForHumans() }}
                ({{ $inbound['rejected']->format('d/m/Y H:i') }}).
            </p>
            <p class="mt-2 text-sm">
                משמעות הדבר אחת: <strong>סוד האפליקציה (App secret) כאן אינו זהה לזה שבאפליקציה שבמטא</strong>.
                כל מסירה נחתמת בו, ומסירה שאי אפשר לאמת נדחית — כי משלוח שאי אפשר לאמת הוא משלוח מכל אחד.
                העתיקו אותו מחדש מ-App settings ← Basic ← App secret, ושמרו כאן.
            </p>
        @elseif ($inbound['verdict'] === 'foreign')
            {{-- הערוץ מוכיח את עצמו, והתקלה היא במספר. אמירת "הבעיה אצל מטא"
                 כאן הייתה שולחת לפרסם אפליקציה שכבר פורסמה. --}}
            <p class="text-sm text-warning-700 dark:text-warning-400">
                <strong>מסירות מגיעות ועוברות אימות — אבל אף אחת אינה למספר שמוגדר כאן.</strong>
                האחרונה: {{ $inbound['delivered']->diffForHumans() }}
                ({{ $inbound['delivered']->format('d/m/Y H:i') }}).
            </p>
            <p class="mt-2 text-sm">
                כלומר <strong>הערוץ עצמו תקין לחלוטין</strong>: האפליקציה מחוברת, הכתובת נכונה והסוד נכון.
                הוובהוק נרשם לפי חשבון הוואטסאפ ולא לפי מספר, כך שחשבון שמחזיק כמה מספרים מעביר את כולם
                לאותה כתובת — ואנחנו מסננים את מה שאינו שלנו.
            </p>
            <ul class="mt-2 list-disc space-y-1 text-sm" style="padding-inline-start:1.25rem">
                <li>מזהה המספר (Phone number ID) שמוגדר כאן הוא של המספר שאליו כותבים?</li>
                <li>המספר נמצא בחשבון הוואטסאפ שהאפליקציה רשומה אליו?</li>
            </ul>
        @else
            <p class="text-sm text-warning-700 dark:text-warning-400">
                <strong>לא התקבלה אף מסירה ממטא, ואף אחת גם לא נדחתה.</strong>
                כלומר שום דבר לא הגיע עד הדלת — הבעיה אינה בהגדרות שבמסך הזה.
            </p>
            <ul class="mt-2 list-disc space-y-1 text-sm" style="padding-inline-start:1.25rem">
                <li>האפליקציה במטא פורסמה? אפליקציה שלא פורסמה אינה מקבלת הודעות אמיתיות כלל.</li>
                <li>בכתובת ה-Webhook, השדה <code>messages</code> מסומן Subscribed?</li>
                <li>הכתובת שמוגדרת שם היא בדיוק <code dir="ltr">{{ $this->webhookUrl() }}</code>?</li>
                <li>ההודעה נשלחה למספר הנכון, זה שמזהה המספר כאן שייך לו?</li>
            </ul>
        @endif
    </x-filament::section>

    <form wire:submit="save" class="mt-6">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                שמירת הגדרות המוצר
            </x-filament::button>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            הסודות נשמרים מוצפנים ואינם מוצגים חזרה — שדה ריק פירושו "אל תשנה", לא "מחק".
        </p>
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-document-text">
        <x-slot name="heading">התבניות שצריך לאשר אצל מטא</x-slot>
        <x-slot name="description">
            שלוש תבניות, פעם אחת. עד שהן מאושרות ושמותיהן מוזנים למעלה — כל הודעה שאנחנו
            מתחילים נדחית על ידי מטא, בלי שגיאה שמגיעה אליכם.
        </x-slot>

        {{--
            צורת המשתנה אינה עניין של העדפה אלא של הקטגוריה, ולכן אין כאן מתג:

            Authentication — מטא כותבת את הגוף בעצמה, והמשתנה בו מיקומי ({{1}}).
            אין לו שם שאפשר לתת לו, ומשתנה בשם נדחה בשליחה.

            Utility — הגוף נכתב על ידינו, והמשתנים בו בשמות ({{domain}}). מטא
            דורשת אותיות קטנות וקו תחתון יחיד.

            זה היה מתג שנשען על הגדרה שאינה קיימת, ולכן תמיד הציג שם גם לתבנית
            האימות — כלומר הנחה לבנות בדיוק את התבנית שתידחה בשליחה הראשונה,
            בלי שגיאה שתגיע למסך הזה.
        --}}
        <div class="space-y-4 text-sm">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">קוד אימות — קטגוריית Authentication</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    נשלחת למספר שמעולם לא כתב אלינו, ולכן חייבת להיות תבנית.
                    את הגוף כותבת מטא, והמשתנה בו <strong>מיקומי</strong>:
                    <code dir="ltr">@{{1}}</code> הוא הקוד בן שש הספרות.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">מנוי מושהה — קטגוריית Utility</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    <code dir="ltr">@{{domain}}</code> הדומיין של הלקוח. הקישור לחידוש
                    (<code>{{ rtrim(config('app.url'), '/') }}/portal/login</code>) נכתב
                    כטקסט קבוע בגוף התבנית ואינו משתנה.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">מנוי חזר — קטגוריית Utility</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    <code dir="ltr">@{{domain}}</code> הדומיין של הלקוח.
                </p>
            </div>
            <p class="text-gray-500 dark:text-gray-400">
                מטא דוחה תבנית שהגוף שלה <strong>מתחיל או מסתיים במשתנה</strong>, ושני משתנים צמודים —
                לכן צריך משפט לפני המשתנה הראשון ואחרי האחרון. אל תוסיפו Header, Footer או כפתורים
                מעבר לכפתור העתקת הקוד בתבנית האימות: המערכת שולחת רכיב גוף בלבד, ורכיב שאינו קיים בתבנית נדחה.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
