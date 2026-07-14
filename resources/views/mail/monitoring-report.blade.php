<x-mail::message>
<div dir="rtl" style="text-align: right !important; direction: rtl !important;">

# שלום {{ $customer->name }},

להלן דוח הניטור של האתרים שלך עבור {{ $report['window_days'] }} הימים האחרונים.

@foreach ($report['sites'] as $site)
<div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin: 0 0 12px;">

**🌐 {{ $site['domain'] }}**

<table width="100%" cellpadding="4" cellspacing="0" style="direction: rtl; text-align: right; font-size: 14px;">
<tr>
<td>זמינות (Uptime)</td>
<td><strong>{{ $site['uptime'] !== null ? number_format($site['uptime'], 2).'%' : '—' }}</strong></td>
</tr>
<tr>
<td>זמן תגובה ממוצע</td>
<td>{{ $site['avg_ms'] !== null ? number_format($site['avg_ms']).' ms' : '—' }}</td>
</tr>
<tr>
<td>אירועי השבתה</td>
<td>{{ $site['incidents'] }}@if ($site['incidents'] > 0) ({{ $site['down_minutes'] }} דק׳ סה״כ)@endif</td>
</tr>
@if ($site['ssl_days_left'] !== null)
<tr>
<td>תעודת SSL</td>
<td>{{ $site['ssl_days_left'] > 0 ? 'תקפה ('.$site['ssl_days_left'].' ימים)' : 'פגה — לחידוש' }}</td>
</tr>
@endif
@if ($site['domain_expiry_at'])
<tr>
<td>תוקף הדומיין</td>
<td>{{ \Illuminate\Support\Carbon::parse($site['domain_expiry_at'])->format('d/m/Y') }}</td>
</tr>
@endif
</table>

</div>
@endforeach

תודה שבחרת בנו לתחזק ולנטר את האתרים שלך. לכל שאלה — פשוט השב/י למייל זה.

{!! \App\Support\Branding::emailFooter() !!}

</div>
</x-mail::message>
