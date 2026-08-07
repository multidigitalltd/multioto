{{-- Offer browser notifications to a team member who has not turned them on
     for THIS browser.

     Push is a per-browser thing, so the server cannot answer the question: a
     member with notifications on their desktop is still un-notified on the
     laptop they are reading this on. Only the browser knows, so the check runs
     here — and the banner stays hidden while it is still asking.

     Dismissal is remembered per browser, for the same reason. Somebody who said
     no on the office machine did not say no on their phone; and somebody who
     said no once should not be asked again tomorrow. An offer that returns
     every morning is not an offer, it is nagging. --}}
@if (\App\Support\WebPush::enabled())
    <style>
        [x-cloak] { display: none !important; }
        .mo-push-offer {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
            margin: 0 0 1rem; padding: .85rem 1rem;
            border-radius: 12px;
            /* #312e81 on #eef2ff = 12.6:1; the muted line below is #3730a3 = 9.1:1.
               Both comfortably past AA, which a banner that appears over other
               people's screens has no excuse not to be. */
            background: #eef2ff; border: 1px solid #c7d2fe; color: #312e81;
        }
        .mo-push-offer__muted { color: #3730a3; }
        .mo-push-offer__enable {
            display: inline-flex; align-items: center; padding: .5rem .9rem;
            border-radius: 9999px; border: none; cursor: pointer;
            /* white on #4338ca = 7.6:1 */
            background: #4338ca; color: #fff; font-size: .8rem; font-weight: 600;
        }
        .mo-push-offer__enable:disabled { opacity: .7; cursor: default; }
        .mo-push-offer__later {
            padding: .5rem .75rem; border-radius: 9999px; border: none; cursor: pointer;
            background: transparent; color: #3730a3; font-size: .8rem;
        }
        /* Keyboard users must be able to find both buttons on a banner that
           interrupts the page. */
        .mo-push-offer__enable:focus-visible,
        .mo-push-offer__later:focus-visible { outline: 3px solid #312e81; outline-offset: 2px; }

        .dark .mo-push-offer {
            background: #1e2939; border-color: #364153; color: #e5e7eb;
        }
        .dark .mo-push-offer__muted,
        .dark .mo-push-offer__later { color: #c7d2fe; }
        .dark .mo-push-offer__enable:focus-visible,
        .dark .mo-push-offer__later:focus-visible { outline-color: #c7d2fe; }
    </style>

    <div
        x-data="{
            state: 'checking',
            dismissed: localStorage.getItem('multioto-push-offer-dismissed') === '1',
            async check() {
                const push = window.MultiotoWebPush;
                // Unsupported, or already refused at the browser level: there is
                // nothing this banner can offer, and saying so unprompted would
                // be noise on every page.
                if (! push || ! push.supported() || push.permission() === 'denied') { this.state = 'hidden'; return; }
                this.state = (await push.isSubscribed()) ? 'hidden' : 'offer';
            },
            async enable() {
                this.state = 'working';
                await window.MultiotoWebPush.subscribe();
                await this.check();
            },
            dismiss() {
                localStorage.setItem('multioto-push-offer-dismissed', '1');
                this.dismissed = true;
            },
        }"
        x-init="check()"
        x-show="! dismissed && (state === 'offer' || state === 'working')"
        x-cloak
        role="status"
        class="mo-push-offer"
    >
        <div style="font-size: .875rem; line-height: 1.5;">
            <strong>לקבל התראה ברגע שנכנסת פנייה?</strong>
            <span class="mo-push-offer__muted">התראות דפדפן קופצות גם כשהלשונית ברקע. ההגדרה היא לדפדפן הזה בלבד.</span>
        </div>

        <div style="display: flex; align-items: center; gap: .5rem;">
            <button
                type="button"
                class="mo-push-offer__enable"
                x-on:click="enable()"
                x-bind:disabled="state === 'working'"
                x-text="state === 'working' ? 'רגע…' : 'הפעל התראות'"
            ></button>
            <button type="button" class="mo-push-offer__later" x-on:click="dismiss()">לא עכשיו</button>
        </div>
    </div>
@endif
