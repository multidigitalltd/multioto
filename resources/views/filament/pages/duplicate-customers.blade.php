<x-filament-panels::page>
    @php $groups = $this->duplicates(); @endphp

    @forelse ($groups as $group)
        <x-filament::section icon="heroicon-o-user-group">
            <x-slot name="heading">{{ $group['reason'] }}: {{ $group['value'] }}</x-slot>
            <x-slot name="description">{{ $group['customers']->count() }} כרטיסים חולקים את המזהה הזה.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="p-2 text-start font-normal">לקוח</th>
                            <th class="p-2 text-start font-normal">מייל</th>
                            <th class="p-2 text-start font-normal">טלפון</th>
                            <th class="p-2 text-start font-normal">נפתח</th>
                            <th class="p-2 text-start font-normal"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['customers'] as $customer)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-2 font-semibold">{{ $customer->name }} <span class="font-mono text-xs text-gray-400">#{{ $customer->id }}</span></td>
                                <td class="p-2">{{ $customer->email ?: '—' }}</td>
                                <td class="p-2" dir="ltr">{{ $customer->phone ?: '—' }}</td>
                                <td class="p-2">{{ $customer->created_at?->format('d/m/Y') }}</td>
                                <td class="p-2 text-end">
                                    <a href="{{ $this->cardUrl($customer->id) }}" class="text-primary-600 hover:underline">פתח כרטיס</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- The merge itself stays where the direction is unambiguous: on the
                 card that will survive. A merge button in a list of pairs would
                 have to ask which one stays, and that question answered wrongly
                 deletes the wrong customer. --}}
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                למיזוג: פתחו את הכרטיס שאתם רוצים <strong>להשאיר</strong>, ושם לחצו "עוד פעולות ← מיזוג כרטיס כפול לכאן".
            </p>
        </x-filament::section>
    @empty
        <x-filament::section icon="heroicon-o-check-circle">
            <x-slot name="heading">אין כרטיסים כפולים</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                אין שני לקוחות שחולקים מייל, טלפון, וואטסאפ או ח.פ.
            </p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
