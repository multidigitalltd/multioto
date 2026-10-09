@extends('portal.layout')

@section('title', 'פרטי מבצע לקטגוריה')

@section('content')
    <h1>מבצע לקטגוריה: {{ data_get($review, 'category.name') }}</h1>
    <div class="card">
        <p>אתר: <bdi dir="ltr">{{ $proposal->site->domain }}</bdi></p>
        <p><strong>מצב ההצעה: {{ $status }}</strong></p>
        <p>
            הנחה: <bdi>{{ $review['discount_value'] ?? '' }}</bdi>
            {{ ($review['discount_type'] ?? '') === 'percent' ? '%' : ($review['currency'] ?? 'ILS') }}
            מהמחיר הרגיל.
        </p>
        <p>תחילה: <bdi>{{ data_get($review, 'schedule.starts_at') ?: 'מיד לאחר האישור' }}</bdi></p>
        <p>סיום: <bdi>{{ data_get($review, 'schedule.ends_at') }}</bdi></p>
        <p>אזור זמן: <bdi dir="ltr">{{ data_get($review, 'schedule.timezone') }}</bdi></p>
        <p>{{ data_get($review, 'category.include_children', true) ? 'כולל תתי־קטגוריות.' : 'הקטגוריה שנבחרה בלבד, ללא תתי־קטגוריות.' }}</p>
        <p>המבצע חל על {{ count($review['products']) }} מוצרים או וריאציות המפורטים כאן. מוצרים שיתווספו לאחר האישור לא יצורפו אוטומטית.</p>
        <p>בסיום מחיר המבצע יוסר והמחיר יחזור למחיר הרגיל. מבצע קודם שהוחלף לא יופעל מחדש אוטומטית.</p>
        @if ($state === \App\Models\SiteAgentRequest::AWAITING)
            <p><strong>לאחר הבדיקה, חזרו להצעה בוואטסאפ וענו ״כן״ לאישור או ״לא״ לביטול. פתיחת העמוד אינה מפעילה את המבצע.</strong></p>
        @else
            <p class="muted">זהו תיעוד ההצעה שהוצגה, ולא קריאה של המחירים הנוכחיים בחנות.</p>
        @endif
    </div>

    <div class="card">
        <h2 id="prices-heading">כל המוצרים והמחירים בהצעה</h2>
        <div class="table-scroll" role="region" aria-labelledby="prices-heading" tabindex="0">
            <table>
                <caption>המחירים כפי שנשמרו בחנות, במטבע {{ $review['currency'] ?? 'ILS' }}. הגדרות המס של החנות קובעות את המחיר המוצג לקונה.</caption>
                <thead><tr><th scope="col">מוצר / וריאציה</th><th scope="col">מחיר רגיל</th><th scope="col">מבצע קודם</th><th scope="col">מחיר המבצע החדש</th></tr></thead>
                <tbody>
                    @foreach ($review['products'] as $product)
                        <tr>
                            <th scope="row">{{ $product['name'] ?? '' }}</th>
                            <td><bdi>{{ data_get($product, 'before.regular_price') }}</bdi></td>
                            <td>
                                <bdi>{{ data_get($product, 'before.sale_price') !== '' && data_get($product, 'before.sale_price') !== null ? data_get($product, 'before.sale_price') : 'ללא' }}</bdi>
                                @if (data_get($product, 'before.sale_from') || data_get($product, 'before.sale_to'))
                                    <div class="muted">מ־<bdi>{{ data_get($product, 'before.sale_from') ?: 'מיידי' }}</bdi> עד <bdi>{{ data_get($product, 'before.sale_to') ?: 'ללא מועד סיום' }}</bdi></div>
                                @endif
                            </td>
                            <td><strong><bdi>{{ data_get($product, 'after.sale_price') }}</bdi></strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if (! empty($review['excluded']))
        <div class="card">
            <h2>פריטים שלא ייכללו במבצע</h2>
            <ul>
                @foreach ($review['excluded'] as $excluded)
                    <li>{{ $excluded['name'] ?? '' }} — {{ $excluded['reason'] ?? '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (! empty($review['notes']))
        <div class="card">
            <h2>פרטים נוספים על המבצע</h2>
            @foreach ($review['notes'] as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif
    <p><a href="{{ route('portal.site-agent') }}">חזרה לבוט ניהול האתר</a></p>
@endsection
