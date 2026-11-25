/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · SERVICE WORKER (BACKGROUND WEB PUSH NOTIFICATIONS)
 * ==============================================================================
 * Menangani penerimaan notifikasi latar belakang (Web Push API) sehingga User
 * maupun Admin tetap menerima notifikasi (Agenda Hari Ini/Besok, Info Ekosistem,
 * dan Notifikasi Kelas) meskipun browser / tab web sedang tidak dibuka.
 * ==============================================================================
 */
'use strict';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var defaultIcon = './assets/pwa/icon-192.png';
    var defaultBadge = './assets/pwa/icon-96.png';

    var handleData = function (data) {
        var title = (data && data.title) ? String(data.title) : 'Notifikasi Margo One';
        var body  = (data && data.body) ? String(data.body) : 'Ada pembaruan baru di ekosistem Ilmuna.';
        var tag   = (data && data.tag) ? String(data.tag) : ('margo-push-' + Date.now());
        var url   = (data && data.url) ? String(data.url) : './gateway';

        return self.registration.showNotification(title, {
            body: body,
            icon: (data && data.icon) ? String(data.icon) : defaultIcon,
            badge: (data && data.badge) ? String(data.badge) : defaultBadge,
            tag: tag,
            renotify: true,
            vibrate: [120, 60, 120],
            data: {
                url: url,
                notif_id: (data && data.notif_id) ? data.notif_id : null,
                category: (data && data.category) ? String(data.category) : 'info',
                title: title,
                body: body,
                meta: (data && data.meta) ? String(data.meta) : ''
            }
        });
    };

    if (event.data) {
        var parsed = null;
        try {
            parsed = event.data.json();
        } catch (e) {
            try {
                parsed = { title: 'Margo One — Ilmuna', body: event.data.text() };
            } catch (e2) {
                parsed = null;
            }
        }
        if (parsed) {
            event.waitUntil(handleData(parsed));
            return;
        }
    }

    // Fallback tickle push: ambil notifikasi terbaru dari server
    event.waitUntil(
        fetch('./api/push.php?action=latest', { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data) {
                    return handleData(res.data);
                }
                return handleData({ title: 'Margo One — Ilmuna', body: 'Ada notifikasi baru menunggu Anda.' });
            })
            .catch(function () {
                return handleData({ title: 'Margo One — Ilmuna', body: 'Ada pembaruan baru di ekosistem Ilmuna.' });
            })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var d = event.notification.data || {};
    var targetUrl = d.url || './gateway';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var c = clientList[i];
                if (c.url && c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
                    c.postMessage({
                        type: 'MARGO_PUSH_CLICK',
                        payload: d
                    });
                    return c.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});
