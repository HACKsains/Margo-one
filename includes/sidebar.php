<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · LEFT SIDEBAR (COLLAPSIBLE ACCORDION LUMINA GOLD)
 * ==============================================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$currentRoute  = preg_replace('/\.php$/i', '', $currentScript);
$activeTabReq  = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : '';
$isAdminPath   = strpos(str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? ''), '/dashboard/admin') !== false;
if ($isAdminPath) {
    if ($currentRoute === 'audit-logs') {
        $currentRoute = 'jwks';
    } elseif ($currentRoute === 'index') {
        $currentRoute = $activeTabReq !== '' ? $activeTabReq : 'clients';
    }
}

require __DIR__ . '/menu.php';
?>
<aside id="leftSidebar" class="margo-sidebar-left" aria-label="Navigasi Utama Margo One">
    <div class="margo-left-brand">
        <a href="<?= htmlspecialchars(BASE_URL . '/gateway', ENT_QUOTES, 'UTF-8') ?>" class="margo-left-brand-link" aria-label="Beranda Margo One" oncontextmenu="return false;">
            <img src="<?= htmlspecialchars(APP_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="Margo One SSO" class="margo-left-logo" draggable="false" oncontextmenu="return false;">
            <span class="margo-left-brand-tag">
                <span class="dot"></span>
                <span>Margo One · SSO</span>
            </span>
        </a>
    </div>

    <nav id="margoLeftNav" class="margo-left-nav">
        <?php foreach ($margoSidebarGroups as $grp): ?>
            <details open class="margo-nav-group <?= !empty($grp['is_admin']) ? 'is-admin-group' : '' ?>" data-sidebar-dropdown="<?= htmlspecialchars($grp['key'], ENT_QUOTES, 'UTF-8') ?>">
                <summary>
                    <iconify-icon icon="<?= htmlspecialchars($grp['icon'], ENT_QUOTES, 'UTF-8') ?>" width="16"></iconify-icon>
                    <span><?= htmlspecialchars($grp['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <iconify-icon icon="solar:alt-arrow-down-bold" width="13" class="margo-nav-group-chevron"></iconify-icon>
                </summary>
                <div class="margo-nav-items">
                    <?php foreach ($grp['items'] as $item):
                        $isActive = ($item['route'] !== '' && $currentRoute === $item['route']);
                    ?>
                        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                           class="margo-nav-link <?= $isActive ? 'is-active' : '' ?>"
                           data-sidebar-route="<?= htmlspecialchars($item['route'], ENT_QUOTES, 'UTF-8') ?>">
                            <iconify-icon icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></iconify-icon>
                            <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="margo-nav-badge <?= ($item['badge']['variant'] ?? '') === 'gold' ? 'margo-nav-badge--gold' : '' ?>">
                                    <?= htmlspecialchars($item['badge']['text'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>

    <div class="margo-left-footer">
        <button type="button" class="margo-left-logout-btn" onclick="margoTriggerLogout()">
            <iconify-icon icon="solar:logout-2-bold" width="16"></iconify-icon>
            <span>Keluar Global (SLO)</span>
        </button>
        <div class="margo-left-status-line">
            <span class="margo-left-status-dot">RS256 Aktif</span>
            <span><?= htmlspecialchars(APP_DOMAIN, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</aside>
<script>
(function () {
    'use strict';
    var nav = document.getElementById('margoLeftNav');
    if (!nav) return;
    var KEY = 'margo_left_nav_scroll_v1';

    // Pulihkan state <details> dan scrollTop secara sinkron sebelum browser menggambar halaman
    document.querySelectorAll('details[data-sidebar-dropdown]').forEach(function (det) {
        var dKey = 'margo.sidebar.dropdown.' + det.getAttribute('data-sidebar-dropdown');
        try {
            var st = localStorage.getItem(dKey);
            if (st === 'closed') det.open = false;
            else det.open = true;
        } catch (e) {}
    });

    function saveScroll() {
        try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) {}
    }

    function restoreScroll() {
        try {
            var saved = sessionStorage.getItem(KEY);
            if (saved !== null && saved !== '') {
                nav.scrollTop = parseInt(saved, 10) || 0;
            }
        } catch (e) {}
    }

    restoreScroll();
    requestAnimationFrame(restoreScroll);

    nav.addEventListener('scroll', saveScroll, { passive: true });
    nav.addEventListener('mousedown', saveScroll, { passive: true });
    nav.addEventListener('click', function (e) {
        saveScroll();
        var link = e.target.closest('a.margo-nav-link');
        if (!link) return;
        var route = link.getAttribute('data-sidebar-route');
        var appsEl = document.getElementById('apps-container');
        var mainEl = document.getElementById('mainContent');
        if (route === 'apps' && appsEl && mainEl) {
            e.preventDefault();
            mainEl.scrollTo({ top: Math.max(0, appsEl.offsetTop - 76), behavior: 'smooth' });
        } else if (route === 'gateway' && appsEl && mainEl && window.location.pathname.indexOf('/gateway') !== -1) {
            e.preventDefault();
            mainEl.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
    window.addEventListener('beforeunload', saveScroll);
    window.addEventListener('pagehide', saveScroll);
})();
</script>
