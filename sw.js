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

var MARGO_SW_CACHE = 'margo-one-sw-v3';
var MARGO_SOUND_URL = './assets/audio/notif-margo-one.mp3';

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(MARGO_SW_CACHE).then(function (cache) {
            return cache.addAll([MARGO_SOUND_URL, './assets/pwa/icon-192.png', './assets/pwa/icon-96.png']).catch(function () {});
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== MARGO_SW_CACHE; }).map(function (k) { return caches.delete(k); }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('push', function (event) {
    var defaultIcon = './assets/pwa/icon-192.png';
    var defaultBadge = './assets/pwa/icon-96.png';

    var handleData = function (data) {
        var title = (data && data.title) ? String(data.title) : 'Notifikasi Margo One';
        var body  = (data && data.body) ? String(data.body) : 'Ada pembaruan baru di ekosistem Ilmuna.';
        var tag   = (data && data.tag) ? String(data.tag) : ('margo-push-' + Date.now());
        var url   = (data && data.url) ? String(data.url) : './gateway';

        var payloadData = {
            url: url,
            notif_id: (data && data.notif_id) ? data.notif_id : null,
            category: (data && data.category) ? String(data.category) : 'info',
            title: title,
            body: body,
            meta: (data && data.meta) ? String(data.meta) : ''
        };

        var showPromise = self.registration.showNotification(title, {
            body: body,
            icon: (data && data.icon) ? String(data.icon) : defaultIcon,
            badge: (data && data.badge) ? String(data.badge) : defaultBadge,
            sound: MARGO_SOUND_URL,
            silent: false,
            tag: tag,
            renotify: true,
            requireInteraction: true,
            timestamp: Date.now(),
            vibrate: [200, 100, 200, 100, 300],
            data: payloadData
        });

        // Beri tahu seluruh jendela PWA/tab yang aktif agar langsung memutar suara MP3 kustom (Sunrise Pendopo)
        var notifyClients = self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                try {
                    clientList[i].postMessage({
                        type: 'MARGO_PUSH_RECEIVED',
                        payload: payloadData
                    });
                } catch (eMsg) {}
            }
        }).catch(function () {});

        return Promise.all([showPromise, notifyClients]);
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
