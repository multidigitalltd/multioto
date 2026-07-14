<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>משימות פתוחות — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            color: #16181d; margin: 24px; line-height: 1.5;
        }
        header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px; }
        h1 { font-size: 1.4rem; margin: 0; }
        .meta { color: #55606e; font-size: .85rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: right; padding: 8px 10px; border-bottom: 1px solid #d7dbe0; vertical-align: top; }
        th { background: #f4f5f7; font-weight: 600; }
        .overdue { color: #b42318; font-weight: 600; }
        .muted { color: #7a828c; }
        .empty { padding: 32px; text-align: center; color: #55606e; }
        @media print {
            body { margin: 0; }
            .noprint { display: none; }
            th { background: #f4f5f7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body onload="window.print()">
    <header>
        <h1>משימות פתוחות</h1>
        <span class="meta">הופק: {{ $generatedAt->format('d/m/Y H:i') }} · סה״כ {{ $tasks->count() }} משימות</span>
    </header>

    @if ($tasks->isEmpty())
        <p class="empty">אין משימות פתוחות 🎉</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>כותרת</th>
                    <th>אחראים</th>
                    <th>לקוח</th>
                    <th>תת-משימות</th>
                    <th>עדיפות</th>
                    <th>סטטוס</th>
                    <th>מועד יעד</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tasks as $task)
                    @php($overdue = $task->due_at && $task->due_at->isPast())
                    @php([$done, $total] = $task->subtaskProgress())
                    <tr>
                        <td>{{ $task->title }}</td>
                        <td>{{ $task->assignees->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td>{{ $task->customer?->name ?? '—' }}</td>
                        <td>{{ $total > 0 ? "{$done}/{$total}" : '—' }}</td>
                        <td>{{ $task->priority?->getLabel() ?? '—' }}</td>
                        <td>{{ $task->status?->getLabel() ?? '—' }}</td>
                        <td class="{{ $overdue ? 'overdue' : '' }}">
                            {{ $task->due_at?->format('d/m/Y') ?? '—' }}@if ($overdue) (באיחור)@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
