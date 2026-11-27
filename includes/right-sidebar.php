<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · RIGHT SIDEBAR (PROFILE · AGENDA · INFO EKOSISTEM)
 * ==============================================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}

$rUser       = getCurrentUser();
$rActiveRole = $GLOBALS['activeRole'] ?? getActiveRole();
$rRolesList  = $rUser['roles_detail'] ?? [];
$rFullName   = $rUser ? ($rUser['full_name'] ?: $rUser['username']) : 'Pengguna';
$rSubLabel   = !empty($rUser['email']) ? (string)$rUser['email'] : ('@' . (string)($rUser['username'] ?? ''));
$rAvatar     = $rUser['avatar'] ?? '';

$rParts   = preg_split('/\s+/', trim($rFullName));
$rInisial = '';
if (!empty($rParts)) {
    $rInisial .= margo_mb_substr($rParts[0], 0, 1);
    if (isset($rParts[1])) {
        $rInisial .= margo_mb_substr($rParts[1], 0, 1);
    }
}
$rInisial = margo_mb_strtoupper($rInisial ?: margo_mb_substr($rFullName, 0, 2));

$rRoleMap = [
    'admin'       => 'Administrator SSO',
    'guru'        => 'Guru Pengampu',
    'guru_bk'     => 'Guru BK',
    'orang_tua'   => 'Orang Tua / Wali',
    'ketua_kelas' => 'Ketua Kelas',
    'siswa'       => 'Siswa',
];
$rRoleLabel = isset($rRoleMap[$rActiveRole]) ? $rRoleMap[$rActiveRole] : ucfirst((string)$rActiveRole);
$rRoleIcon  = function_exists('margo_role_icon') ? margo_role_icon($rActiveRole) : 'solar:user-bold';
?>
<aside id="rightSidebar" class="margo-sidebar-right" aria-label="Panel Profil, Agenda & Pengumuman">
    <!-- 1. KARTU PROFIL PENGGUNA + DROPDOWN -->
    <div class="margo-profile-wrap" id="margoRightProfileWrap">
        <button type="button" class="margo-profile-card" id="margoRightProfileBtn" aria-expanded="false">
            <div class="margo-profile-avatar">
                <?php if (!empty($rAvatar)): ?>
                    <img src="<?= htmlspecialchars($rAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" loading="lazy">
                <?php else: ?>
                    <?= htmlspecialchars($rInisial, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <span class="margo-profile-online" title="Sesi SSO Aktif"></span>
            </div>
            <div class="margo-profile-meta">
                <div class="margo-profile-name"><?= htmlspecialchars($rFullName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="margo-profile-sub"><?= htmlspecialchars($rSubLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <span class="margo-profile-role-pill">
                    <iconify-icon icon="<?= htmlspecialchars($rRoleIcon, ENT_QUOTES, 'UTF-8') ?>" width="11"></iconify-icon>
                    <span><?= htmlspecialchars($rRoleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </span>
            </div>
            <iconify-icon icon="solar:alt-arrow-down-bold" class="margo-profile-caret"></iconify-icon>
        </button>

        <div class="margo-profile-dropdown" id="margoRightProfileMenu" role="menu">
            <a href="<?= htmlspecialchars(BASE_URL . '/profile?tab=security', ENT_QUOTES, 'UTF-8') ?>" class="margo-dd-item" role="menuitem">
                <iconify-icon icon="solar:user-id-bold"></iconify-icon>
                <span>Profil &amp; Identitas</span>
            </a>
            <a href="<?= htmlspecialchars(BASE_URL . '/profile?tab=google', ENT_QUOTES, 'UTF-8') ?>" class="margo-dd-item" role="menuitem">
                <iconify-icon icon="solar:link-circle-bold"></iconify-icon>
                <span>Tautan Google SSO</span>
            </a>
            <a href="<?= htmlspecialchars(BASE_URL . '/profile?tab=sessions', ENT_QUOTES, 'UTF-8') ?>" class="margo-dd-item" role="menuitem">
                <iconify-icon icon="solar:devices-bold"></iconify-icon>
                <span>Perangkat &amp; Sesi Aktif</span>
            </a>
            <?php if (hasRole('admin')): ?>
                <div class="margo-dd-divider"></div>
                <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/', ENT_QUOTES, 'UTF-8') ?>" class="margo-dd-item" role="menuitem">
                    <iconify-icon icon="solar:shield-keyhole-bold" style="color:#0D7A87;"></iconify-icon>
                    <span>Panel Kontrol Admin</span>
                </a>
            <?php endif; ?>
            <div class="margo-dd-divider"></div>
            <button type="button" class="margo-dd-item is-danger" onclick="margoTriggerLogout()" role="menuitem">
                <iconify-icon icon="solar:logout-2-bold"></iconify-icon>
                <span>Keluar Global (SLO)</span>
            </button>
        </div>

        <?php if (count($rRolesList) > 1): ?>
            <div class="margo-role-switch-box" aria-label="Ganti Peran Aktif">
                <?php foreach ($rRolesList as $rl):
                    $isAct = ($rl['code'] === $rActiveRole);
                ?>
                    <a href="<?= htmlspecialchars(BASE_URL . '/gateway?switch_role=' . urlencode($rl['code']), ENT_QUOTES, 'UTF-8') ?>"
                       class="margo-role-switch-pill <?= $isAct ? 'is-active' : '' ?>">
                        <iconify-icon icon="<?= htmlspecialchars($rl['icon'], ENT_QUOTES, 'UTF-8') ?>" width="12"></iconify-icon>
                        <span><?= htmlspecialchars($rl['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. WIDGET AGENDA MENDATANG (HORIZONTAL DATE CARDS) -->
    <section aria-label="Agenda Mendatang">
        <div class="margo-rwidget-head">
            <h3 class="margo-rwidget-title">
                <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
                <span>Agenda Mendatang</span>
            </h3>
            <a href="<?= htmlspecialchars(BASE_URL . '/agenda', ENT_QUOTES, 'UTF-8') ?>" class="margo-rwidget-link">
                <span>Semua</span>
                <iconify-icon icon="solar:arrow-right-linear" width="13"></iconify-icon>
            </a>
        </div>
        <div id="margoRightAgendaStrip" class="margo-agenda-strip">
            <div class="margo-skel" style="flex:0 0 88px;height:106px;border-radius:16px;"></div>
            <div class="margo-skel" style="flex:0 0 88px;height:106px;border-radius:16px;"></div>
            <div class="margo-skel" style="flex:0 0 88px;height:106px;border-radius:16px;"></div>
        </div>
    </section>

    <!-- 3. WIDGET INFO & PENGUMUMAN EKOSISTEM -->
    <section aria-label="Info & Pengumuman Ekosistem">
        <div class="margo-rwidget-head">
            <h3 class="margo-rwidget-title">
                <iconify-icon icon="solar:bell-bing-bold"></iconify-icon>
                <span>Info Ekosistem</span>
            </h3>
            <?php if (hasRole('admin')): ?>
                <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/portal', ENT_QUOTES, 'UTF-8') ?>" class="margo-rwidget-link">
                    <span>Kelola</span>
                    <iconify-icon icon="solar:pen-new-square-linear" width="13"></iconify-icon>
                </a>
            <?php endif; ?>
        </div>
        <div id="margoRightNewsList" class="margo-news-list">
            <div class="margo-skel" style="height:76px;border-radius:14px;"></div>
            <div class="margo-skel" style="height:76px;border-radius:14px;"></div>
        </div>
        <button type="button" id="margoNewsToggleBtn" class="margo-load-more-btn" style="display:none;">
            Muat info lainnya
        </button>
    </section>
</aside>
