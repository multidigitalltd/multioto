{{--
    Read-only list of the site's Cloudflare IP Access Rules, so the team can
    verify a whitelist/block from the panel instead of the Cloudflare dashboard.
    $result is the array returned by CloudflareClient::listAccessRules().
--}}
@php
    // Human labels for the modes Cloudflare returns.
    $modeLabels = [
        'whitelist' => 'מעבר חופשי (Allow)',
        'block' => 'חסימה',
        'challenge' => 'אתגר (CAPTCHA)',
        'js_challenge' => 'אתגר JavaScript',
        'managed_challenge' => 'אתגר מנוהל',
    ];
    $targetLabels = ['ip' => 'IP', 'ip_range' => 'טווח IP', 'country' => 'מדינה', 'asn' => 'ASN'];
@endphp

<div dir="rtl" class="cf-rules space-y-3 text-sm">
    @if (! ($result['ok'] ?? false))
        <p class="cf-rules-error">{{ $result['message'] ?? 'הפנייה ל-Cloudflare נכשלה.' }}</p>
    @elseif (empty($result['rules']))
        <p class="cf-rules-muted">אין כרגע כללי IP Access Rules על הזון הזה ב-Cloudflare.</p>
    @else
        <p class="cf-rules-muted">{{ count($result['rules']) }} כללים על הזון של האתר:</p>
        <div class="cf-rules-scroll">
            <table class="cf-rules-table">
                <thead>
                    <tr>
                        <th>יעד</th>
                        <th>ערך</th>
                        <th>פעולה</th>
                        <th>הערה</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['rules'] as $rule)
                        <tr>
                            <td>{{ $targetLabels[$rule['target']] ?? $rule['target'] }}</td>
                            <td dir="ltr" class="cf-rules-value">{{ $rule['value'] }}</td>
                            <td>{{ $modeLabels[$rule['mode']] ?? $rule['mode'] }}</td>
                            <td class="cf-rules-note">{{ $rule['notes'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Scoped styles: the panel ships only Filament's purged stylesheet, so
         app-specific utilities won't render — keep styling self-contained. --}}
    <style>
        .cf-rules-muted { color: #6b7280; }
        .cf-rules-error { color: #b91c1c; font-weight: 600; }
        .cf-rules-scroll { overflow-x: auto; }
        .cf-rules-table { width: 100%; border-collapse: collapse; }
        .cf-rules-table th, .cf-rules-table td {
            text-align: right; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
        }
        .cf-rules-table th { font-weight: 600; color: #374151; }
        .cf-rules-value { font-family: ui-monospace, monospace; }
        .cf-rules-note { white-space: normal; color: #6b7280; }
        .dark .cf-rules-muted, .dark .cf-rules-note { color: #9ca3af; }
        .dark .cf-rules-error { color: #f87171; }
        .dark .cf-rules-table th { color: #d1d5db; }
        .dark .cf-rules-table th, .dark .cf-rules-table td { border-bottom-color: #374151; }
    </style>
</div>
