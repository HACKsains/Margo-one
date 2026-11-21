<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · PROFIL & KEAMANAN AKUN TERPUSAT (/profile)
 * ==============================================================================
 * Fitur:
 *   1. Ubah Kata Sandi Terpusat (verifikasi password lama + opsi cabut sesi lain)
 *   2. Tautkan / Putuskan Akun Google SSO (users.email_google — strict unique)
 *   3. Kelola & Cabut Sesi Perangkat Aktif Global (sso_sessions)
 *
 * Standar UI: Data-First Zero Title, Segmented Tabs (.curriculum-main-tabs),
 *             Iconify Solar Icons, window.Ilmuna, Zero Page Reload, Mobile 360px+.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();

$pdo         = getDBConnection();
$currentUser = getCurrentUser();
$userId      = (int)$currentUser['id'];
$currentSid  = (int)($currentUser['sso_session_id'] ?? 0);

if (empty($_SESSION['csrf_profile_token'])) {
    $_SESSION['csrf_profile_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_profile_token'];

/* ==============================================================================
 * AJAX JSON API (?api=1) — ZERO PAGE RELOAD
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        if ($act === 'bootstrap') {
            $identity = margo_resolve_user_identity($pdo, $userId, getActiveRole());
            $uStmt = $pdo->prepare("SELECT id, username, email, email_google, google_id, full_name, nisn_nip, last_login, created_at FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $sStmt = $pdo->prepare("
                SELECT id, active_role, ip_address, user_agent, login_method, expires_at, last_activity_at, created_at
                FROM sso_sessions
                WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
                ORDER BY last_activity_at DESC
            ");
            $sStmt->execute([$userId]);
            $sessions = $sStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sessions as &$s) {
                $s['is_current'] = ((int)$s['id'] === $currentSid);
            }
            unset($s);

            echo json_encode([
                'success' => true,
                'data'    => [
                    'user'     => [
                        'id'           => (int)($uRow['id'] ?? $userId),
                        'username'     => (string)($uRow['username'] ?? ''),
                        'full_name'    => (string)($identity['full_name'] ?? $uRow['full_name'] ?? ''),
                        'email'        => (string)($uRow['email'] ?? ''),
                        'email_google' => $uRow['email_google'] ? (string)$uRow['email_google'] : '',
                        'nisn_nip'     => (string)($uRow['nisn_nip'] ?? ''),
                        'roles'        => $identity['roles'] ?? [],
                        'primary_role' => $identity['primary_role'] ?? null,
                        'guru_id'      => $identity['guru_id'] ?? null,
                        'siswa_id'     => $identity['siswa_id'] ?? null,
                        'nama_kelas'   => $identity['nama_kelas'] ?? null,
                        'last_login'   => $uRow['last_login'] ?? null,
                    ],
                    'sessions' => $sessions,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Validasi CSRF untuk seluruh mutasi POST
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
            exit;
        }

        if ($act === 'change_password') {
            $oldPass     = (string)($_POST['current_password'] ?? '');
            $newPass     = (string)($_POST['new_password'] ?? '');
            $confirmPass = (string)($_POST['confirm_password'] ?? '');
            $revokeOther = !empty($_POST['revoke_other_sessions']);

            if ($oldPass === '' || $newPass === '') {
                echo json_encode(['success' => false, 'message' => 'Kata sandi saat ini dan kata sandi baru wajib diisi.']);
                exit;
            }
            if (strlen($newPass) < 8) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi baru minimal 8 karakter.']);
                exit;
            }
            if ($newPass !== $confirmPass) {
                echo json_encode(['success' => false, 'message' => 'Konfirmasi kata sandi baru tidak cocok.']);
                exit;
            }

            $stU = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stU->execute([$userId]);
            $currHash = (string)$stU->fetchColumn();
            if ($currHash === '' || !password_verify($oldPass, $currHash)) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi saat ini salah.']);
                exit;
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $userId]);

            $revokedCnt = 0;
            if ($revokeOther) {
                $revokedCnt = margo_revoke_all_user_sessions($pdo, $userId, $currentSid, true);
            }

            margo_sso_log_audit($pdo, 'password_changed', [
                'user_id'     => $userId,
                'http_status' => 200,
                'detail'      => ['revoked_other_sessions' => $revokedCnt],
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Kata sandi terpusat Margo One berhasil diperbarui' . ($revokedCnt > 0 ? " ({$revokedCnt} sesi perangkat lain dicabut)." : '.'),
            ]);
            exit;
        }

        if ($act === 'link_google') {
            $emailGoogle = strtolower(trim((string)($_POST['email_google'] ?? '')));
            $currentPass = (string)($_POST['verify_password'] ?? '');

            if ($emailGoogle === '' || !filter_var($emailGoogle, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Masukkan alamat email Google yang valid.']);
                exit;
            }
            if ($currentPass === '') {
                echo json_encode(['success' => false, 'message' => 'Masukkan kata sandi akun Anda untuk konfirmasi keamanan penautan Google.']);
                exit;
            }

            $stU = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stU->execute([$userId]);
            $currHash = (string)$stU->fetchColumn();
            if ($currHash === '' || !password_verify($currentPass, $currHash)) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi verifikasi salah.']);
                exit;
            }

            // Pastikan email_google belum dipakai akun lain
            $stDup = $pdo->prepare("SELECT id FROM users WHERE LOWER(email_google) = ? AND id != ? LIMIT 1");
            $stDup->execute([$emailGoogle, $userId]);
            if ($stDup->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Email Google tersebut sudah ditautkan ke akun pengguna lain.']);
                exit;
            }

            $pdo->prepare("UPDATE users SET email_google = ? WHERE id = ?")->execute([$emailGoogle, $userId]);
            $_SESSION['user_email_google'] = $emailGoogle;

            margo_sso_log_audit($pdo, 'google_linked', [
                'user_id'     => $userId,
                'http_status' => 200,
                'detail'      => ['email_google' => $emailGoogle],
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Akun Google (' . $emailGoogle . ') berhasil ditautkan ke Margo One SSO.',
                'data'    => ['email_google' => $emailGoogle],
            ]);
            exit;
        }

        if ($act === 'unlink_google') {
            $pdo->prepare("UPDATE users SET email_google = NULL, google_id = NULL WHERE id = ?")->execute([$userId]);
            $_SESSION['user_email_google'] = null;

            margo_sso_log_audit($pdo, 'google_unlinked', [
                'user_id'     => $userId,
                'http_status' => 200,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Tautan akun Google SSO berhasil diputuskan.',
            ]);
            exit;
        }

        if ($act === 'revoke_session') {
            $targetSid = (int)($_POST['session_id'] ?? 0);
            $chk = $pdo->prepare("SELECT id FROM sso_sessions WHERE id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$targetSid, $userId]);
            if (!$chk->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Sesi tidak ditemukan atau bukan milik Anda.']);
                exit;
            }
            margo_revoke_sso_session($pdo, $targetSid, $userId, true);
            $isSelf = ($targetSid === $currentSid);
            if ($isSelf) {
                logoutUserLocalOnly();
            }
            echo json_encode([
                'success' => true,
                'message' => 'Sesi perangkat berhasil dicabut secara global.',
                'data'    => ['is_self' => $isSelf, 'redirect' => BASE_URL . '/login?msg=logged_out'],
            ]);
            exit;
        }

        if ($act === 'revoke_other_sessions') {
            $cnt = margo_revoke_all_user_sessions($pdo, $userId, $currentSid, true);
            echo json_encode([
                'success' => true,
                'message' => $cnt > 0 ? ($cnt . ' sesi perangkat lain berhasil dikeluarkan.') : 'Tidak ada sesi perangkat lain yang aktif.',
                'data'    => ['revoked_count' => $cnt],
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Profil & Keamanan Akun';
$pageIcon  = 'solar:user-circle-bold';

$extraHead = '<style>
.mprof-app{display:flex;flex-direction:column;gap:16px}
.mprof-pane{display:none}.mprof-pane.is-active{display:flex;flex-direction:column;gap:16px}
.mprof-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:18px 20px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.mprof-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #F1F5F9}
.mprof-card-title{margin:0;font-family:"Outfit",sans-serif;font-size:15px;font-weight:800;color:#1D3B5E;display:inline-flex;align-items:center;gap:8px}
.mprof-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:768px){.mprof-grid-2{grid-template-columns:1fr}}
.mprof-field{display:flex;flex-direction:column;gap:5px}
.mprof-field label{font-size:12.5px;font-weight:700;color:#334155}
.mprof-input{width:100%;padding:10px 12px;min-height:44px;border:1.5px solid #CBD5E1;border-radius:10px;font-size:14px;background:#fff;color:#0F172A;font-family:inherit}
.mprof-input:focus{outline:none;border-color:#0D7A87;box-shadow:0 0 0 3px rgba(13,122,135,.14)}
.mprof-btn{appearance:none;border:1px solid transparent;border-radius:10px;padding:9px 15px;min-height:42px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;font-family:inherit}
.mprof-btn--primary{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff}
.mprof-btn--danger{background:#FEF2F2;color:#B91C1C;border-color:#FECACA}
.mprof-btn--ghost{background:#F8FAFC;color:#1D3B5E;border-color:#CBD5E1}
.mprof-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700}
.mprof-badge--ok{background:#DCFCE7;color:#15803D}
.mprof-badge--warn{background:#FEF3C7;color:#B45309}
.mprof-badge--teal{background:#CCFBF1;color:#0F766E}
.mprof-meta-strip{display:flex;flex-wrap:wrap;gap:10px;padding:12px 14px;border-radius:12px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:13px;color:#334155}
.mprof-meta-item{display:inline-flex;align-items:center;gap:6px}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="mprof-app">
    <!-- DATA-FIRST ZERO TITLE: LANGSUNG SEGMENTED TABS -->
    <div class="curriculum-main-tabs" id="mprofTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="security" role="tab" aria-selected="true">
            <iconify-icon icon="solar:lock-password-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kata Sandi &amp; Identitas</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="google" role="tab" aria-selected="false">
            <iconify-icon icon="solar:link-circle-bold"></iconify-icon>
            <span class="curriculum-tab-label">Tautan Google SSO</span>
            <span class="curriculum-tab-counter" id="cntGoogleStatus">-</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="sessions" role="tab" aria-selected="false">
            <iconify-icon icon="solar:devices-bold"></iconify-icon>
            <span class="curriculum-tab-label">Sesi Perangkat Aktif</span>
            <span class="curriculum-tab-counter" id="cntMySessions">0</span>
        </button>
    </div>

    <!-- TAB 1: KATA SANDI & IDENTITAS -->
    <section class="mprof-pane is-active" id="mprofPane_security">
        <div class="mprof-card">
            <div class="mprof-card-head">
                <h3 class="mprof-card-title">
                    <iconify-icon icon="solar:user-id-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Ringkasan Identitas Margo One
                </h3>
                <div id="mprofRolesWrap" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
            </div>
            <div class="mprof-meta-strip" id="mprofIdentityMeta">Memuat profil...</div>
        </div>

        <form class="mprof-card" id="formChangePassword">
            <div class="mprof-card-head">
                <h3 class="mprof-card-title">
                    <iconify-icon icon="solar:key-minimalistic-square-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Ubah Kata Sandi Terpusat
                </h3>
                <button type="submit" class="mprof-btn mprof-btn--primary" id="btnSavePass">
                    <iconify-icon icon="solar:diskette-bold" width="17"></iconify-icon>
                    <span>Simpan Kata Sandi</span>
                </button>
            </div>
            <div class="mprof-grid-2">
                <div class="mprof-field">
                    <label for="currPassword">Kata Sandi Saat Ini</label>
                    <input type="password" id="currPassword" class="mprof-input" required autocomplete="current-password">
                </div>
                <div></div>
                <div class="mprof-field">
                    <label for="newPassword">Kata Sandi Baru (min. 8 karakter)</label>
                    <input type="password" id="newPassword" class="mprof-input" minlength="8" required autocomplete="new-password">
                </div>
                <div class="mprof-field">
                    <label for="confPassword">Konfirmasi Kata Sandi Baru</label>
                    <input type="password" id="confPassword" class="mprof-input" minlength="8" required autocomplete="new-password">
                </div>
            </div>
            <label style="display:inline-flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;font-weight:600;color:#334155;cursor:pointer;">
                <input type="checkbox" id="chkRevokeOther" checked>
                Keluar otomatis dari seluruh perangkat lain setelah kata sandi berubah
            </label>
        </form>
    </section>

    <!-- TAB 2: TAUTAN GOOGLE SSO -->
    <section class="mprof-pane" id="mprofPane_google">
        <div class="mprof-card">
            <div class="mprof-card-head">
                <h3 class="mprof-card-title">
                    <iconify-icon icon="solar:shield-check-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Integrasi Google OAuth 2.0 (Strict Linked-Only)
                </h3>
                <span class="mprof-badge mprof-badge--teal">Hanya email tertaut yang dapat masuk via Google</span>
            </div>
            <div id="mprofGoogleBox">Memuat status tautan...</div>
        </div>
    </section>

    <!-- TAB 3: SESI PERANGKAT AKTIF -->
    <section class="mprof-pane" id="mprofPane_sessions">
        <div class="mprof-card">
            <div class="mprof-card-head">
                <h3 class="mprof-card-title">
                    <iconify-icon icon="solar:devices-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Daftar Sesi Aktif Lintas Ekosistem
                </h3>
                <button type="button" class="mprof-btn mprof-btn--danger" onclick="MPROF.revokeOthers()">
                    <iconify-icon icon="solar:logout-2-bold" width="16"></iconify-icon>
                    <span>Keluar Semua Perangkat Lain</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Perangkat &amp; IP</th>
                            <th>Metode Masuk</th>
                            <th>Aktivitas Terakhir</th>
                            <th>Berakhir</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="mprofSessionsTbody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat sesi aktif...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    var ENDPOINT = <?= json_encode(BASE_URL . '/profile.php?api=1') ?>;
    var CSRF     = <?= json_encode($csrfToken) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    async function postApi(action, data) {
        var fd = new FormData();
        fd.append('api_action', action);
        fd.append('csrf_token', CSRF);
        if (data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        var r = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        return await r.json();
    }

    function activateProfTab(tab) {
        var valid = ['security', 'google', 'sessions'];
        if (valid.indexOf(tab) === -1) tab = 'security';
        document.querySelectorAll('#mprofTabs .curriculum-tab-btn').forEach(function (b) {
            var act = b.getAttribute('data-tab') === tab;
            b.classList.toggle('is-active', act);
            b.setAttribute('aria-selected', act ? 'true' : 'false');
        });
        document.querySelectorAll('.mprof-pane').forEach(function (p) {
            p.classList.toggle('is-active', p.id === 'mprofPane_' + tab);
        });
    }

    function bindTabs() {
        document.querySelectorAll('#mprofTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateProfTab(btn.getAttribute('data-tab'));
            });
        });
        var params = new URLSearchParams(window.location.search);
        var initTab = params.get('tab');
        if (initTab) activateProfTab(initTab);
    }

    function renderGoogle(u) {
        var box = document.getElementById('mprofGoogleBox');
        var cnt = document.getElementById('cntGoogleStatus');
        if (u.email_google) {
            cnt.textContent = 'TERTAUT';
            box.innerHTML =
                '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px;border-radius:12px;background:#F0FDF4;border:1px solid #BBF7D0;">'
                + '<div><div class="mprof-badge mprof-badge--ok"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Tertaut Aktif</div>'
                + '<div style="font-weight:800;color:#0F1D2F;font-size:15px;margin-top:6px;">' + esc(u.email_google) + '</div>'
                + '<div style="font-size:12px;color:#475569;margin-top:2px;">Anda dapat masuk satu klik dengan akun Google ini di Margo One SSO.</div></div>'
                + '<button type="button" class="mprof-btn mprof-btn--danger" onclick="MPROF.unlinkGoogle()"><iconify-icon icon="solar:link-broken-bold" width="16"></iconify-icon> Putuskan Tautan</button>'
                + '</div>';
        } else {
            cnt.textContent = 'BELUM';
            box.innerHTML =
                '<form id="formLinkGoogle" style="display:flex;flex-direction:column;gap:12px;">'
                + '<div style="padding:10px 12px;border-radius:10px;background:#FFFBEB;border:1px solid #FDE68A;font-size:13px;color:#92400E;">Akun Google Anda belum ditautkan. Tautkan email Gmail Anda di bawah agar tombol <strong>Masuk dengan Google</strong> aktif untuk akun ini.</div>'
                + '<div class="mprof-grid-2">'
                + '<div class="mprof-field"><label for="inpGoogleEmail">Alamat Email Google</label><input type="email" id="inpGoogleEmail" class="mprof-input" placeholder="nama@gmail.com" required></div>'
                + '<div class="mprof-field"><label for="inpGooglePass">Verifikasi Kata Sandi Saat Ini</label><input type="password" id="inpGooglePass" class="mprof-input" placeholder="Kata sandi akun Margo One" required></div>'
                + '</div>'
                + '<div><button type="submit" class="mprof-btn mprof-btn--primary"><iconify-icon icon="solar:link-circle-bold" width="17"></iconify-icon> Tautkan Akun Google</button></div>'
                + '</form>';

            var f = document.getElementById('formLinkGoogle');
            if (f) {
                f.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    var res = await postApi('link_google', {
                        email_google: document.getElementById('inpGoogleEmail').value.trim(),
                        verify_password: document.getElementById('inpGooglePass').value
                    });
                    if (res.success) {
                        Ilmuna.success('Berhasil Ditautkan', res.message);
                        MPROF.load();
                    } else {
                        Ilmuna.error('Gagal', res.message || 'Gagal menautkan akun Google.');
                    }
                });
            }
        }
    }

    function renderSessions(list) {
        document.getElementById('cntMySessions').textContent = list.length;
        var tb = document.getElementById('mprofSessionsTbody');
        if (!list.length) {
            tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Tidak ada sesi aktif.</td></tr>';
            return;
        }
        tb.innerHTML = list.map(function (s) {
            var badge = s.is_current ? '<span class="mprof-badge mprof-badge--ok">Perangkat Ini</span>' : '<span class="mprof-badge mprof-badge--teal">Aktif</span>';
            var methodLbl = s.login_method === 'google_oauth' ? 'Google OAuth' : 'Kata Sandi';
            return '<tr id="my-sess-' + s.id + '">'
                + '<td data-label="Perangkat & IP"><strong>' + esc(s.ip_address) + '</strong> ' + badge + '<div style="font-size:11.5px;color:#64748B;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(s.user_agent || '-') + '</div></td>'
                + '<td data-label="Metode">' + esc(methodLbl) + '</td>'
                + '<td data-label="Aktivitas">' + esc(s.last_activity_at) + '</td>'
                + '<td data-label="Berakhir">' + esc(s.expires_at) + '</td>'
                + '<td data-label="Aksi" style="text-align:right;"><button type="button" class="mprof-btn mprof-btn--danger" onclick="MPROF.revokeOne(' + s.id + ',' + (s.is_current ? 'true' : 'false') + ')"><iconify-icon icon="solar:logout-2-bold" width="15"></iconify-icon> Cabut</button></td>'
                + '</tr>';
        }).join('');
    }

    window.MPROF = {
        load: async function () {
            try {
                var r = await fetch(ENDPOINT + '&api_action=bootstrap', { credentials: 'same-origin' });
                var res = await r.json();
                if (!res.success) return;
                var u = res.data.user;
                document.getElementById('mprofRolesWrap').innerHTML = (u.roles || []).map(function (rl) {
                    return '<span class="mprof-badge mprof-badge--teal">' + esc(rl.toUpperCase()) + '</span>';
                }).join('');
                document.getElementById('mprofIdentityMeta').innerHTML =
                    '<span class="mprof-meta-item"><iconify-icon icon="solar:user-bold" style="color:#0D7A87;"></iconify-icon> <strong>' + esc(u.full_name) + '</strong> (@' + esc(u.username) + ')</span>'
                    + '<span class="mprof-meta-item"><iconify-icon icon="solar:letter-bold" style="color:#0D7A87;"></iconify-icon> ' + esc(u.email || '-') + '</span>'
                    + (u.nama_kelas ? '<span class="mprof-meta-item"><iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;"></iconify-icon> ' + esc(u.nama_kelas) + '</span>' : '')
                    + '<span class="mprof-meta-item"><iconify-icon icon="solar:clock-circle-linear" style="color:#64748B;"></iconify-icon> Login terakhir: ' + esc(u.last_login || 'Baru saja') + '</span>';

                renderGoogle(u);
                renderSessions(res.data.sessions || []);
            } catch (e) {
                if (window.Ilmuna) Ilmuna.error('Kesalahan', 'Gagal memuat data profil.');
            }
        },
        unlinkGoogle: async function () {
            var ok = await Ilmuna.confirm('warning', 'Putuskan Google SSO?', 'Anda tidak lagi bisa masuk menggunakan Google hingga menautkannya kembali.', 'Ya, Putuskan', 'Batal');
            if (!ok) return;
            var res = await postApi('unlink_google', {});
            if (res.success) {
                Ilmuna.success('Berhasil', res.message);
                MPROF.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal memutuskan tautan.');
            }
        },
        revokeOne: async function (sid, isSelf) {
            var msg = isSelf
                ? 'Ini adalah sesi perangkat Anda saat ini. Anda akan langsung keluar dari Margo One.'
                : 'Perangkat tersebut akan segera dikeluarkan dari seluruh aplikasi ekosistem Ilmuna.';
            var ok = await Ilmuna.confirm('error', 'Cabut Sesi Perangkat?', msg, 'Ya, Cabut Sesi', 'Batal');
            if (!ok) return;
            var res = await postApi('revoke_session', { session_id: sid });
            if (res.success) {
                if (res.data && res.data.is_self) {
                    window.location.href = res.data.redirect;
                    return;
                }
                var row = document.getElementById('my-sess-' + sid);
                if (row) {
                    row.classList.add('row-smooth-out');
                    setTimeout(function () { row.remove(); MPROF.load(); }, 280);
                }
                Ilmuna.success('Sesi Dicabut', res.message);
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal mencabut sesi.');
            }
        },
        revokeOthers: async function () {
            var ok = await Ilmuna.confirm('error', 'Keluar Semua Perangkat Lain?', 'Seluruh sesi aktif di browser/perangkat lain akan langsung dicabut secara global.', 'Ya, Keluarkan Semua', 'Batal');
            if (!ok) return;
            var res = await postApi('revoke_other_sessions', {});
            if (res.success) {
                Ilmuna.success('Berhasil', res.message);
                MPROF.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal mencabut sesi lain.');
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        bindTabs();
        MPROF.load();
        var formPass = document.getElementById('formChangePassword');
        if (formPass) {
            formPass.addEventListener('submit', async function (e) {
                e.preventDefault();
                var res = await postApi('change_password', {
                    current_password: document.getElementById('currPassword').value,
                    new_password: document.getElementById('newPassword').value,
                    confirm_password: document.getElementById('confPassword').value,
                    revoke_other_sessions: document.getElementById('chkRevokeOther').checked ? '1' : '0'
                });
                if (res.success) {
                    Ilmuna.success('Kata Sandi Diperbarui', res.message);
                    formPass.reset();
                    MPROF.load();
                } else {
                    Ilmuna.error('Gagal', res.message || 'Gagal memperbarui kata sandi.');
                }
            });
        }
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
