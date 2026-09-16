<x-filament-panels::page>
    {{-- The standing rules, stated rather than remembered. A rule that is
         switched off says so in place, because a screen that lists a protection
         it is not actually applying is worse than one that lists nothing. --}}
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->rules() as $rule)
            <div @class([
                'rounded-xl border p-4',
                'border-gray-200 dark:border-white/10' => $rule['active'],
                'border-dashed border-warning-400/60 bg-warning-50/40 dark:bg-warning-400/5' => ! $rule['active'],
            ])>
                <div class="flex items-start gap-3">
                    <span class="text-xl leading-none" aria-hidden="true">{{ $rule['icon'] }}</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $rule['title'] }}</span>
                            @unless ($rule['active'])
                                <span class="rounded-md bg-warning-500/10 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-400">
                                    לא פעיל
                                </span>
                            @endunless
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $rule['detail'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if (($unprotected = $this->unprotectedSites()) > 0)
        {{-- Nothing on this page applies to these sites. Leaving that unsaid
             would let the rules above read as cover for every customer. --}}
        <div class="rounded-xl border border-danger-300 bg-danger-50/50 p-4 dark:border-danger-400/40 dark:bg-danger-400/5">
            <div class="flex items-start gap-3">
                <span class="text-xl leading-none" aria-hidden="true">⚠️</span>
                <div>
                    <div class="font-semibold text-gray-950 dark:text-white">
                        {{ $unprotected }} אתרים פעילים אינם מחוברים לתוסף
                    </div>
                    <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        שום דבר מהמוגדר למעלה לא חל עליהם: אין שומר, אין החלפת מפתחות ואין ניתוק התחברויות.
                        כדי לכסות אותם יש להתקין את התוסף ולחבר אותו.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
