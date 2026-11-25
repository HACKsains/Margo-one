<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · UNIFIED 3-COLUMN INNER SHELL HEADER (LUMINA GOLD)
 * ==============================================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}

$currentUser = getCurrentUser();
$activeRole  = $GLOBALS['activeRole'] ?? $activeRole ?? getActiveRole();
$pageTitle   = isset($pageTitle) ? (string)$pageTitle : 'Portal Margo One';
$pageIcon    = isset($pageIcon) ? (string)$pageIcon : 'solar:home-smile-bold';
$bodyExtraClass = isset($bodyExtraClass) ? trim((string)$bodyExtraClass) : '';

$user_name = $currentUser ? ($currentUser['full_name'] ?: $currentUser['username']) : 'Pengguna';
$user_foto = $currentUser['avatar'] ?? '';

$parts   = preg_split('/\s+/', trim($user_name));
$inisial = '';
if (!empty($parts)) {
    $inisial .= margo_mb_substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $inisial .= margo_mb_substr($parts[1], 0, 1);
    }
}
$inisial = margo_mb_strtoupper($inisial ?: margo_mb_substr($user_name, 0, 2));

$roleNameMap = [
    'admin'       => 'Administrator',
    'guru'        => 'Guru',
    'ketua_kelas' => 'Ketua Kelas',
    'siswa'       => 'Siswa',
];
$currentRoleLabel = isset($roleNameMap[$activeRole]) ? $roleNameMap[$activeRole] : ucfirst((string)$activeRole);
$shellCssVer = @filemtime(BASE_PATH . '/assets/css/margo-shell.css') ?: '1';
$respCssVer  = @filemtime(BASE_PATH . '/assets/css/ilmuna-unified-responsive.css') ?: '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<?= renderFavicon() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?= renderIconifyScript() ?>

    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . '/assets/css/lumina-lms.css', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . '/assets/css/ilmuna-unified-responsive.css?v=' . $respCssVer, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . '/assets/css/margo-shell.css?v=' . $shellCssVer, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.ILMUNA_BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/alert.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>

<?php
if (function_exists('loadCentralTemplates')) {
    loadCentralTemplates(['modal', 'table']);
}
if (!empty($extraHead)) {
    echo $extraHead;
}
?>
</head>
<body class="margo-shell-body<?= $bodyExtraClass !== '' ? ' ' . htmlspecialchars($bodyExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>">
    <div id="entrySlide" class="margo-entry-slide" aria-hidden="true"></div>
    <div id="sidebarOverlay" class="margo-sidebar-overlay" aria-hidden="true"></div>

    <div class="margo-shell">
        <?php require_once BASE_PATH . '/includes/sidebar.php'; ?>

        <main id="mainContent" class="margo-main">
            <header id="topHeader" class="margo-top-header">
                <div class="margo-header-left">
                    <button type="button" id="leftSidebarToggle" class="margo-icon-btn" aria-label="Buka/Tutup Menu Kiri" title="Menu Navigasi">
                        <iconify-icon icon="solar:hamburger-menu-bold"></iconify-icon>
                    </button>
                </div>

                <div class="margo-header-center">
                    <div class="margo-header-title">
                        <iconify-icon icon="<?= htmlspecialchars($pageIcon, ENT_QUOTES, 'UTF-8') ?>" width="18"></iconify-icon>
                        <span><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <span class="margo-header-role-chip"><?= htmlspecialchars($currentRoleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="margo-header-right">
                    <?php
                    // ── LEARN REAL-TIME NOTIFICATION BELL ────────────────────────────────
                    // Muat bridge + terbitkan WS Ticket server-side agar TIDAK ada Private Key bocor ke browser
                    $margoLearnWsBootstrap = null;
                    $margoLearnNotifEvents = [];
                    try {
                        $learnBridgePath = BASE_PATH . '/includes/learn-realtime-bridge.php';
                        if (file_exists($learnBridgePath)) {
                            require_once $learnBridgePath;
                            $footUserHdr = function_exists('getCurrentUser') ? getCurrentUser() : null;
                            $learnPdoHdr = function_exists('getDBConnection') ? getDBConnection() : null;
                            if ($footUserHdr && $learnPdoHdr) {
                                $margoLearnWsBootstrap = margo_learn_issue_user_ws_bootstrap($learnPdoHdr, $footUserHdr);
                                if (!empty($margoLearnWsBootstrap['recent_events']) && is_array($margoLearnWsBootstrap['recent_events'])) {
                                    $margoLearnNotifEvents = $margoLearnWsBootstrap['recent_events'];
                                }
                            }
                        }
                    } catch (Throwable $eLearnHdr) {
                        // Non-fatal: bell tampil kosong, WS connect ulang otomatis di JS
                    }
                    ?>
                    <div class="margo-learn-notif-wrap" id="margoLearnNotifMount">
                        <button type="button" class="margo-icon-btn margo-learn-bell-btn" id="margoLearnBellBtn"
                                aria-label="Notifikasi Real-Time Ilmuna Learn" title="Notifikasi Kelas Ilmuna Learn"
                                onclick="MargoLearnWS && MargoLearnWS.toggleDrawer()">
                            <iconify-icon icon="solar:bell-bing-bold" id="margoLearnBellIcon"></iconify-icon>
                            <span class="margo-learn-badge" id="margoLearnBadge" style="display:none">0</span>
                            <span class="margo-learn-live-dot" id="margoLearnLiveDot" title="Koneksi real-time aktif"></span>
                        </button>
                        <div class="margo-learn-drawer" id="margoLearnDrawer" style="display:none">
                            <div class="mld-header">
                                <span class="mld-title"><iconify-icon icon="solar:bell-bing-bold"></iconify-icon> Notifikasi Kelas</span>
                                <button type="button" class="mld-clear-btn" id="margoLearnClearBtn" onclick="MargoLearnWS && MargoLearnWS.clearAll()">
                                    <iconify-icon icon="solar:trash-bin-minimalistic-bold" width="14"></iconify-icon> Bersihkan
                                </button>
                            </div>
                            <div class="mld-list" id="margoLearnNotifList">
                                <div class="mld-empty" id="margoLearnEmptyState">
                                    <iconify-icon icon="solar:bell-off-bold" width="28" style="opacity:.35;color:#0D7A87;"></iconify-icon>
                                    <span>Belum ada notifikasi kelas</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars(BASE_URL . '/agenda', ENT_QUOTES, 'UTF-8') ?>" class="margo-icon-btn" title="Agenda Ekosistem" aria-label="Agenda Ekosistem">
                        <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
                    </a>
                    <?php if (hasRole('admin')): ?>
                        <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/', ENT_QUOTES, 'UTF-8') ?>" class="margo-icon-btn" title="Kontrol Admin SSO" aria-label="Kontrol Admin SSO">
                            <iconify-icon icon="solar:shield-keyhole-bold"></iconify-icon>
                        </a>
                    <?php endif; ?>
                    <button type="button" id="rightSidebarToggle" class="margo-header-avatar-btn" aria-label="Buka/Tutup Panel Profil & Agenda" title="Profil, Agenda & Info">
                        <span class="margo-mini-avatar">
                            <?php if (!empty($user_foto)): ?>
                                <img src="<?= htmlspecialchars($user_foto, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
                            <?php else: ?>
                                <?= htmlspecialchars($inisial, ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </span>
                        <span class="margo-header-avatar-name"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                </div>
            </header>

            <div class="margo-main-inner">
