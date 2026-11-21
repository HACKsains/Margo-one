(function () {
    'use strict';
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        document.querySelectorAll('[data-pwa-install]').forEach(function (btn) {
            btn.classList.add('is-ready');
        });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-pwa-install]') : null;
        if (!btn) return;
        e.preventDefault();
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
            });
        } else if (window.Ilmuna && typeof window.Ilmuna.info === 'function') {
            window.Ilmuna.info('Install Aplikasi', 'Gunakan menu browser "Tambahkan ke Layar Utama / Install App" untuk memasang Margo One.');
        }
    });
})();
