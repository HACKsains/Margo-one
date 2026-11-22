<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · BERANDA PORTAL & APP LAUNCHER (/gateway)
 * ==============================================================================
 * Arsitektur Inner Layout 3-Kolom (Lumina Gold & Nusantara):
 *   - Left Sidebar  : Menu Utama & Kontrol Admin (collapsible accordion)
 *   - Main Content  : Ecosystem Hero Banner + Aplikasi Terintegrasi SSO & Non-SSO
 *                     + Aktivitas & Login Terakhir (AJAX pagination, zero reload)
 *   - Right Sidebar : Kartu Profil + Multi-Role + Agenda Mendatang + Info Ekosistem
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
}

requireAuth();
margo_clear_pending_oauth();

$pdo         = getDBConnection();
$currentUser = getCurrentUser();
$userRoles   = getUserRoles();
$activeRole  = getActiveRole();
$nama        = $currentUser['full_name'] ?: $currentUser['username'];

// Switch role aktif di sesi SSO
if (isset($_GET['switch_role']) && is_string($_GET['switch_role'])) {
    $targetRole = trim($_GET['switch_role']);
    if (hasRole($targetRole)) {
        setActiveRole($targetRole);
        header('Location: ' . BASE_URL . '/gateway');
        exit;
    }
}

// Hitung jumlah sesi perangkat aktif milik user & muat daftar Aplikasi Ekosistem langsung dari server (100% tahan reload)
$activeSessionsCount = 1;
$integratedCount = 0;
$initialIntegratedApps = [];
$initialOtherApps = [];
$hostNowHero = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHero = (strpos($hostNowHero, 'localhost') === 0 || strpos($hostNowHero, '127.0.0.1') === 0);
$learnLaunchUrl = $isLocalHero ? 'http://localhost/learn/login.php?sso_login=1' : 'https://learn.ilmuna.my.id/login.php?sso_login=1';

if (hasRole('admin')) {
    $initialIntegratedApps[] = [
        'id'          => 0,
        'slug'        => 'margo-admin',
        'name'        => 'Panel Kontrol Admin SSO',
        'description' => 'Kelola klien OAuth2, Public/Private Key, sesi global, agenda & audit log',
        'url'         => BASE_URL . '/dashboard/admin/',
        'icon'        => 'solar:shield-keyhole-bold',
        'badge'       => 'ADMIN',
        'is_admin'    => true,
        'external'    => false,
    ];
}

try {
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM sso_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()");
    $stCnt->execute([(int)$currentUser['id']]);
    $activeSessionsCount = max(1, (int)$stCnt->fetchColumn());

    $clientRows = $pdo->query("SELECT * FROM sso_clients WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $integratedCount = count($clientRows);
    foreach ($clientRows as $c) {
        $allowedRoles = margo_sso_parse_list($c['allowed_roles']);
        $canUse = empty($allowedRoles);
        if (!$canUse) {
            foreach ($userRoles as $ur) {
                if (in_array(strtolower((string)$ur), $allowedRoles, true)) {
                    $canUse = true;
                    break;
                }
            }
        }
        if (!$canUse) {
            continue;
        }
        $rawAppUrl = trim((string)$c['app_url']);
        $hostLbl = parse_url($rawAppUrl, PHP_URL_HOST) ?: $rawAppUrl;
        if ((string)$c['client_slug'] === 'ilmuna-learn') {
            $launchUrl = $isLocalHero
                ? 'http://localhost/learn/login.php?sso_login=1'
                : (rtrim($rawAppUrl, '/') . '/login.php?sso_login=1');
        } else {
            $launchUrl = (strpos($rawAppUrl, 'sso_login=') === false)
                ? ($rawAppUrl . (strpos($rawAppUrl, '?') === false ? '?' : '&') . 'sso_login=1')
                : $rawAppUrl;
        }
        $initialIntegratedApps[] = [
            'id'          => (int)$c['id'],
            'slug'        => (string)$c['client_slug'],
            'name'        => (string)$c['client_name'],
            'description' => 'Masuk otomatis ke ' . $hostLbl . ' dengan sesi Margo One SSO',
            'url'         => $launchUrl,
            'icon'        => !empty($c['app_icon']) ? (string)$c['app_icon'] : 'solar:widget-5-bold',
            'badge'       => 'SSO AKTIF',
            'is_admin'    => false,
            'external'    => true,
        ];
    }

    $extRows = $pdo->query("
        SELECT id, app_name, description, app_url, app_icon, badge_label
        FROM sso_external_apps
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($extRows as $ex) {
        $exUrl = trim((string)$ex['app_url']);
        $isExtUrl = (bool)preg_match('#^https?://#i', $exUrl);
        $resolvedUrl = $isExtUrl ? $exUrl : (rtrim(BASE_URL, '/') . '/' . ltrim($exUrl, '/'));
        $initialOtherApps[] = [
            'id'          => (int)$ex['id'],
            'name'        => (string)$ex['app_name'],
            'description' => (string)($ex['description'] ?? ''),
            'url'         => $resolvedUrl,
            'icon'        => !empty($ex['app_icon']) ? (string)$ex['app_icon'] : 'solar:widget-4-bold',
            'badge'       => (string)($ex['badge_label'] ?: 'EKOSISTEM'),
            'external'    => $isExtUrl,
        ];
    }
} catch (Throwable $e) {
    $activeSessionsCount = 1;
}

$forbiddenAlert = (isset($_GET['error']) && $_GET['error'] === 'forbidden');
$loginMethodLabel = ($currentUser['login_method'] === 'google_oauth') ? 'Google OAuth 2.0' : 'Kata Sandi Terpusat';
$gununganUrl = BASE_URL . '/assets/img/gunungan.png';

$pageTitle = 'Beranda Portal';
$pageIcon  = 'solar:home-smile-bold';

require_once BASE_PATH . '/includes/header.php';
?>

<!-- ========================================================================= -->
<!-- 1. ECOSYSTEM HERO BANNER (LUMINA GOLD & GUNUNGAN NUSANTARA)               -->
<!-- ========================================================================= -->
<section id="heroContent" class="margo-hero-banner">
    <img src="<?= htmlspecialchars($gununganUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="margo-hero-gunungan" aria-hidden="true">
    <div class="margo-hero-body">
        <div class="margo-hero-eyebrow">
            <span class="dot"></span>
            <span>Margo One · Sesi Single Sign-On Aktif</span>
        </div>
        <h1 class="margo-hero-title">Selamat datang, <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="margo-hero-desc">
            Akses seluruh layanan ekosistem digital Ilmuna cukup dengan satu autentikasi terpusat yang dilindungi enkripsi JWT RS256 &amp; Single Logout Global.
        </p>
        <div class="margo-hero-actions">
            <a href="<?= htmlspecialchars($learnLaunchUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="margo-hero-btn margo-hero-btn--white">
                <iconify-icon icon="solar:square-academic-cap-2-bold" width="17"></iconify-icon>
                <span>Buka Ilmuna Learn</span>
            </a>
            <a href="<?= htmlspecialchars(BASE_URL . '/agenda', ENT_QUOTES, 'UTF-8') ?>" class="margo-hero-btn margo-hero-btn--glass">
                <iconify-icon icon="solar:calendar-mark-bold" width="17"></iconify-icon>
                <span>Agenda Ekosistem</span>
            </a>
            <?php if (hasRole('admin')): ?>
                <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/', ENT_QUOTES, 'UTF-8') ?>" class="margo-hero-btn margo-hero-btn--gold">
                    <iconify-icon icon="solar:shield-keyhole-bold" width="17"></iconify-icon>
                    <span>Kontrol Admin</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="margo-hero-aside">
        <div class="margo-hero-stat">
            <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
            <span>Metode: <strong><?= htmlspecialchars($loginMethodLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
        </div>
        <div class="margo-hero-stat">
            <iconify-icon icon="solar:devices-bold"></iconify-icon>
            <span>Perangkat Aktif: <strong><?= (int)$activeSessionsCount ?> sesi</strong></span>
        </div>
        <div class="margo-hero-stat">
            <iconify-icon icon="solar:link-circle-bold"></iconify-icon>
            <span>Aplikasi SSO: <strong><?= (int)$integratedCount ?> terhubung</strong></span>
        </div>
    </div>
</section>

<!-- ========================================================================= -->
<!-- 2. APLIKASI EKOSISTEM (LIVE SEARCH + 3D HOVER CARDS — SERVER PRE-RENDERED)-->
<!-- ========================================================================= -->
<section id="apps-container" style="margin-bottom:24px;">
    <div class="margo-section-bar">
        <h2 class="margo-section-h2">
            <iconify-icon icon="solar:widget-5-bold" style="color:#0D7A87;"></iconify-icon>
            <span>Aplikasi Ekosistem</span>
        </h2>
        <div class="margo-search-box">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="appSearchInput" class="margo-search-input" placeholder="Cari aplikasi atau layanan...">
        </div>
    </div>

    <div id="appsLoadedWrap">
        <div id="integratedAppsSection" style="<?= empty($initialIntegratedApps) ? 'display:none;' : '' ?>">
            <h3 class="margo-section-h3">
                <iconify-icon icon="solar:link-circle-bold" width="17"></iconify-icon>
                <span>Terintegrasi Margo One SSO</span>
            </h3>
            <div id="integratedAppsGrid" class="margo-apps-grid">
                <?php foreach ($initialIntegratedApps as $app):
                    $frameCls = 'margo-app-frame' . (!empty($app['is_admin']) ? ' is-admin-app' : '');
                    $targetAt = !empty($app['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                    $arrIcon  = !empty($app['external']) ? 'solar:arrow-right-up-bold' : 'solar:arrow-right-bold';
                ?>
                    <a href="<?= htmlspecialchars((string)$app['url'], ENT_QUOTES, 'UTF-8') ?>" class="<?= $frameCls ?>"<?= $targetAt ?>>
                        <div class="margo-app-card">
                            <div class="margo-app-icon"><iconify-icon icon="<?= htmlspecialchars((string)($app['icon'] ?: 'solar:widget-5-bold'), ENT_QUOTES, 'UTF-8') ?>"></iconify-icon></div>
                            <div class="margo-app-info">
                                <div class="margo-app-title-row">
                                    <span class="margo-app-name"><?= htmlspecialchars((string)$app['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="margo-app-pill"><?= htmlspecialchars((string)($app['badge'] ?: 'SSO'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="margo-app-desc"><?= htmlspecialchars((string)($app['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <iconify-icon icon="<?= $arrIcon ?>" class="margo-app-arrow"></iconify-icon>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="otherAppsSection" style="<?= empty($initialOtherApps) ? 'display:none;' : '' ?>">
            <h3 class="margo-section-h3 is-muted">
                <iconify-icon icon="solar:widget-4-bold" width="17"></iconify-icon>
                <span>Layanan &amp; Tautan Ekosistem Lainnya</span>
            </h3>
            <div id="otherAppsGrid" class="margo-apps-grid">
                <?php foreach ($initialOtherApps as $app):
                    $targetAt = !empty($app['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                    $arrIcon  = !empty($app['external']) ? 'solar:arrow-right-up-bold' : 'solar:arrow-right-bold';
                ?>
                    <a href="<?= htmlspecialchars((string)$app['url'], ENT_QUOTES, 'UTF-8') ?>" class="margo-app-frame is-external"<?= $targetAt ?>>
                        <div class="margo-app-card">
                            <div class="margo-app-icon"><iconify-icon icon="<?= htmlspecialchars((string)($app['icon'] ?: 'solar:widget-4-bold'), ENT_QUOTES, 'UTF-8') ?>"></iconify-icon></div>
                            <div class="margo-app-info">
                                <div class="margo-app-title-row">
                                    <span class="margo-app-name"><?= htmlspecialchars((string)$app['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="margo-app-pill"><?= htmlspecialchars((string)($app['badge'] ?: 'EKOSISTEM'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="margo-app-desc"><?= htmlspecialchars((string)($app['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <iconify-icon icon="<?= $arrIcon ?>" class="margo-app-arrow"></iconify-icon>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="appsEmptyState" style="display:none;text-align:center;padding:34px 16px;background:#FFFFFF;border:1px dashed #CBD5E1;border-radius:18px;color:#64748B;">
            <iconify-icon icon="solar:magnifer-zoom-out-bold" width="34" style="color:#94A3B8;margin-bottom:6px;"></iconify-icon>
            <div style="font-weight:700;color:#1D3B5E;">Aplikasi tidak ditemukan</div>
            <div style="font-size:12.5px;margin-top:3px;">Coba kata kunci pencarian lain.</div>
        </div>
    </div>
</section>

<!-- ========================================================================= -->
<!-- 3. AKTIVITAS & LOGIN TERAKHIR (PAGINATED AJAX — ZERO RELOAD)              -->
<!-- ========================================================================= -->
<section class="margo-activity-panel" id="activitySection">
    <div class="margo-section-bar" style="margin-bottom:14px;">
        <h2 class="margo-section-h2">
            <iconify-icon icon="solar:history-bold" style="color:#0D7A87;"></iconify-icon>
            <span>Aktivitas &amp; Sesi Terakhir</span>
        </h2>
        <a href="<?= htmlspecialchars(BASE_URL . '/profile?tab=sessions', ENT_QUOTES, 'UTF-8') ?>" class="margo-rwidget-link" style="font-size:12.5px;">
            <span>Kelola Perangkat</span>
            <iconify-icon icon="solar:arrow-right-linear" width="14"></iconify-icon>
        </a>
    </div>

    <div id="recentLoginsWrap" class="margo-login-cards">
        <div class="margo-skel" style="height:68px;border-radius:14px;"></div>
        <div class="margo-skel" style="height:68px;border-radius:14px;"></div>
    </div>

    <h3 class="margo-section-h3 is-muted" style="margin-top:4px;">
        <iconify-icon icon="solar:shield-check-bold" width="16"></iconify-icon>
        <span>Riwayat Autentikasi Akun Anda</span>
    </h3>
    <div id="activityLogsWrap" class="margo-activity-rows">
        <div class="margo-skel" style="height:52px;border-radius:13px;"></div>
        <div class="margo-skel" style="height:52px;border-radius:13px;"></div>
        <div class="margo-skel" style="height:52px;border-radius:13px;"></div>
    </div>

    <div class="margo-pagination" id="activityPagination" style="display:none;">
        <div id="activityPageInfo" style="font-size:12px;color:#64748B;"></div>
        <div id="activityPageBtns" class="margo-page-btns"></div>
    </div>
</section>

<script>
(function () {
    'use strict';
    var BASE_URL = <?= json_encode(BASE_URL) ?>;
    var FORBIDDEN = <?= $forbiddenAlert ? 'true' : 'false' ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function revealElement(skelId, contentId) {
        var sk = document.getElementById(skelId);
        var ct = document.getElementById(contentId);
        if (sk) sk.style.display = 'none';
        if (ct) {
            ct.style.display = '';
            requestAnimationFrame(function () { ct.style.opacity = '1'; });
        }
    }

    var ALL_INTEGRATED = <?= json_encode($initialIntegratedApps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var ALL_OTHERS = <?= json_encode($initialOtherApps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function renderAppCard(app, isExternalGroup) {
        var frameCls = 'margo-app-frame' + (app.is_admin ? ' is-admin-app' : (isExternalGroup ? ' is-external' : ''));
        var targetAttrs = app.external ? ' target="_blank" rel="noopener noreferrer"' : '';
        var arrowIcon = app.external ? 'solar:arrow-right-up-bold' : 'solar:arrow-right-bold';
        return '<a href="' + esc(app.url) + '" class="' + frameCls + '"' + targetAttrs + '>'
            + '<div class="margo-app-card">'
            + '<div class="margo-app-icon"><iconify-icon icon="' + esc(app.icon || 'solar:widget-5-bold') + '"></iconify-icon></div>'
            + '<div class="margo-app-info">'
            + '<div class="margo-app-title-row">'
            + '<span class="margo-app-name">' + esc(app.name) + '</span>'
            + '<span class="margo-app-pill">' + esc(app.badge || 'SSO') + '</span>'
            + '</div>'
            + '<div class="margo-app-desc">' + esc(app.description || '') + '</div>'
            + '</div>'
            + '<iconify-icon icon="' + arrowIcon + '" class="margo-app-arrow"></iconify-icon>'
            + '</div>'
            + '</a>';
    }

    function renderAppsFiltered() {
        var q = (document.getElementById('appSearchInput').value || '').toLowerCase().trim();
        var matchFn = function (a) {
            if (!q) return true;
            return ((a.name || '') + ' ' + (a.description || '') + ' ' + (a.badge || '')).toLowerCase().indexOf(q) !== -1;
        };
        var intList = ALL_INTEGRATED.filter(matchFn);
        var othList = ALL_OTHERS.filter(matchFn);

        var intSec = document.getElementById('integratedAppsSection');
        var othSec = document.getElementById('otherAppsSection');
        var empBox = document.getElementById('appsEmptyState');

        intSec.style.display = intList.length ? '' : 'none';
        othSec.style.display = othList.length ? '' : 'none';
        empBox.style.display = (!intList.length && !othList.length) ? 'block' : 'none';

        document.getElementById('integratedAppsGrid').innerHTML = intList.map(function (a) { return renderAppCard(a, false); }).join('');
        document.getElementById('otherAppsGrid').innerHTML = othList.map(function (a) { return renderAppCard(a, true); }).join('');
    }

    async function loadApps() {
        renderAppsFiltered();
        try {
            var r = await fetch(BASE_URL + '/portal-data.php?resource=apps', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            var res = await r.json();
            if (res && res.success && res.data && Array.isArray(res.data.integrated) && res.data.integrated.length) {
                ALL_INTEGRATED = res.data.integrated;
                ALL_OTHERS = res.data.others || [];
                renderAppsFiltered();
            }
        } catch (e) {}
    }

    async function loadActivity(page) {
        page = page || 1;
        try {
            var r = await fetch(BASE_URL + '/portal-data.php?resource=activity&page=' + page + '&per_page=6', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            var res = await r.json();
            if (!res || !res.success) return;
            var d = res.data;

            // Recent logins
            var rlWrap = document.getElementById('recentLoginsWrap');
            var logins = d.recent_logins || [];
            if (!logins.length) {
                rlWrap.innerHTML = '<div style="font-size:12.5px;color:#64748B;">Belum ada riwayat sesi perangkat.</div>';
            } else {
                rlWrap.innerHTML = logins.map(function (s) {
                    var badge = s.is_current
                        ? '<span class="ilmuna-badge ilmuna-badge--success">Perangkat Ini</span>'
                        : (s.is_active ? '<span class="ilmuna-badge ilmuna-badge--teal">Aktif</span>' : '<span class="ilmuna-badge ilmuna-badge--navy">Berakhir</span>');
                    return '<div class="margo-login-card">'
                        + '<div>'
                        + '<div class="margo-login-card-title"><iconify-icon icon="solar:laptop-minimalistic-bold" style="color:#0D7A87;"></iconify-icon> ' + esc(s.device) + '</div>'
                        + '<div class="margo-login-card-sub">' + esc(s.login_method) + ' · IP ' + esc(s.ip_address) + ' · ' + esc(s.last_activity_at) + '</div>'
                        + '</div>'
                        + badge
                        + '</div>';
                }).join('');
            }

            // Paginated audit logs
            var logsWrap = document.getElementById('activityLogsWrap');
            var logs = d.logs || [];
            if (!logs.length) {
                logsWrap.innerHTML = '<div style="text-align:center;padding:20px;font-size:12.5px;color:#64748B;">Belum ada catatan aktivitas autentikasi.</div>';
            } else {
                logsWrap.innerHTML = logs.map(function (l) {
                    var icon = l.ok ? 'solar:shield-check-bold' : 'solar:danger-triangle-bold';
                    var badge = l.ok
                        ? '<span class="ilmuna-badge ilmuna-badge--success">OK ' + esc(l.http_status) + '</span>'
                        : '<span class="ilmuna-badge ilmuna-badge--danger">ERR ' + esc(l.http_status) + '</span>';
                    return '<div class="margo-act-row row-smooth-in">'
                        + '<div class="margo-act-left">'
                        + '<div class="margo-act-icon' + (l.ok ? '' : ' is-err') + '"><iconify-icon icon="' + icon + '"></iconify-icon></div>'
                        + '<div style="min-width:0;">'
                        + '<div class="margo-act-title">' + esc(l.label) + '</div>'
                        + '<div class="margo-act-meta">' + esc(l.client_name) + ' · ' + esc(l.ip_address) + ' · ' + esc(l.created_at) + '</div>'
                        + '</div>'
                        + '</div>'
                        + badge
                        + '</div>';
                }).join('');
            }

            // Pagination controls
            var pg = d.pagination || { page: 1, total_pages: 1, total: 0 };
            var pgWrap = document.getElementById('activityPagination');
            if (pg.total > 0) {
                pgWrap.style.display = 'flex';
                document.getElementById('activityPageInfo').textContent = 'Halaman ' + pg.page + ' dari ' + pg.total_pages + ' · Total ' + pg.total + ' aktivitas';
                var btnsHtml = '';
                btnsHtml += '<button type="button" class="margo-page-btn" ' + (pg.page <= 1 ? 'disabled' : 'onclick="MARGO_PORTAL.loadActivity(' + (pg.page - 1) + ')"') + '>Sebelumnya</button>';
                var startP = Math.max(1, pg.page - 2);
                var endP   = Math.min(pg.total_pages, pg.page + 2);
                for (var p = startP; p <= endP; p++) {
                    btnsHtml += '<button type="button" class="margo-page-btn' + (p === pg.page ? ' is-active' : '') + '" onclick="MARGO_PORTAL.loadActivity(' + p + ')">' + p + '</button>';
                }
                btnsHtml += '<button type="button" class="margo-page-btn" ' + (pg.page >= pg.total_pages ? 'disabled' : 'onclick="MARGO_PORTAL.loadActivity(' + (pg.page + 1) + ')"') + '>Berikutnya</button>';
                document.getElementById('activityPageBtns').innerHTML = btnsHtml;
            } else {
                pgWrap.style.display = 'none';
            }
        } catch (e) {}
    }

    window.MARGO_PORTAL = { loadActivity: loadActivity };

    document.addEventListener('DOMContentLoaded', function () {
        renderAppsFiltered();
        loadActivity(1);

        if (window.location.search.indexOf('section=apps') !== -1 || window.location.hash === '#apps-container') {
            var appsEl = document.getElementById('apps-container');
            var mainEl = document.getElementById('mainContent');
            if (appsEl && mainEl) {
                setTimeout(function () {
                    mainEl.scrollTo({ top: Math.max(0, appsEl.offsetTop - 76), behavior: 'smooth' });
                }, 80);
            }
        }

        var searchInp = document.getElementById('appSearchInput');
        if (searchInp) searchInp.addEventListener('input', renderAppsFiltered);

        if (FORBIDDEN && window.Ilmuna) {
            window.Ilmuna.warning('Akses Dibatasi', 'Anda tidak memiliki hak peran untuk membuka halaman yang diminta sebelumnya.');
        }
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
