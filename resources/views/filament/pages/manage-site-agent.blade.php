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
                במסך <strong>הפעלת סוכן לאתר</strong>.
            </p>
        </x-filament::section>
    @endif

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

        <div class="space-y-4 text-sm">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">קוד אימות — קטגוריית Authentication</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    נשלחת למספר שמעולם לא כתב אלינו, ולכן חייבת להיות תבנית.
                    <code>@{{1}}</code> הוא הקוד בן שש הספרות.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">מנוי מושהה — קטגוריית Utility</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    <code>@{{1}}</code> הדומיין של הלקוח, <code>@{{2}}</code> מה לעשות כדי לחדש.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="font-medium text-gray-900 dark:text-gray-100">מנוי חזר — קטגוריית Utility</div>
                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    <code>@{{1}}</code> הדומיין של הלקוח.
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
