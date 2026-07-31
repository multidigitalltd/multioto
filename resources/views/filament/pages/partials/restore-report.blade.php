<div class="max-h-96 overflow-y-auto text-sm">
    @if ($items === [])
        <p class="py-2">
            הבדיקה לא מצאה רשומות חסרות בטווח שהספיקה לסרוק, והיא נעצרה לפני סופו של הטווח.
            יש להשוות ידנית מול הדוחות בקארדקום ובלינט — במיוחד את החיובים והמסמכים האחרונים שלפני השחזור.
        </p>
    @else
        <ul class="space-y-1">
            @foreach ($items as $item)
                <li class="border-b border-gray-100 py-1 dark:border-gray-800">{{ $item }}</li>
            @endforeach
        </ul>

        @if ($truncated ?? false)
            <p class="pt-3">
                הרשימה חלקית: הסריקה נעצרה בתקרה ולא הגיעה לרשומות האחרונות. יש להשלים ידנית מול קארדקום ולינט.
            </p>
        @endif
    @endif
</div>
