@extends('portal.layout')

@section('title', 'הרישיונות שלי')

@section('content')
    <h1>הרישיונות שלי</h1>

    @if ($licenses->isEmpty())
        <div class="card">
            <p class="empty">אין רישיונות רשומים על החשבון הזה.</p>
            <p class="muted">אם רכשתם תוסף בכתובת אימייל אחרת, הרישיון רשום שם. פנו אלינו ונאחד.</p>
        </div>
    @endif

    @foreach ($licenses as $license)
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap;">
                <h2 style="margin:0;">{{ $license->product?->name ?? 'תוסף' }}</h2>
                <span class="status-chip">{{ $license->statusLabel() }}</span>
            </div>

            <p class="muted" style="margin-top:.4rem;">
                מפתח <span dir="ltr">{{ $license->key_prefix }}…</span> ·
                {{ $license->isUnlimited() ? 'אתרים ללא הגבלה' : $license->seatsUsed().' מתוך '.$license->sites_limit.' אתרים בשימוש' }}
            </p>

            {{-- The sentence that stops the support mail: what happens to the
                 plugin itself when updates end. It is never "it stops working",
                 and that has to be said before somebody assumes otherwise. --}}
            <p>{{ $license->updatesSummary() }}</p>

            <h3 style="font-size:.95rem;margin:1.1rem 0 .4rem;">אתרים שהרישיון פעיל בהם</h3>

            @if ($license->sites->isEmpty())
                <p class="empty">הרישיון עדיין לא הופעל באף אתר. התקינו את התוסף והזינו את המפתח בהגדרות שלו.</p>
            @else
                <div class="table-scroll">
                    <table>
                        <caption class="muted" style="caption-side:top;text-align:right;padding:.2rem 0 .5rem;font-size:.85rem;">
                            אתר שכבר לא קיים או שהוחלף — שחררו אותו כאן כדי לפנות מקום.
                        </caption>
                        <thead>
                            <tr>
                                <th>אתר</th>
                                <th>גרסה מותקנת</th>
                                <th>נראה לאחרונה</th>
                                <th><span class="visually-hidden">פעולה</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($license->sites as $site)
                                <tr>
                                    <td dir="ltr" style="text-align:right;">{{ $site->site_url }}</td>
                                    <td>{{ $site->version ?: '—' }}</td>
                                    <td>{{ $site->last_seen_at?->diffForHumans() ?? 'מעולם' }}</td>
                                    <td>
                                        <form method="POST"
                                              action="{{ route('portal.licenses.release', ['license' => $license, 'site' => $site]) }}"
                                              onsubmit="return confirm('לשחרר את {{ $site->site_url }} מהרישיון? המקום יתפנה, והתוסף באתר הזה יפסיק לקבל עדכונים.');">
                                            @csrf
                                            <button type="submit" class="btn ghost" style="padding:.4rem .8rem;">
                                                שחרור<span class="visually-hidden"> של האתר {{ $site->site_url }}</span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.1rem;">
                @if ($license->entitledRelease() !== null)
                    <a class="btn" href="{{ route('portal.licenses.download', $license) }}">
                        הורדת התוסף (גרסה {{ $license->entitledRelease()->number() }})
                    </a>
                @endif

                @unless ($license->isRevoked())
                    <form method="POST" action="{{ route('portal.licenses.key', $license) }}"
                          onsubmit="return confirm('להנפיק מפתח חדש? המפתח הנוכחי יפסיק לעבוד מיד, ותצטרכו להזין את החדש בכל אתר.');">
                        @csrf
                        <button type="submit" class="btn ghost">איבדתי את המפתח — הנפקת מפתח חדש</button>
                    </form>
                @endunless
            </div>

            {{-- Placed under the button rather than in the confirmation alone:
                 somebody who reads this as "resend my key" will break every
                 working installation they have and not connect the two. --}}
            @unless ($license->isRevoked())
                <p class="muted" style="margin-top:.5rem;font-size:.88rem;">
                    את המפתח עצמו אי אפשר לשלוח שוב — הוא לא נשמר אצלנו, וזו בחירה מכוונת. אפשר רק להנפיק מפתח
                    חדש במקומו, והישן מפסיק לעבוד באותו רגע.
                </p>
            @endunless
        </div>
    @endforeach
@endsection
