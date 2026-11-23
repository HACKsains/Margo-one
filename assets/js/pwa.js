(function () {
    'use strict';
    var deferredPrompt = null;

    function isStandaloneMode() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
    }

    function markInstalledState() {
        document.querySelectorAll('[data-pwa-install]').forEach(function (btn) {
            btn.classList.remove('is-ready');
            btn.classList.add('is-installed');
            var sp = btn.querySelector('span');
            if (sp) sp.textContent = 'Terpasang';
        });
    }

    if (isStandaloneMode()) {
        document.addEventListener('DOMContentLoaded', markInstalledState);
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        var btns = document.querySelectorAll('[data-pwa-install]');
        if (!btns.length) {
            return;
        }
        e.preventDefault();
        deferredPrompt = e;
        btns.forEach(function (btn) {
            btn.classList.add('is-ready');
        });
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        markInstalledState();
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-pwa-install]') : null;
        if (!btn) return;
        e.preventDefault();
        if (isStandaloneMode() || btn.classList.contains('is-installed')) {
            return;
        }
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    markInstalledState();
                }
            }).finally(function () {
                deferredPrompt = null;
            });
        } else {
            var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '');
            var msg = isIOS
                ? 'Di Safari iOS: ketuk ikon Bagikan (Share) lalu pilih "Tambahkan ke Layar Utama (Add to Home Screen)".'
                : 'Gunakan menu browser (ikon ⋮ atau ikon install di address bar) lalu pilih "Install Aplikasi / Tambahkan ke Layar Utama".';
            if (window.Ilmuna && typeof window.Ilmuna.info === 'function') {
                window.Ilmuna.info('Install Aplikasi Margo One', msg);
            } else {
                window.alert('Install Aplikasi Margo One\n\n' + msg);
            }
        }
    });
})();
