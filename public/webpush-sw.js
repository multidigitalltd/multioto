/* Multioto browser-push service worker. Shows an OS notification for each push
   and focuses/opens the relevant panel page when it's clicked. */
self.addEventListener('push', function (event) {
    if (!event.data) {
        return;
    }

    var payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'מולטי דיגיטל', body: event.data.text() };
    }

    var title = payload.title || 'מולטי דיגיטל';
    var options = {
        body: payload.body || '',
        icon: payload.icon || '/favicon.ico',
        badge: payload.badge || '/favicon.ico',
        dir: 'rtl',
        lang: 'he',
        tag: payload.tag,
        data: payload.data || {},
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var url = (event.notification.data && event.notification.data.url) || '/admin';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            for (var i = 0; i < windowClients.length; i++) {
                var client = windowClients[i];
                if ('focus' in client) {
                    if ('navigate' in client) {
                        client.navigate(url);
                    }
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
