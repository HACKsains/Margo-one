(function (window) {
    'use strict';
    window.MargoUI = window.MargoUI || {
        copyText: function (text, label) {
            var val = String(text || '');
            if (!val) return Promise.resolve(false);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(val).then(function () {
                    if (window.Ilmuna) window.Ilmuna.success('Tersalin', (label || 'Teks') + ' berhasil disalin ke clipboard.');
                    return true;
                }).catch(function () {
                    return fallbackCopy(val, label);
                });
            }
            return Promise.resolve(fallbackCopy(val, label));
        }
    };

    function fallbackCopy(val, label) {
        try {
            var ta = document.createElement('textarea');
            ta.value = val;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            if (window.Ilmuna) window.Ilmuna.success('Tersalin', (label || 'Teks') + ' berhasil disalin ke clipboard.');
            return true;
        } catch (e) {
            if (window.Ilmuna) window.Ilmuna.error('Gagal Menyalin', 'Silakan salin secara manual.');
            return false;
        }
    }
})(window);
