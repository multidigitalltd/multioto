{{-- Browser-push (Web Push) bootstrap. Rendered into the panel only when VAPID
     keys are configured. Loads the shared helper + config, registers the service
     worker, and offers a small "enable notifications" button until the member
     grants permission; once granted it subscribes silently and stays fresh. The
     profile screen has a full on/off toggle that reuses the same helper. --}}
@php($vapidPublicKey = \App\Support\WebPush::publicKey())
@if ($vapidPublicKey)
    <script>
        window.MultiotoWebPushConfig = {
            vapidPublicKey: @js($vapidPublicKey),
            storeUrl: @js(route('push-subscriptions.store')),
            destroyUrl: @js(route('push-subscriptions.destroy')),
            csrf: @js(csrf_token()),
        };
    </script>
    <script src="{{ asset('js/webpush.js') }}"></script>

    <div x-data="webPushButton()" x-init="init()" x-cloak>
        <button
            type="button"
            x-show="showButton"
            x-on:click="enable()"
            style="position: fixed; inset-inline-end: 1rem; inset-block-end: 1rem; z-index: 50;
                   display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem;
                   border-radius: 9999px; background: rgb(79 70 229); color: #fff; font-size: .8rem;
                   font-weight: 600; box-shadow: 0 6px 16px rgba(0,0,0,.2); border: none; cursor: pointer;"
        >
            🔔 <span>הפעל התראות דפדפן</span>
        </button>
    </div>

    <script>
        function webPushButton() {
            return {
                showButton: false,

                init() {
                    const push = window.MultiotoWebPush;
                    if (! push || ! push.supported()) {
                        return;
                    }

                    push.register().then(() => {
                        if (Notification.permission === 'granted' && ! push.optedOut()) {
                            // Allowed and not turned off here — keep the subscription fresh.
                            push.subscribe();
                        } else if (Notification.permission === 'default') {
                            this.showButton = true;
                        }
                    }).catch(() => {});
                },

                enable() {
                    window.MultiotoWebPush.subscribe().then(() => {
                        this.showButton = false;
                    });
                },
            };
        }
    </script>
@endif
