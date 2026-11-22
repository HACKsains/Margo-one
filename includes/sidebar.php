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
        <a href="<?= htmlspecialchars(BASE_URL . '/gateway', ENT_QUOTES, 'UTF-8') ?>" class="margo-left-brand-link" aria-label="Beranda Margo One">
            <img src="<?= htmlspecialchars(APP_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="Margo One SSO" class="margo-left-logo">
            <span class="margo-left-brand-tag">
                <span class="dot"></span>
                <span>Margo One · SSO</span>
            </span>
        </a>
    </div>

    <nav class="margo-left-nav">
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
