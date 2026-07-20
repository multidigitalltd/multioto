{{-- Browser-push (Web Push) registration. Rendered into the panel only when
     VAPID keys are configured. Registers the service worker, and offers a small
     "enable notifications" button until the member grants permission; once
     granted it subscribes silently and keeps the subscription fresh. --}}
@php($vapidPublicKey = \App\Support\WebPush::publicKey())
@if ($vapidPublicKey)
    <div
        x-data="webPush({
            vapidPublicKey: @js($vapidPublicKey),
            storeUrl: @js(route('push-subscriptions.store')),
            csrf: @js(csrf_token()),
        })"
        x-init="init()"
        x-cloak
    >
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
        function webPush(config) {
            return {
                showButton: false,

                init() {
                    if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) {
                        return;
                    }

                    navigator.serviceWorker.register('/webpush-sw.js').then(() => {
                        if (Notification.permission === 'granted') {
                            // Already allowed — make sure the subscription is stored/fresh.
                            this.subscribe();
                        } else if (Notification.permission === 'default') {
                            this.showButton = true;
                        }
                    }).catch(() => {});
                },

                enable() {
                    Notification.requestPermission().then((permission) => {
                        this.showButton = false;
                        if (permission === 'granted') {
                            this.subscribe();
                        }
                    });
                },

                async subscribe() {
                    try {
                        const registration = await navigator.serviceWorker.ready;
                        let subscription = await registration.pushManager.getSubscription();

                        if (! subscription) {
                            subscription = await registration.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: this.urlBase64ToUint8Array(config.vapidPublicKey),
                            });
                        }

                        await fetch(config.storeUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': config.csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify(subscription.toJSON()),
                        });
                    } catch (e) {
                        // Best-effort: never break the panel over a push hiccup.
                    }
                },

                urlBase64ToUint8Array(base64String) {
                    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
                    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                    const raw = window.atob(base64);
                    const output = new Uint8Array(raw.length);
                    for (let i = 0; i < raw.length; ++i) {
                        output[i] = raw.charCodeAt(i);
                    }
                    return output;
                },
            };
        }
    </script>
@endif
