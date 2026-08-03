@props([
    // The Livewire property the dictated text goes into, e.g. "data.instruction".
    'target' => 'data.instruction',
    'label' => 'הכתבה קולית',
])

@php
    $id = 'voice-'.\Illuminate\Support\Str::random(6);
@endphp

{{--
    הכתבה קולית דרך הדפדפן עצמו.

    אין כאן מודל, אין שרת ואין העלאה של הקלטה לשום מקום: הדפדפן מתמלל, ואנחנו
    מקבלים טקסט. זו גם ההחלטה הפרטית הנכונה — קול של שיחה על לקוחות לא יוצא
    מהמכשיר דרכנו — וגם הסיבה שזה עובד בלי להתקין דבר.

    הכפתור מסתיר את עצמו בדפדפן שאינו תומך (נכון להיום: פיירפוקס), במקום להציג
    כפתור שלא עושה כלום.
--}}
<div
    x-data="{
        supported: false,
        listening: false,
        status: '',
        preview: '',
        recognition: null,

        init() {
            const Engine = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.supported = Boolean(Engine);

            if (! this.supported) {
                return;
            }

            this.recognition = new Engine();
            this.recognition.lang = 'he-IL';
            this.recognition.continuous = false;
            this.recognition.interimResults = true;

            this.recognition.onresult = (event) => {
                let settled = '';
                let pending = '';

                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const chunk = event.results[i][0].transcript;
                    event.results[i].isFinal ? settled += chunk : pending += chunk;
                }

                this.preview = pending;

                if (settled.trim() !== '') {
                    this.commit(settled.trim());
                }
            };

            this.recognition.onerror = (event) => {
                this.listening = false;
                this.preview = '';
                this.status = event.error === 'not-allowed'
                    ? 'הדפדפן חסם את המיקרופון. יש לאשר גישה בהגדרות האתר.'
                    : (event.error === 'no-speech' ? 'לא נקלט דיבור. נסו שוב.' : 'ההכתבה נעצרה.');
            };

            this.recognition.onend = () => {
                this.listening = false;
                this.preview = '';
            };
        },

        toggle() {
            if (! this.supported) {
                return;
            }

            if (this.listening) {
                this.recognition.stop();
                return;
            }

            this.status = '';

            try {
                this.recognition.start();
                this.listening = true;
                this.status = 'מקשיב…';
            } catch (e) {
                // start() throws when it is already running — nothing to report.
                this.listening = false;
            }
        },

        /* מצטרף לטקסט הקיים ולא מוחק אותו: אדם שמתקן את עצמו בקול מוסיף מילים. */
        commit(text) {
            const current = (this.$wire.get(@js($target)) || '').trim();

            this.$wire.set(@js($target), current === '' ? text : current + ' ' + text);
            this.status = 'נקלט.';
        },
    }"
    x-show="supported"
    x-cloak
    class="flex items-center gap-2"
>
    <button
        type="button"
        @click="toggle()"
        :aria-pressed="listening.toString()"
        :aria-label="listening ? 'עצירת ההכתבה' : @js($label)"
        aria-describedby="{{ $id }}-status"
        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition
               focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600
               border-gray-300 bg-white text-gray-700 hover:bg-gray-50
               dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
        :class="listening && 'border-danger-400 text-danger-700 dark:border-danger-500 dark:text-danger-300'"
    >
        <span
            class="inline-block h-2 w-2 rounded-full"
            :class="listening ? 'bg-danger-600 motion-safe:animate-pulse' : 'bg-gray-400'"
            aria-hidden="true"
        ></span>
        <span x-text="listening ? 'עוצר' : @js($label)"></span>
    </button>

    {{-- הודעת מצב, מקושרת לכפתור וגם מוכרזת לקורא מסך. --}}
    <span id="{{ $id }}-status" class="text-xs text-gray-500 dark:text-gray-400" aria-live="polite">
        <span x-text="preview !== '' ? preview : status"></span>
    </span>
</div>
