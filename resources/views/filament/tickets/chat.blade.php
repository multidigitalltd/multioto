<x-filament-panels::page>
    @php
        $ticket = $this->record;
    @endphp

    {{-- Legible defaults for the sanitized rich email body: keep the sender's
         paragraphs, lists, emphasis and links readable inside the bubble. --}}
    <style>
        .chat-rich { word-break: break-word; }
        .chat-rich p { margin: 0 0 .5rem; }
        .chat-rich p:last-child { margin-bottom: 0; }
        .chat-rich ul, .chat-rich ol { margin: .25rem 0; padding-inline-start: 1.5rem; }
        .chat-rich ul { list-style: disc; }
        .chat-rich ol { list-style: decimal; }
        .chat-rich li { margin: .125rem 0; }
        .chat-rich a { color: rgb(37 99 235); text-decoration: underline; }
        .dark .chat-rich a { color: rgb(96 165 250); }
        .chat-rich strong, .chat-rich b { font-weight: 700; }
        .chat-rich em, .chat-rich i { font-style: italic; }
        .chat-rich blockquote { margin: .25rem 0; padding-inline-start: .75rem; border-inline-start: 3px solid rgb(209 213 219); color: rgb(107 114 128); }
        .chat-rich h1, .chat-rich h2, .chat-rich h3, .chat-rich h4 { font-weight: 700; margin: .5rem 0 .25rem; }
    </style>

    {{-- Context strip: who + channel + status at a glance. --}}
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">{{ $ticket->senderName() }}</span>
        <x-filament::badge>{{ $ticket->channel->getLabel() }}</x-filament::badge>
        <x-filament::badge>{{ $ticket->status->getLabel() }}</x-filament::badge>
        @if ($ticket->customer)
            <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $ticket->customer]) }}"
               class="text-primary-600 hover:underline">לכרטיס הלקוח ←</a>
        @endif
    </div>

    {{-- The conversation. Polls so live WhatsApp exchanges appear like a chat. --}}
    <div wire:poll.15s role="log" aria-label="שיחה" aria-live="polite"
         class="flex flex-col gap-3 rounded-xl bg-gray-50 p-4 dark:bg-gray-900" style="min-height: 20rem; max-height: 55vh; overflow-y: auto;">
        @forelse ($this->messages as $message)
            @php
                $inbound = $message->direction === \App\Enums\MessageDirection::Inbound;
                $note = $message->channel === \App\Enums\MessageChannel::InternalNote;
                $authorLabel = match ($message->author) {
                    \App\Enums\MessageAuthor::Customer => $ticket->customer?->name ?? ($ticket->contact_name ?: 'לקוח'),
                    \App\Enums\MessageAuthor::Agent => 'צוות',
                    \App\Enums\MessageAuthor::System => 'מערכת',
                    \App\Enums\MessageAuthor::Ai => 'סוכן AI',
                };
            @endphp

            <div class="flex {{ $inbound ? 'justify-start' : 'justify-end' }}">
                <div @class([
                        'max-w-[80%] rounded-2xl px-4 py-3 text-base leading-relaxed shadow-sm',
                        'bg-white dark:bg-gray-800' => $inbound,
                        'bg-primary-100 dark:bg-primary-900/60' => ! $inbound && ! $note,
                        'border border-dashed border-amber-400 bg-amber-50 dark:bg-amber-900/30' => $note,
                    ])>
                    <div class="mb-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-semibold">{{ $authorLabel }}</span>
                        @if ($note) <span>🔒 הערה פנימית</span>
                        @else <span>{{ $message->channel->getLabel() }}</span> @endif
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d/m/Y H:i') }}</time>
                    </div>
                    @php $safeHtml = $message->safeBodyHtml(); @endphp
                    @if (filled($safeHtml))
                        {{-- Re-sanitized at render (allow-list, balanced) — safe even for
                             legacy/raw rows, so malformed HTML can't corrupt the DOM. --}}
                        <div class="chat-rich">{!! $safeHtml !!}</div>
                    @else
                        <div class="whitespace-pre-line break-words">{{ $message->body }}</div>
                    @endif

                    @if (filled($message->attachments))
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($message->attachments as $i => $attachment)
                                @php
                                    $url = route('support.attachment', ['message' => $message->id, 'index' => $i]);
                                    $mime = $attachment['mime'] ?? '';
                                @endphp
                                @if (str_starts_with($mime, 'image/'))
                                    <a href="{{ $url }}" target="_blank" rel="noopener">
                                        <img src="{{ $url }}" alt="{{ $attachment['name'] ?? 'תמונה' }}"
                                             class="max-h-48 rounded-lg border border-gray-200 dark:border-gray-700" style="max-width: 100%;">
                                    </a>
                                @elseif (str_starts_with($mime, 'audio/'))
                                    <audio controls preload="none" src="{{ $url }}" class="w-full max-w-xs"></audio>
                                @elseif (str_starts_with($mime, 'video/'))
                                    <video controls preload="none" src="{{ $url }}"
                                           class="max-h-64 rounded-lg border border-gray-200 dark:border-gray-700" style="max-width: 100%;"></video>
                                @else
                                    <a href="{{ $url }}" target="_blank" rel="noopener"
                                       class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs text-primary-600 hover:underline dark:border-gray-700">
                                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4" />
                                        {{ $attachment['name'] ?? 'קובץ מצורף' }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if ($message->isRateable())
                        {{-- Rate the reply 1–10; feeds the AI style learner so
                             future drafts lean on the highly-rated answers. --}}
                        <div class="mt-2 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span>דירוג איכות (1–10):</span>
                            <select
                                class="rounded border-gray-300 bg-white py-0.5 text-xs dark:border-gray-600 dark:bg-gray-900"
                                wire:change="rateMessage({{ $message->id }}, $event.target.value)"
                                aria-label="דירוג איכות התשובה"
                            >
                                <option value="">—</option>
                                @for ($n = 1; $n <= 10; $n++)
                                    <option value="{{ $n }}" @selected($message->quality_rating === $n)>{{ $n }}</option>
                                @endfor
                            </select>
                            @if ($message->quality_rating)
                                <span class="font-semibold text-primary-600 dark:text-primary-400">{{ $message->quality_rating }}/10 ✓</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-center text-sm text-gray-500">אין הודעות עדיין.</p>
        @endforelse
    </div>

    {{-- The bot's recommendations, pulled OUT of the conversation so they read as
         suggestions to the team, not as part of the customer exchange. --}}
    @if ($this->aiDrafts->isNotEmpty())
        <div class="ai-suggest" dir="rtl">
            <div class="ai-suggest-head">
                <span class="ai-suggest-icon" aria-hidden="true">🤖</span>
                <span class="ai-suggest-title">המלצת הסוכן</span>
                <span class="ai-suggest-tag">טיוטה — לא נשלחה ללקוח</span>
            </div>

            @foreach ($this->aiDrafts as $draft)
                <div class="ai-suggest-card">
                    <div class="ai-suggest-text">{{ $this->draftText($draft) }}</div>
                    <div class="ai-suggest-actions">
                        <button type="button" wire:click="useDraft({{ $draft->id }})" class="ai-suggest-btn ai-suggest-btn-primary">
                            ✏️ ערוך ושלח
                        </button>
                        <button type="button" wire:click="dismissDraft({{ $draft->id }})" class="ai-suggest-btn ai-suggest-btn-ghost">
                            דחה
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Scoped, purge-proof styles (the panel loads only Filament's compiled
             CSS, so app utilities won't render). Indigo accent + dark mode. --}}
        <style>
            .ai-suggest { border: 1px solid #c7d2fe; background: #eef2ff; border-radius: .85rem; padding: .9rem 1rem; }
            .ai-suggest-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; }
            .ai-suggest-icon { font-size: 1.1rem; }
            .ai-suggest-title { font-weight: 700; color: #3730a3; }
            .ai-suggest-tag { font-size: .72rem; color: #6366f1; border: 1px solid #c7d2fe; border-radius: 9999px; padding: .05rem .5rem; }
            .ai-suggest-card { background: #fff; border: 1px solid #e0e7ff; border-radius: .6rem; padding: .7rem .8rem; }
            .ai-suggest-card + .ai-suggest-card { margin-top: .6rem; }
            .ai-suggest-text { white-space: pre-line; word-break: break-word; color: #1f2937; line-height: 1.55; }
            .ai-suggest-actions { display: flex; gap: .5rem; margin-top: .7rem; }
            .ai-suggest-btn { font-size: .8rem; font-weight: 600; border-radius: .5rem; padding: .3rem .75rem; cursor: pointer; }
            .ai-suggest-btn-primary { background: #4f46e5; color: #fff; border: 1px solid #4f46e5; }
            .ai-suggest-btn-primary:hover { background: #4338ca; }
            .ai-suggest-btn-ghost { background: transparent; color: #4f46e5; border: 1px solid #c7d2fe; }
            .ai-suggest-btn-ghost:hover { background: #e0e7ff; }
            .dark .ai-suggest { border-color: #3730a3; background: rgba(49,46,129,.35); }
            .dark .ai-suggest-title { color: #c7d2fe; }
            .dark .ai-suggest-tag { color: #a5b4fc; border-color: #4338ca; }
            .dark .ai-suggest-card { background: #1f2937; border-color: #3730a3; }
            .dark .ai-suggest-text { color: #e5e7eb; }
            .dark .ai-suggest-btn-ghost { color: #a5b4fc; border-color: #4338ca; }
            .dark .ai-suggest-btn-ghost:hover { background: rgba(67,56,202,.35); }
        </style>
    @endif

    {{-- The AI's other working notes (classification, site findings) — kept out
         of the conversation as compact context, not as chat bubbles. --}}
    @if ($this->aiNotes->isNotEmpty())
        <details class="ai-note" dir="rtl">
            <summary class="ai-note-head">
                <span aria-hidden="true">🤖</span>
                <span class="ai-note-title">מידע מהסוכן</span>
                <span class="ai-note-count">{{ $this->aiNotes->count() }}</span>
                <span class="ai-note-tag">פנימי — לא נשלח ללקוח</span>
            </summary>
            @foreach ($this->aiNotes as $note)
                <div class="ai-note-card">
                    <div class="ai-note-text">{{ $note->body }}</div>
                    <time class="ai-note-time" datetime="{{ $note->created_at->toIso8601String() }}">{{ $note->created_at->format('d/m/Y H:i') }}</time>
                </div>
            @endforeach
        </details>

        <style>
            .ai-note { border: 1px solid #e5e7eb; background: #f9fafb; border-radius: .75rem; padding: .35rem .9rem; }
            .ai-note-head { display: flex; align-items: center; gap: .5rem; cursor: pointer; padding: .35rem 0; font-size: .85rem; color: #4b5563; list-style: none; }
            .ai-note-head::-webkit-details-marker { display: none; }
            .ai-note-title { font-weight: 600; color: #374151; }
            .ai-note-count { font-size: .72rem; background: #e5e7eb; color: #374151; border-radius: 9999px; padding: .02rem .45rem; }
            .ai-note-tag { margin-inline-start: auto; font-size: .72rem; color: #9ca3af; }
            .ai-note-card { border-top: 1px solid #eceef1; padding: .6rem 0; }
            .ai-note-text { white-space: pre-line; word-break: break-word; color: #374151; font-size: .88rem; line-height: 1.55; }
            .ai-note-time { display: block; margin-top: .3rem; font-size: .7rem; color: #9ca3af; }
            .dark .ai-note { border-color: #374151; background: rgba(31,41,55,.5); }
            .dark .ai-note-head { color: #9ca3af; }
            .dark .ai-note-title { color: #d1d5db; }
            .dark .ai-note-count { background: #374151; color: #d1d5db; }
            .dark .ai-note-card { border-top-color: #374151; }
            .dark .ai-note-text { color: #d1d5db; }
        </style>
    @endif

    {{-- Reply box: channel + text + send. --}}
    <form wire:submit.prevent="sendReply" class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="flex flex-wrap items-center gap-4">
            <label class="text-sm font-semibold" for="replyChannel">מענה דרך:</label>
            <select id="replyChannel" wire:model="replyChannel"
                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                <option value="{{ \App\Enums\MessageChannel::Whatsapp->value }}">וואטסאפ</option>
                <option value="{{ \App\Enums\MessageChannel::Email->value }}">מייל</option>
                <option value="{{ \App\Enums\MessageChannel::InternalNote->value }}">🔒 הערה פנימית (לא נשלחת ללקוח)</option>
            </select>
        </div>
        {{-- Real WYSIWYG editor (basic toolbar) — matches the formatting we now show. --}}
        {{ $this->replyForm }}

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm">
                <label for="replyFiles" class="flex cursor-pointer items-center gap-1 text-primary-600 hover:underline">
                    <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4" />
                    צירוף קובץ
                </label>
                <input id="replyFiles" type="file" wire:model="replyFiles" multiple class="hidden">
                @if (filled($replyFiles))
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($replyFiles) }} קבצים נבחרו</span>
                @endif
                <span wire:loading wire:target="replyFiles" class="text-xs text-gray-500">מעלה…</span>
            </div>
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                שליחה
            </x-filament::button>
        </div>
        @error('replyFiles.*') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
    </form>
</x-filament-panels::page>
