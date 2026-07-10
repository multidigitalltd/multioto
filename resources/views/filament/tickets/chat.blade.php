<x-filament-panels::page>
    @php
        $ticket = $this->record;
    @endphp

    {{-- Context strip: who + channel + status at a glance. --}}
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">{{ $ticket->customer?->name ?? 'פונה לא מזוהה' }}</span>
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
                    \App\Enums\MessageAuthor::Customer => $ticket->customer?->name ?? 'לקוח',
                    \App\Enums\MessageAuthor::Agent => 'צוות',
                    \App\Enums\MessageAuthor::System => 'מערכת',
                    \App\Enums\MessageAuthor::Ai => 'סוכן AI',
                };
            @endphp

            <div class="flex {{ $inbound ? 'justify-start' : 'justify-end' }}">
                <div @class([
                        'max-w-[80%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm',
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
                    <div class="whitespace-pre-line">{{ $message->body }}</div>
                </div>
            </div>
        @empty
            <p class="text-center text-sm text-gray-500">אין הודעות עדיין.</p>
        @endforelse
    </div>

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
        <label for="replyBody" class="sr-only">תוכן המענה</label>
        <textarea id="replyBody" wire:model="replyBody" rows="3" required
                  placeholder="כתבו מענה ללקוח…"
                  class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900"></textarea>
        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                שליחה
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
