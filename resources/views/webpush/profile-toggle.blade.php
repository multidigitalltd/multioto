{{-- Profile-screen on/off control for browser push. Reuses the shared
     MultiotoWebPush helper (loaded panel-wide by webpush/register). Reflects the
     real browser state and lets the member turn notifications on or off on THIS
     device. --}}
<div
    x-data="{
        state: 'loading',
        async refresh() {
            const push = window.MultiotoWebPush;
            if (! push || ! push.supported()) { this.state = 'unsupported'; return; }
            if (push.permission() === 'denied') { this.state = 'denied'; return; }
            this.state = (await push.isSubscribed()) ? 'on' : 'off';
        },
        async toggle() {
            const push = window.MultiotoWebPush;
            if (this.state === 'on') {
                this.state = 'loading';
                await push.unsubscribe();
            } else {
                this.state = 'loading';
                await push.subscribe();
            }
            await this.refresh();
        },
    }"
    x-init="refresh()"
    class="fi-section-content"
>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div style="font-size: .875rem;">
            <span x-show="state === 'on'" style="color: rgb(22 163 74); font-weight: 600;">התראות דפדפן מופעלות במכשיר הזה ✓</span>
            <span x-show="state === 'off'" style="color: rgb(107 114 128);">התראות דפדפן כבויות במכשיר הזה.</span>
            <span x-show="state === 'loading'" style="color: rgb(107 114 128);">בודק…</span>
            <span x-show="state === 'denied'" style="color: rgb(202 138 4);">הדפדפן חוסם התראות עבור האתר — יש לאשר אותן בהגדרות הדפדפן ולנסות שוב.</span>
            <span x-show="state === 'unsupported'" style="color: rgb(202 138 4);">הדפדפן הזה אינו תומך בהתראות דפדפן (באייפון יש להוסיף את האתר למסך הבית).</span>
        </div>

        <button
            type="button"
            x-show="state === 'on' || state === 'off' || state === 'loading'"
            x-bind:disabled="state === 'loading'"
            x-on:click="toggle()"
            x-text="state === 'on' ? 'כבה התראות' : 'הפעל התראות'"
            style="display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem;
                   border-radius: 9999px; color: #fff; font-size: .8rem; font-weight: 600;
                   border: none; cursor: pointer;"
            x-bind:style="state === 'on'
                ? 'background: rgb(220 38 38);'
                : 'background: rgb(79 70 229);'"
        ></button>
    </div>
</div>
