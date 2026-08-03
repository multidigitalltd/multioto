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
    <div class="mt-6">
        <div class="relative mb-4 text-center">
            <span class="relative z-10 bg-white px-3 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">או</span>
            <span class="absolute inset-x-0 top-1/2 border-t border-gray-200 dark:border-gray-700"></span>
        </div>

        {{-- קישור ולא טופס: זו ניווט אל גוגל, ולא פעולה שמשנה משהו אצלנו. --}}
        <a href="{{ route('auth.google.redirect') }}"
           class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5
                  text-sm font-medium text-gray-700 shadow-sm transition
                  hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600
                  dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24s.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            התחברות עם גוגל
        </a>

        <p class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400">
            הכניסה מיועדת לחשבונות שכבר רשומים במערכת. חשבון גוגל שאינו רשום לא ייכנס.
        </p>
    </div>
@endif
