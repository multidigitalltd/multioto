@extends('portal.layout')

@section('title', 'חשבוניות')

@section('content')
    <h1>חשבוניות</h1>

    <div class="card">
        @if ($invoices->isEmpty())
            <p class="empty">עדיין אין חשבוניות באזור האישי.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>תאריך</th>
                            <th>סוג מסמך</th>
                            <th>מספר</th>
                            <th>סכום</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td>{{ $invoice->issued_at?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $invoice->document_type?->getLabel() ?? 'חשבונית' }}</td>
                                <td dir="ltr" style="text-align:right;">{{ $invoice->allocation_number ?? $invoice->linet_document_id ?? '—' }}</td>
                                <td>{{ \App\Support\Money::ils((int) $invoice->total_agorot) }}</td>
                                <td>
                                    @if ($invoice->pdf_url)
                                        <a href="{{ $invoice->pdf_url }}" class="btn ghost" target="_blank" rel="noopener" style="padding:.4rem .8rem;">הורדה</a>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
