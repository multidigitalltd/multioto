<div class="max-h-96 overflow-y-auto text-sm">
    <ul class="space-y-1">
        @foreach ($items as $item)
            <li class="border-b border-gray-100 py-1 dark:border-gray-800">{{ $item }}</li>
        @endforeach
    </ul>
</div>
