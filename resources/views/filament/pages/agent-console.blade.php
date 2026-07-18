<x-filament-panels::page>
    {{-- Poll the thread so a background result (e.g. a site investigation the
         agent kicked off) appears on its own when the queued job posts it back,
         without the operator refreshing. A full re-render also refreshes the
         extra-pending block and the awaiting-reply hint. Livewire preserves the
         text being typed in the message box across polls. --}}
    <div class="flex flex-col gap-4" wire:poll.15s>
        {{-- The conversation thread. flex-col-reverse + a newest-first list keeps
             it pinned to the latest message without any scroll scripting. --}}
        <div class="flex min-h-[45vh] flex-col-reverse gap-4 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40"
             style="max-height: 62vh">
            @forelse ($this->conversation as $command)
                <div wire:key="msg-{{ $command->id }}" class="flex flex-col gap-2">
                    @if ($command->role === 'system')
                        {{-- Approval / rejection outcome, posted back into the thread. --}}
                        <div class="mx-auto max-w-[90%] rounded-full bg-gray-200 px-4 py-1 text-center text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ $command->result }}
                        </div>
                    @else
                        {{-- The manager's turn (right in RTL). --}}
                        <div class="flex justify-start">
                            <div class="max-w-[85%] whitespace-pre-line break-words rounded-2xl rounded-se-sm bg-primary-600 px-4 py-2 text-sm text-white shadow-sm">
                                {{ $command->instruction }}
                            </div>
                        </div>

                        {{-- The agent's reply (left in RTL). --}}
                        @if (filled($command->result))
                            <div class="flex justify-end">
                                <div @class([
                                    'max-w-[85%] whitespace-pre-line break-words rounded-2xl rounded-es-sm border px-4 py-2 text-sm shadow-sm',
                                    'bg-white text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700' => ! in_array($command->outcome->value, ['failed', 'unclear'], true),
                                    'bg-danger-50 text-danger-700 border-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:border-danger-800' => $command->outcome->value === 'failed',
                                    'bg-warning-50 text-warning-800 border-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:border-warning-800' => $command->outcome->value === 'unclear',
                                ])>
                                    @if ($command->outcome->value === 'unclear')
                                        <span class="mb-1 block text-xs font-semibold">הסוכן שואל:</span>
                                    @endif
                                    {{ $command->result }}
                                </div>
                            </div>
                        @endif

                        {{-- A proposal the agent filed on this turn — approve or reject
                             it right here; the outcome returns to the thread. --}}
                        @if ($command->pendingAction && $command->pendingAction->status === \App\Enums\ActionStatus::Pending)
                            <div class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950/30">
                                    <div class="mb-1 flex flex-wrap items-center gap-2">
                                        <x-filament::badge color="warning">
                                            {{ \App\Filament\Resources\PendingActionResource::TYPE_LABELS[$command->pendingAction->type] ?? $command->pendingAction->type }}
                                        </x-filament::badge>
                                        <span class="text-xs text-gray-400">#{{ $command->pendingAction->id }}</span>
                                    </div>
                                    <p class="whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-200">
                                        {{ \Illuminate\Support\Str::limit($command->pendingAction->summary, 600) }}
                                    </p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <x-filament::button size="sm" color="success" icon="heroicon-o-check"
                                                            wire:click="approveAction({{ $command->pendingAction->id }})"
                                                            wire:confirm="לאשר ולבצע את הפעולה?"
                                                            wire:loading.attr="disabled">
                                            אשר
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark"
                                                            wire:click="rejectAction({{ $command->pendingAction->id }})"
                                                            wire:loading.attr="disabled">
                                            דחה
                                        </x-filament::button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            @empty
                <div class="m-auto max-w-md text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        התחילו שיחה עם הסוכן — כתבו הוראה בשפה חופשית בתיבה למטה.
                    </p>
                    <ul class="mt-3 space-y-1 text-start text-xs text-gray-500 dark:text-gray-400">
                        <li>· תענה למשה בכרטיס הפתוח שאנחנו על זה ונחזור אליו היום.</li>
                        <li>· תנקה קאש באתר example.co.il · תבדוק למה האתר של דנה איטי.</li>
                        <li>· תשלח דרישת תשלום למשה על 300 ש״ח עבור אחסון שנתי.</li>
                    </ul>
                </div>
            @endforelse
        </div>

        {{-- Any proposal not already shown inline above — a run that filed several
             actions, or proposals from monitoring/WhatsApp — stays actionable here. --}}
        @if ($this->extraPending->isNotEmpty())
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950/30">
                <p class="mb-2 text-xs font-semibold text-warning-700 dark:text-warning-300">פעולות נוספות ממתינות לאישור</p>
                <div class="flex flex-col divide-y divide-warning-200/60 dark:divide-warning-800/60">
                    @foreach ($this->extraPending as $action)
                        <div class="flex flex-col gap-2 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="mb-1 flex flex-wrap items-center gap-2">
                                    <x-filament::badge color="warning">
                                        {{ \App\Filament\Resources\PendingActionResource::TYPE_LABELS[$action->type] ?? $action->type }}
                                    </x-filament::badge>
                                    @if ($action->customer)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $action->customer->name }}</span>
                                    @endif
                                    <span class="text-xs text-gray-400">#{{ $action->id }}</span>
                                </div>
                                <p class="whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($action->summary, 500) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-filament::button size="sm" color="success" icon="heroicon-o-check"
                                                    wire:click="approveAction({{ $action->id }})"
                                                    wire:confirm="לאשר ולבצע את הפעולה?"
                                                    wire:loading.attr="disabled">אשר</x-filament::button>
                                <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark"
                                                    wire:click="rejectAction({{ $action->id }})"
                                                    wire:loading.attr="disabled">דחה</x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Clarify-and-continue hint: when the agent's last turn was a question,
             make it obvious the answer just goes in the same box. --}}
        @if ($this->awaitingReply)
            <p class="text-xs text-warning-600 dark:text-warning-400">
                הסוכן ממתין לתשובתך — כתבו אותה בתיבה והשיחה תמשיך מאותה נקודה.
            </p>
        @endif

        {{-- The message box, pinned under the thread. --}}
        <form wire:submit="run" class="flex flex-col gap-2">
            {{ $this->form }}

            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="run">
                    <span wire:loading.remove wire:target="run">שלח</span>
                    <span wire:loading wire:target="run">הסוכן חושב…</span>
                </x-filament::button>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    שום דבר לא נשלח ללקוח ולא מתבצע באתר ללא אישורכם — כל פעולה מוצעת כאן לאישור.
                </p>
            </div>
        </form>
    </div>
</x-filament-panels::page>
