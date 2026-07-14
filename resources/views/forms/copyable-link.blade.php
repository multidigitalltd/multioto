{{-- A read-only link with a self-contained copy button. Pure Alpine — no
     Livewire round-trip or Filament action — so the copy actually runs on the
     user's click gesture and reads the input's live value. --}}
<div x-data="{ copied: false }" class="flex items-center gap-2">
    <input
        type="text"
        readonly
        dir="ltr"
        x-ref="cardlink"
        value="{{ $link }}"
        @click="$refs.cardlink.select()"
        class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 font-mono text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
    >
    <button
        type="button"
        class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
        x-on:click="
            const i = $refs.cardlink;
            i.focus(); i.select(); i.setSelectionRange(0, 99999);
            const done = () => { copied = true; setTimeout(() => copied = false, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(i.value).then(done).catch(() => { try { document.execCommand('copy'); } catch (e) {} done(); });
            } else {
                try { document.execCommand('copy'); } catch (e) {}
                done();
            }
        "
    >
        <span x-show="!copied">העתקה</span>
        <span x-show="copied" x-cloak>הועתק ✓</span>
    </button>
</div>
