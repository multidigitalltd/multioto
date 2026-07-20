/* Shared browser-push (Web Push) helpers, used by both the floating "enable"
   button and the profile on/off toggle. Reads its config from
   window.MultiotoWebPushConfig (set inline by the server). Everything is
   best-effort and never throws to the caller. */
window.MultiotoWebPush = {
    cfg() {
        return window.MultiotoWebPushConfig || {};
    },

    supported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },

    permission() {
        return this.supported() ? Notification.permission : 'unsupported';
    },

    register() {
        return navigator.serviceWorker.register('/webpush-sw.js');
    },

    async isSubscribed() {
        if (! this.supported() || Notification.permission !== 'granted') {
            return false;
        }
        try {
            const registration = await navigator.serviceWorker.ready;
            return !! (await registration.pushManager.getSubscription());
        } catch (e) {
            return false;
        }
    },

    /** Ask permission (if needed), subscribe, and store on the server. */
    async subscribe() {
        if (! this.supported()) {
            return false;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            return false;
        }

        try {
            await this.register();
            const registration = await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();

            if (! subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.cfg().vapidPublicKey),
                });
            }

            await fetch(this.cfg().storeUrl, {
                method: 'POST',
                headers: this.headers(),
                body: JSON.stringify(subscription.toJSON()),
            });

            return true;
        } catch (e) {
            return false;
        }
    },

    /** Remove this browser's subscription, server-side and locally. */
    async unsubscribe() {
        if (! this.supported()) {
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await fetch(this.cfg().destroyUrl, {
                    method: 'DELETE',
                    headers: this.headers(),
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }

            return true;
        } catch (e) {
            return false;
        }
    },

    headers() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': this.cfg().csrf,
        };
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
