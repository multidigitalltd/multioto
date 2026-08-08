@php
    $configured = \App\Http\Controllers\Auth\GoogleLoginController::configured();
    $error = session('error');
@endphp

@if ($error)
    <div class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400"
         role="alert">
        {{ $error }}
    </div>
@endif

@if ($configured)
    {{-- מעל טופס הסיסמה: זו הדרך שבה נכנסים בפועל, והסיסמה נשארת מתחת למי
         שצריך אותה. --}}
    <div class="mb-6">
        {{-- קישור ולא טופס: זו ניווט אל גוגל, ולא פעולה שמשנה משהו אצלנו. --}}
        <a href="{{ route('auth.google.redirect') }}"
           class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5
                  text-sm font-medium text-gray-700 shadow-sm transition
                  hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600
                  dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            @include('auth.google-mark', ['class' => 'h-4 w-4'])
            התחברות עם גוגל
        </a>

        <div class="relative mt-6 text-center">
            <span class="relative z-10 bg-white px-3 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">או</span>
            <span class="absolute inset-x-0 top-1/2 border-t border-gray-200 dark:border-gray-700"></span>
        </div>
    </div>
@endif
