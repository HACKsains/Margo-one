<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · PROFIL & KEAMANAN AKUN TERPUSAT (/profile)
 * ==============================================================================
 * Fitur:
 *   1. Foto Profil & Sinkronisasi Lintas Tabel (users, guru, siswa — 1:1 learn/profile.php)
 *   2. Ubah Kata Sandi Terpusat (verifikasi password lama + opsi cabut sesi lain)
 *   3. Tautkan / Putuskan Akun Google SSO 1-Klik (Google Identity Services + Verifikasi
 *      Gmail — sistem & UI 1:1 identik dengan C:\laragon\www\learn\profile.php)
 *   4. Kelola & Cabut Sesi Perangkat Aktif Global (sso_sessions)
 *
 * Standar UI: Data-First Zero Title, Segmented Tabs (.curriculum-main-tabs),
 *             Iconify Solar Icons, window.Ilmuna, Zero Page Reload, Mobile 360px+.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once BASE_PATH . '/includes/email-verify-google-template.php';

requireAuth();

$pdo         = getDBConnection();
ilmuna_ensure_google_verification_table($pdo);

$currentUser = getCurrentUser();
$userId      = (int)$currentUser['id'];
$currentSid  = (int)($currentUser['sso_session_id'] ?? 0);

if (empty($_SESSION['csrf_profile_token'])) {
    $_SESSION['csrf_profile_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_profile_token'];

/**
 * Helper ambil daftar kolom tabel secara aman
 */
function margo_prof_table_columns(PDO $pdo, $tableName)
{
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        $cache[$tableName] = $cols;
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Bangun payload bootstrap profil pengguna (dipakai baik saat render halaman awal maupun AJAX refresh)
 */
function margo_profile_build_bootstrap_data(PDO $pdo, $userId, $currentSid)
{
    $identity = margo_resolve_user_identity($pdo, $userId, getActiveRole());
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rawPhoto = !empty($uRow['foto_profil'])
        ? (string)$uRow['foto_profil']
        : (!empty($uRow['avatar'])
            ? (string)$uRow['avatar']
            : (!empty($identity['picture']) ? (string)$identity['picture'] : ''));
    $photoUrl = margo_resolve_avatar_url($rawPhoto);
    if ($photoUrl) {
        $_SESSION['user_avatar'] = $photoUrl;
    }

    $pendingEmailGoogle = '';
    if (empty($uRow['email_google'])) {
        try {
            $pStmt = $pdo->prepare("SELECT email_google FROM google_link_verifications WHERE user_id = ? AND verified_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $pStmt->execute([$userId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow && !empty($pRow['email_google'])) {
                $pendingEmailGoogle = (string)$pRow['email_google'];
            }
        } catch (Throwable $e) {
            $pendingEmailGoogle = '';
        }
    }

    $sStmt = $pdo->prepare("
        SELECT id, active_role, ip_address, user_agent, login_method, expires_at, last_activity_at, created_at
        FROM sso_sessions
        WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
        ORDER BY last_activity_at DESC
    ");
    $sStmt->execute([$userId]);
    $sessions = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$s) {
        $s['is_current'] = ((int)$s['id'] === (int)$currentSid);
    }
    unset($s);

    return [
        'user' => [
            'id'                   => (int)($uRow['id'] ?? $userId),
            'username'             => (string)($uRow['username'] ?? ''),
            'full_name'            => (string)($identity['full_name'] ?? $uRow['full_name'] ?? ''),
            'email'                => (string)($uRow['email'] ?? ''),
            'email_google'         => !empty($uRow['email_google']) ? (string)$uRow['email_google'] : '',
            'pending_email_google' => $pendingEmailGoogle,
            'photo_url'            => $photoUrl ?: '',
            'nisn_nip'             => (string)($uRow['nisn_nip'] ?? ''),
            'roles'                => $identity['roles'] ?? [],
            'primary_role'         => $identity['primary_role'] ?? null,
            'guru_id'              => $identity['guru_id'] ?? null,
            'siswa_id'             => $identity['siswa_id'] ?? null,
            'nama_kelas'           => $identity['nama_kelas'] ?? null,
            'last_login'           => $uRow['last_login'] ?? null,
        ],
        'sessions' => $sessions,
    ];
}

/* ==============================================================================
 * AJAX JSON API (?api=1) — ZERO PAGE RELOAD
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? $_POST['action'] ?? $_GET['action'] ?? ''));

    try {
        if ($act === 'bootstrap') {
            echo json_encode([
                'success' => true,
                'data'    => margo_profile_build_bootstrap_data($pdo, $userId, $currentSid),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Lazy Check Status Penautan Google (dipanggil saat tab fokus / watcher 5 detik)
        if ($act === 'check_google_link_status') {
            $userStmt = $pdo->prepare("SELECT email_google, google_id FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $uRow = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($uRow['email_google'])) {
                $_SESSION['user_email_google'] = (string)$uRow['email_google'];
                echo json_encode([
                    'success'      => true,
                    'connected'    => true,
                    'email_google' => (string)$uRow['email_google'],
                ]);
                exit;
            }

            $pStmt = $pdo->prepare("SELECT email_google, expires_at FROM google_link_verifications WHERE user_id = ? AND verified_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $pStmt->execute([$userId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                echo json_encode([
                    'success'      => true,
                    'connected'    => false,
                    'pending'      => true,
                    'email_google' => (string)$pRow['email_google'],
                ]);
                exit;
            }

            echo json_encode([
                'success'   => true,
                'connected' => false,
                'pending'   => false,
            ]);
            exit;
        }

        // Validasi CSRF untuk seluruh mutasi POST
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
            exit;
        }

        // =====================================================================
        // 1. UPLOAD FOTO PROFIL (Sync ke users, siswa, dan guru — 1:1 learn)
        // =====================================================================
        if ($act === 'upload_photo') {
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada file foto yang diunggah atau terjadi kesalahan server.']);
                exit;
            }

            $file    = $_FILES['photo'];
            $maxSize = 3 * 1024 * 1024; // 3MB
            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'Ukuran foto melebihi batas maksimal 3MB.']);
                exit;
            }

            $mime = '';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = (string)$finfo->file($file['tmp_name']);
            } elseif (function_exists('mime_content_type')) {
                $mime = (string)@mime_content_type($file['tmp_name']);
            }
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime, $allowedMimes, true)) {
                echo json_encode(['success' => false, 'message' => 'Format file tidak didukung. Harap unggah format JPG, PNG, atau WebP.']);
                exit;
            }

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            $ext = isset($extMap[$mime]) ? $extMap[$mime] : 'jpg';

            $targetDir = BASE_PATH . '/uploads/profiles';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            $fileName = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $targetDir . '/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file yang diunggah.']);
                exit;
            }

            // Salin otomatis ke folder learn/uploads/profiles jika berada di server yang sama
            $siblingLearnDirs = [
                dirname(BASE_PATH) . '/learn/uploads/profiles',
                dirname(BASE_PATH) . '/learn.ilmuna.my.id/uploads/profiles',
            ];
            foreach ($siblingLearnDirs as $sDir) {
                if (is_dir(dirname($sDir))) {
                    if (!is_dir($sDir)) {
                        @mkdir($sDir, 0755, true);
                    }
                    @copy($destPath, $sDir . '/' . $fileName);
                }
            }

            $photoRelativeUrl = 'uploads/profiles/' . $fileName;
            $photoFullUrl     = rtrim(BASE_URL, '/') . '/' . $photoRelativeUrl;

            // 1. Update tabel users
            $userCols        = margo_prof_table_columns($pdo, 'users');
            $userPhotoSets   = [];
            $userPhotoParams = [];
            if (in_array('foto_profil', $userCols, true)) {
                $userPhotoSets[]   = "`foto_profil` = ?";
                $userPhotoParams[] = $photoRelativeUrl;
            }
            if (in_array('avatar', $userCols, true)) {
                $userPhotoSets[]   = "`avatar` = ?";
                $userPhotoParams[] = $photoRelativeUrl;
            }
            if (!empty($userPhotoSets)) {
                $userPhotoParams[] = $userId;
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $userPhotoSets) . " WHERE `id` = ?")->execute($userPhotoParams);
            }

            $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $currentUser;

            // 2. Sinkronkan ke tabel siswa jika ada record terkait
            $syncedTo  = ['users'];
            $siswaCols = margo_prof_table_columns($pdo, 'siswa');
            if (in_array('foto', $siswaCols, true)) {
                $sInfo = margo_resolve_logged_siswa($pdo, $uRow);
                if (!empty($sInfo['siswa_id'])) {
                    $pdo->prepare("UPDATE `siswa` SET `foto` = ? WHERE `id` = ?")->execute([$photoRelativeUrl, (int)$sInfo['siswa_id']]);
                    $syncedTo[] = 'siswa';
                }
            }

            // 3. Sinkronkan ke tabel guru jika ada record terkait
            $guruCols = margo_prof_table_columns($pdo, 'guru');
            if (in_array('foto', $guruCols, true)) {
                $gInfo = margo_resolve_logged_guru($pdo, $uRow);
                if (!empty($gInfo['id_guru'])) {
                    $pdo->prepare("UPDATE `guru` SET `foto` = ? WHERE `id` = ?")->execute([$photoRelativeUrl, (int)$gInfo['id_guru']]);
                    $syncedTo[] = 'guru';
                }
            }

            $_SESSION['user_avatar'] = $photoFullUrl;

            margo_sso_log_audit($pdo, 'profile_photo_updated', [
                'user_id'     => $userId,
                'http_status' => 200,
                'detail'      => ['photo' => $photoRelativeUrl, 'synced_to' => $syncedTo],
            ]);

            echo json_encode([
                'success'   => true,
                'message'   => 'Foto profil berhasil diperbarui dan disinkronkan ke ' . implode(' & ', $syncedTo) . '!',
                'photo_url' => $photoFullUrl,
                'synced_to' => $syncedTo,
            ]);
            exit;
        }

        // =====================================================================
        // 2. HAPUS FOTO PROFIL (Sync hapus di users, siswa, dan guru)
        // =====================================================================
        if ($act === 'delete_photo') {
            $userCols      = margo_prof_table_columns($pdo, 'users');
            $userPhotoSets = [];
            if (in_array('foto_profil', $userCols, true)) {
                $userPhotoSets[] = "`foto_profil` = NULL";
            }
            if (in_array('avatar', $userCols, true)) {
                $userPhotoSets[] = "`avatar` = NULL";
            }
            if (!empty($userPhotoSets)) {
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $userPhotoSets) . " WHERE `id` = ?")->execute([$userId]);
            }

            $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $currentUser;

            $siswaCols = margo_prof_table_columns($pdo, 'siswa');
            if (in_array('foto', $siswaCols, true)) {
                $sInfo = margo_resolve_logged_siswa($pdo, $uRow);
                if (!empty($sInfo['siswa_id'])) {
                    $pdo->prepare("UPDATE `siswa` SET `foto` = NULL WHERE `id` = ?")->execute([(int)$sInfo['siswa_id']]);
                }
            }

            $guruCols = margo_prof_table_columns($pdo, 'guru');
            if (in_array('foto', $guruCols, true)) {
                $gInfo = margo_resolve_logged_guru($pdo, $uRow);
                if (!empty($gInfo['id_guru'])) {
                    $pdo->prepare("UPDATE `guru` SET `foto` = NULL WHERE `id` = ?")->execute([(int)$gInfo['id_guru']]);
                }
            }

            $_SESSION['user_avatar'] = null;

            margo_sso_log_audit($pdo, 'profile_photo_deleted', [
                'user_id'     => $userId,
                'http_status' => 200,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Foto profil berhasil dihapus dari seluruh tabel sistem.',
            ]);
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

        // =====================================================================
        // PERMINTAAN TAUTKAN AKUN GOOGLE 1-KLIK (IDENTIK LEARN/API/PROFILE-ACTION.PHP)
        // =====================================================================
        if ($act === 'link_google' || $act === 'request_link_google') {
            ilmuna_ensure_google_verification_table($pdo);

            $accessToken = trim((string)($_POST['google_access_token'] ?? ''));
            $emailGoogle = '';
            $googleId    = '';

            if ($accessToken !== '') {
                $uInfo = fetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($accessToken));
                if (!empty($uInfo['email'])) {
                    $emailGoogle = strtolower(trim((string)$uInfo['email']));
                    $googleId    = trim((string)($uInfo['sub'] ?? ''));
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Gagal mengambil informasi profil dari Google. Pastikan izin akun Google diberikan.',
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Token otentikasi Google tidak ditemukan. Silakan klik tombol Hubungkan kembali.',
                ]);
                exit;
            }

            if ($emailGoogle === '' || !filter_var($emailGoogle, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Alamat Gmail Google tidak valid.']);
                exit;
            }

            if ($googleId === '') {
                $googleId = 'goog_' . md5($emailGoogle . '_ilmuna_sso');
            }

            // Cek apakah akun Google sudah ditautkan ke pengguna lain
            $chk = $pdo->prepare("SELECT id, username FROM `users` WHERE (`google_id` = ? OR LOWER(`email_google`) = ?) AND `id` != ? LIMIT 1");
            $chk->execute([$googleId, $emailGoogle, $userId]);
            $exists = $chk->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Akun Google tersebut (' . $emailGoogle . ') sudah tertaut pada pengguna lain (@' . $exists['username'] . ').',
                ]);
                exit;
            }

            // Cek apakah akun Google ini sudah ditautkan ke pengguna ini sendiri
            $chkSelf = $pdo->prepare("SELECT id FROM `users` WHERE `id` = ? AND (LOWER(`email_google`) = ? OR `google_id` = ?) LIMIT 1");
            $chkSelf->execute([$userId, $emailGoogle, $googleId]);
            if ($chkSelf->fetch()) {
                echo json_encode([
                    'success'      => true,
                    'linked'       => true,
                    'email_google' => $emailGoogle,
                    'message'      => 'Akun Google ini (' . $emailGoogle . ') sudah berhasil tertaut dengan akun Anda.',
                ]);
                exit;
            }

            // Hapus permintaan verifikasi lama yang belum selesai untuk user ini
            $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ? AND `verified_at` IS NULL")->execute([$userId]);

            // Buat token verifikasi kriptografis baru
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // Berlaku 60 menit
            $createdAt = date('Y-m-d H:i:s');
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

            $ins = $pdo->prepare("INSERT INTO `google_link_verifications` (`user_id`, `email_google`, `google_id`, `token`, `expires_at`, `created_at`, `ip_address`) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$userId, $emailGoogle, $googleId, $token, $expiresAt, $createdAt, $ipAddress]);

            $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost';
            $verifyUrl = $baseUrl . '/verify-google.php?token=' . $token;

            $userName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'Pengguna');
            $mailRes  = ilmuna_send_google_verify_email($emailGoogle, $userName, $verifyUrl, 60);

            if (empty($mailRes['success'])) {
                echo json_encode([
                    'success'      => true,
                    'pending'      => true,
                    'email_google' => $emailGoogle,
                    'verify_url'   => $verifyUrl,
                    'mail_failed'  => true,
                    'message'      => 'Tautan verifikasi penautan Google berhasil dibuat! Karena proteksi SMTP membatasi pengiriman dari IP hosting, silakan klik tombol "Buka Link Verifikasi" di bawah ini untuk memverifikasi secara langsung.',
                    'action_url'   => $verifyUrl,
                ]);
                exit;
            }

            echo json_encode([
                'success'      => true,
                'pending'      => true,
                'email_google' => $emailGoogle,
                'message'      => 'Tautan verifikasi telah dikirim ke ' . $emailGoogle . '. Silakan periksa kotak masuk atau spam di Gmail Anda dan klik tautan untuk menyelesaikan penautan.',
            ]);
            exit;
        }

        // Batalkan permintaan penautan Google yang masih pending
        if ($act === 'cancel_link_google') {
            ilmuna_ensure_google_verification_table($pdo);
            $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ? AND `verified_at` IS NULL")->execute([$userId]);

            echo json_encode([
                'success' => true,
                'message' => 'Permintaan penautan akun Google berhasil dibatalkan.',
            ]);
            exit;
        }

        if ($act === 'unlink_google') {
            $pdo->prepare("UPDATE users SET email_google = NULL, google_id = NULL WHERE id = ?")->execute([$userId]);
            try {
                $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ?")->execute([$userId]);
            } catch (Throwable $e) {
            }
            $_SESSION['user_email_google'] = null;

            margo_sso_log_audit($pdo, 'google_unlinked', [
                'user_id'     => $userId,
                'http_status' => 200,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Tautan akun Google berhasil diputuskan.',
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

// Ambil foto profil & inisial awal untuk render server-side tanpa flicker
$initIdentity = margo_resolve_user_identity($pdo, $userId, getActiveRole());
$initFullName = (string)($initIdentity['full_name'] ?? $currentUser['full_name'] ?? $currentUser['username'] ?? 'Pengguna');
$initPhotoUrl = !empty($initIdentity['picture']) ? (string)$initIdentity['picture'] : (string)($currentUser['avatar'] ?? '');
if ($initPhotoUrl !== '') {
    $_SESSION['user_avatar'] = $initPhotoUrl;
}
$initParts   = preg_split('/\s+/', trim($initFullName));
$initInisial = '';
if (!empty($initParts)) {
    $initInisial .= margo_mb_substr($initParts[0], 0, 1);
    if (isset($initParts[1])) {
        $initInisial .= margo_mb_substr($initParts[1], 0, 1);
    }
}
$initInisial = margo_mb_strtoupper($initInisial ?: margo_mb_substr($initFullName, 0, 2));

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
.mprof-btn--danger{background:rgba(29,59,94,0.08);color:#1D3B5E;border-color:rgba(29,59,94,0.24)}
.mprof-btn--danger:hover{background:#1D3B5E;color:#fff}
.mprof-btn--ghost{background:#F8FAFC;color:#1D3B5E;border-color:#CBD5E1}
.mprof-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700}
.mprof-badge--ok{background:rgba(13,122,135,0.12);color:#0D7A87}
.mprof-badge--warn{background:rgba(82,178,220,0.16);color:#1D3B5E}
.mprof-badge--teal{background:rgba(13,122,135,0.12);color:#0D7A87}
.mprof-meta-strip{display:flex;flex-wrap:wrap;gap:10px;padding:12px 14px;border-radius:12px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:13px;color:#334155}
.mprof-meta-item{display:inline-flex;align-items:center;gap:6px}

/* ============================================================================
 * FOTO PROFIL & SINKRONISASI — 1:1 PARITAS C:\laragon\www\learn\profile.php
 * ============================================================================ */
.avatar-sync-box{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.avatar-sync-preview-wrap{position:relative;width:104px;height:104px;flex-shrink:0}
.avatar-sync-preview{
    position:relative;width:100px;height:100px;border-radius:50%;overflow:hidden;
    box-shadow:0 8px 20px rgba(13,122,135,0.14);border:3px solid #FFF;
    outline:2px solid rgba(13,122,135,0.22);background:rgba(13,122,135,0.08);
    cursor:pointer;
}
.sync-photo-container{width:100%;height:100%}
.sync-photo-container img{width:100%;height:100%;object-fit:cover;display:block}
.avatar-fallback-lg{
    width:100%;height:100%;background:linear-gradient(135deg,rgba(29,59,94,0.10),rgba(13,122,135,0.14));
    display:flex;align-items:center;justify-content:center;
    font-family:"Outfit",sans-serif;font-size:32px;font-weight:800;color:#0D7A87;
}
.avatar-camera-badge{
    position:absolute;bottom:2px;right:2px;width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,#0D7A87,#1D3B5E);color:#FFF;border:2.5px solid #FFF;
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    box-shadow:0 4px 10px rgba(29,59,94,0.22);transition:transform .18s ease;z-index:5;
}
.avatar-camera-badge:hover{transform:scale(1.08)}
.photo-loading-spinner{
    position:absolute;inset:0;background:rgba(29,59,94,0.55);backdrop-filter:blur(2px);
    display:none;align-items:center;justify-content:center;z-index:4;
}
.photo-loading-spinner.active{display:flex}
.spinner-ring{
    width:28px;height:28px;border:3px solid rgba(255,255,255,0.3);
    border-top-color:#FFF;border-radius:50%;animation:spin .8s linear infinite;
}
.avatar-sync-actions{flex:1;min-width:220px}
.sync-info-badge{
    display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:8px;
    background:rgba(13,122,135,0.12);color:#0D7A87;font-size:12.5px;font-weight:700;margin-bottom:12px;
}
.action-buttons-group{display:flex;gap:10px;margin-bottom:8px;flex-wrap:wrap;align-items:center}
.upload-hint{font-size:12.5px;color:#64748B;margin:0}

/* ============================================================================
 * GOOGLE SSO CARD BOXES — 1:1 PARITAS DENGAN C:\laragon\www\learn\profile.php
 * ============================================================================ */
.google-status-box{
    display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px;
    border-radius:16px;border:1px solid #E2E8F0;background:#F8FAFC;flex-wrap:wrap;
}
.google-status-box.status-connected{background:rgba(13,122,135,0.08);border-color:rgba(13,122,135,0.30)}
.google-status-box.status-pending{background:rgba(82,178,220,0.10);border-color:rgba(82,178,220,0.45)}
.google-status-info{display:flex;align-items:center;gap:16px}
.google-logo-badge{
    width:50px;height:50px;border-radius:14px;background:#FFF;border:1px solid #E2E8F0;
    display:flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(0,0,0,0.03);flex-shrink:0;
}
.google-status-pill{
    display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;
    text-transform:uppercase;color:#64748B;margin-bottom:4px;
}
.status-connected .google-status-pill{color:#0D7A87}
.status-dot{width:8px;height:8px;border-radius:50%;background:#94A3B8}
.status-connected .status-dot{background:#0D7A87}
.status-pill-amber{color:#0D7A87 !important}
.status-dot-amber{background:#52B2DC !important;animation:pulseAmber 1.8s infinite}
@keyframes pulseAmber{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.4;transform:scale(1.25)}}
.google-email-text{font-size:15px;font-weight:700;color:#1D3B5E}
.google-help-text{font-size:12.5px;color:#1D3B5E;margin-top:4px;display:flex;align-items:center;gap:6px}
.google-sub-hint{font-size:12px;color:#64748B;margin-top:2px}
.google-status-action{display:flex;align-items:center;gap:8px}
.flex-action-buttons{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.btn-g-connect{
    appearance:none;border:none;border-radius:12px;padding:10px 18px;min-height:42px;
    font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;
    background:linear-gradient(135deg,#0D7A87 0%,#1D3B5E 100%);color:#FFF;
    box-shadow:0 4px 12px rgba(13,122,135,0.25);transition:all .2s ease;font-family:inherit;
}
.btn-g-connect:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 16px rgba(13,122,135,0.35)}
.btn-g-connect:disabled{opacity:.7;cursor:wait}
.btn-g-outline-primary{
    appearance:none;background:#FFF;border:1.5px solid #CBD5E1;color:#0D7A87;
    border-radius:12px;padding:9px 15px;min-height:40px;font-size:13px;font-weight:700;
    cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;font-family:inherit;
}
.btn-g-outline-primary:hover:not(:disabled){border-color:#0D7A87;background:rgba(13,122,135,0.06)}
.btn-g-outline-secondary{
    appearance:none;background:#FFF;border:1.5px solid #E2E8F0;color:#64748B;
    border-radius:12px;padding:9px 15px;min-height:40px;font-size:13px;font-weight:700;
    cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;font-family:inherit;
}
.btn-g-outline-secondary:hover{background:#F8FAFC;color:#1D3B5E}
.btn-g-outline-danger{
    appearance:none;background:rgba(29,59,94,0.08);border:1px solid rgba(29,59,94,0.24);color:#1D3B5E;
    border-radius:12px;padding:9px 15px;min-height:40px;font-size:13px;font-weight:700;
    cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;font-family:inherit;
}
.btn-g-outline-danger:hover{background:#1D3B5E;color:#FFF}

@keyframes spin{100%{transform:rotate(360deg)}}
.spin-icon{animation:spin 1s linear infinite}
@keyframes rowSmoothIn{
    0%{opacity:0;transform:translateY(-8px) scale(0.98)}
    100%{opacity:1;transform:translateY(0) scale(1)}
}
@keyframes rowSmoothOut{
    0%{opacity:1;transform:scale(1)}
    100%{opacity:0;transform:scale(0.92)}
}
@keyframes rowFlash{
    0%{background-color:rgba(82,178,220,0.22)}
    100%{background-color:transparent}
}
.row-smooth-in{animation:rowSmoothIn .35s cubic-bezier(.16,1,.3,1) forwards}
.row-smooth-out{animation:rowSmoothOut .28s ease forwards;pointer-events:none}
.row-flash{animation:rowFlash 1.4s ease-out forwards}
</style>';

if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    $extraHead .= "\n" . '<script src="https://accounts.google.com/gsi/client" async defer></script>';
}

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

    <!-- TAB 1: FOTO PROFIL, IDENTITAS & KATA SANDI -->
    <section class="mprof-pane is-active" id="mprofPane_security">
        <!-- KARTU 1: FOTO PROFIL & SINKRONISASI (1:1 IDENTIK LEARN/PROFILE.PHP) -->
        <div class="mprof-card card-avatar-sync" id="cardAvatarSync">
            <div class="mprof-card-head">
                <div>
                    <h3 class="mprof-card-title">
                        <iconify-icon icon="solar:gallery-round-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                        Foto Profil &amp; Sinkronisasi
                    </h3>
                    <div style="font-size:12.5px;color:#64748B;margin-top:2px;">Otomatis sinkron ke akun Margo One SSO dan data pokok (Guru / Siswa)</div>
                </div>
                <div class="sync-info-badge" style="margin-bottom:0;">
                    <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                    <span>Tersinkronisasi</span>
                </div>
            </div>

            <div class="avatar-sync-box">
                <div class="avatar-sync-preview-wrap">
                    <div class="avatar-sync-preview" onclick="document.getElementById('profilePhotoInput').click()" title="Klik untuk mengganti foto profil">
                        <div class="sync-photo-container" id="syncPhotoPreview">
                            <?php if ($initPhotoUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($initPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Foto Akun" loading="lazy">
                            <?php else: ?>
                                <div class="avatar-fallback-lg" id="syncAvatarFallback"><?= htmlspecialchars($initInisial, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="photo-loading-spinner" id="photoSpinner">
                            <div class="spinner-ring"></div>
                        </div>
                    </div>
                    <label for="profilePhotoInput" class="avatar-camera-badge" title="Ganti Foto Profil">
                        <iconify-icon icon="solar:camera-minimalistic-bold" width="16"></iconify-icon>
                    </label>
                </div>

                <div class="avatar-sync-actions">
                    <div class="action-buttons-group" id="avatarActionButtons">
                        <label for="profilePhotoInput" class="btn-g-connect" style="cursor:pointer;">
                            <iconify-icon icon="solar:upload-bold" width="16"></iconify-icon>
                            <span>Unggah Baru</span>
                        </label>
                        <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="handlePhotoUpload(this)">

                        <?php if ($initPhotoUrl !== ''): ?>
                            <button type="button" class="btn-g-outline-danger" id="btnDeletePhoto" onclick="confirmDeletePhoto()">
                                <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
                                <span>Hapus</span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <p class="upload-hint">Format JPG/PNG/WebP maks 3MB. Rasio 1:1.</p>
                </div>
            </div>
        </div>

        <!-- KARTU 2: RINGKASAN IDENTITAS -->
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

        <!-- KARTU 3: UBAH KATA SANDI -->
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

    <!-- TAB 2: TAUTAN GOOGLE SSO (1:1 IDENTIK C:\laragon\www\learn\profile.php) -->
    <section class="mprof-pane" id="mprofPane_google">
        <div class="mprof-card card-google-integration" id="cardGoogleIntegration">
            <div class="mprof-card-head">
                <div>
                    <h3 class="mprof-card-title">
                        <iconify-icon icon="solar:link-circle-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                        Tautan Akun Google
                    </h3>
                    <div style="font-size:12.5px;color:#64748B;margin-top:2px;">Masuk satu klik dengan Google SSO</div>
                </div>
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
    var ENDPOINT         = <?= json_encode(BASE_URL . '/profile.php?api=1') ?>;
    var CSRF             = <?= json_encode($csrfToken) ?>;
    var GOOGLE_CLIENT_ID = <?= json_encode(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '') ?>;
    var CURRENT_FULLNAME = <?= json_encode($initFullName) ?>;
    var INITIAL_PROFILE  = <?= json_encode(margo_profile_build_bootstrap_data($pdo, $userId, $currentSid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var GOOGLE_SVG_24    = '<svg width="24" height="24" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    var GOOGLE_SVG_16    = '<svg width="16" height="16" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    var googlePendingInterval = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function computeInitials(fullName) {
        if (!fullName) return 'U';
        var parts = String(fullName).trim().split(/\s+/);
        var inisial = parts[0] ? parts[0].substring(0, 1) : '';
        if (parts[1]) {
            inisial += parts[1].substring(0, 1);
        }
        return (inisial || String(fullName).substring(0, 2)).toUpperCase();
    }

    function notifyToast(type, title, message) {
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            window.Ilmuna.toast(type, title, message);
        } else if (window.Ilmuna && typeof window.Ilmuna[type] === 'function') {
            window.Ilmuna[type](title, message);
        }
    }

    async function postApi(action, data) {
        var fd = new FormData();
        fd.append('api_action', action);
        fd.append('csrf_token', CSRF);
        if (data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        var r = await fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        return await r.json();
    }

    // -------------------------------------------------------------------------
    // FOTO PROFIL UPLOAD & DELETE (IN-PLACE MUTATION & ZERO PAGE RELOAD)
    // -------------------------------------------------------------------------
    function syncShellAvatars(photoUrl, fullName) {
        var inisial = computeInitials(fullName || CURRENT_FULLNAME);

        // 1. Update Preview di Kartu Foto Profil
        var syncPreview = document.getElementById('syncPhotoPreview');
        if (syncPreview) {
            if (photoUrl) {
                syncPreview.innerHTML = '<img src="' + esc(photoUrl) + '" alt="Foto Akun" loading="lazy" class="row-smooth-in">';
            } else {
                syncPreview.innerHTML = '<div class="avatar-fallback-lg row-smooth-in" id="syncAvatarFallback">' + esc(inisial) + '</div>';
            }
        }

        // 2. Update Topbar Mini Avatar (.margo-mini-avatar)
        var topMini = document.querySelector('.margo-mini-avatar');
        if (topMini) {
            if (photoUrl) {
                topMini.innerHTML = '<img src="' + esc(photoUrl) + '" alt="Avatar">';
            } else {
                topMini.textContent = inisial;
            }
        }

        // 3. Update Right Sidebar Avatar (.margo-profile-avatar)
        var rightAv = document.querySelector('.margo-profile-avatar');
        if (rightAv) {
            if (photoUrl) {
                rightAv.innerHTML = '<img src="' + esc(photoUrl) + '" alt="Avatar" loading="lazy"><span class="margo-profile-online" title="Sesi SSO Aktif"></span>';
            } else {
                rightAv.innerHTML = esc(inisial) + '<span class="margo-profile-online" title="Sesi SSO Aktif"></span>';
            }
        }

        // 4. Tampilkan / sembunyikan tombol Hapus Foto
        var actionGroup = document.getElementById('avatarActionButtons');
        var existingDel = document.getElementById('btnDeletePhoto');
        if (photoUrl) {
            if (actionGroup && !existingDel) {
                var btnDel = document.createElement('button');
                btnDel.type = 'button';
                btnDel.id = 'btnDeletePhoto';
                btnDel.className = 'btn-g-outline-danger row-smooth-in';
                btnDel.onclick = window.confirmDeletePhoto;
                btnDel.innerHTML = '<iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon> <span>Hapus</span>';
                actionGroup.appendChild(btnDel);
            }
        } else if (existingDel) {
            existingDel.classList.add('row-smooth-out');
            setTimeout(function () {
                if (existingDel.parentNode) existingDel.parentNode.removeChild(existingDel);
            }, 260);
        }
    }

    window.handlePhotoUpload = async function (input) {
        if (!input || !input.files || !input.files[0]) return;
        var file = input.files[0];

        if (file.size > 3 * 1024 * 1024) {
            notifyToast('error', 'Ukuran Terlalu Besar', 'Maksimal ukuran foto adalah 3MB.');
            input.value = '';
            return;
        }

        var spinner = document.getElementById('photoSpinner');
        if (spinner) spinner.classList.add('active');

        var fd = new FormData();
        fd.append('api_action', 'upload_photo');
        fd.append('csrf_token', CSRF);
        fd.append('photo', file);

        try {
            var response = await fetch(ENDPOINT, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var result = await response.json();

            if (result.success) {
                syncShellAvatars(result.photo_url, CURRENT_FULLNAME);
                var cardAvatar = document.getElementById('cardAvatarSync');
                if (cardAvatar) {
                    cardAvatar.classList.remove('row-flash');
                    void cardAvatar.offsetWidth;
                    cardAvatar.classList.add('row-flash');
                }
                notifyToast('success', 'Berhasil Disimpan', result.message || 'Foto profil berhasil disinkronkan!');
            } else {
                notifyToast('error', 'Gagal Mengunggah', result.message || 'Gagal mengunggah foto profil.');
            }
        } catch (err) {
            notifyToast('error', 'Kesalahan Jaringan', 'Terjadi kesalahan jaringan saat mengunggah foto.');
        } finally {
            if (spinner) spinner.classList.remove('active');
            input.value = '';
        }
    };

    window.confirmDeletePhoto = async function () {
        var yakin = false;
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            yakin = await window.Ilmuna.confirm(
                'error',
                'Hapus Foto Profil?',
                'Foto profil Anda akan dihapus permanen dan diganti dengan inisial nama akun Anda.',
                'Ya, Hapus Foto',
                'Batal'
            );
        }
        if (!yakin) return;

        try {
            var result = await postApi('delete_photo', {});
            if (result.success) {
                syncShellAvatars('', CURRENT_FULLNAME);
                var cardAvatar = document.getElementById('cardAvatarSync');
                if (cardAvatar) {
                    cardAvatar.classList.remove('row-flash');
                    void cardAvatar.offsetWidth;
                    cardAvatar.classList.add('row-flash');
                }
                notifyToast('success', 'Berhasil Dihapus', result.message || 'Foto profil berhasil dihapus.');
            } else {
                notifyToast('error', 'Gagal Menghapus', result.message || 'Gagal menghapus foto profil.');
            }
        } catch (err) {
            notifyToast('error', 'Kesalahan Sistem', 'Gagal menghubungi server untuk menghapus foto.');
        }
    };

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

    // -------------------------------------------------------------------------
    // GOOGLE SSO DOM RENDERERS (1:1 IDENTIK C:\laragon\www\learn\profile.php)
    // -------------------------------------------------------------------------
    function renderGoogleCardConnected(emailGoogle) {
        var box = document.getElementById('mprofGoogleBox');
        var cnt = document.getElementById('cntGoogleStatus');
        var cardGoogle = document.getElementById('cardGoogleIntegration');
        if (cnt) cnt.textContent = 'TERTAUT';
        if (!box) return;

        box.innerHTML =
            '<div class="google-status-box status-connected row-smooth-in">'
            + '<div class="google-status-info">'
            + '<div class="google-logo-badge">' + GOOGLE_SVG_24 + '</div>'
            + '<div class="google-details">'
            + '<div class="google-status-pill"><span class="status-dot"></span><span>Terhubung</span></div>'
            + '<div class="google-email-text">' + esc(emailGoogle) + '</div>'
            + '</div>'
            + '</div>'
            + '<div class="google-status-action">'
            + '<button type="button" class="btn-g-outline-danger" onclick="openUnlinkGoogleModal()">'
            + '<iconify-icon icon="solar:link-broken-bold" width="16"></iconify-icon>'
            + '<span>Putuskan</span>'
            + '</button>'
            + '</div>'
            + '</div>';

        if (cardGoogle) {
            cardGoogle.classList.remove('row-flash');
            void cardGoogle.offsetWidth;
            cardGoogle.classList.add('row-flash');
        }
    }

    function renderGoogleCardPending(emailGoogle) {
        var box = document.getElementById('mprofGoogleBox');
        var cnt = document.getElementById('cntGoogleStatus');
        if (cnt) cnt.textContent = 'PENDING';
        if (!box) return;

        box.innerHTML =
            '<div class="google-status-box status-pending row-smooth-in">'
            + '<div class="google-status-info">'
            + '<div class="google-logo-badge">' + GOOGLE_SVG_24 + '</div>'
            + '<div class="google-details">'
            + '<div class="google-status-pill status-pill-amber"><span class="status-dot status-dot-amber"></span><span>Menunggu Verifikasi Gmail</span></div>'
            + '<div class="google-email-text">' + esc(emailGoogle) + '</div>'
            + '<div class="google-help-text"><iconify-icon icon="solar:letter-unread-bold" width="14"></iconify-icon><span>Tautan verifikasi telah dikirim ke Gmail. Klik tautan untuk mengaktifkan.</span></div>'
            + '</div>'
            + '</div>'
            + '<div class="google-status-action flex-action-buttons">'
            + '<button type="button" class="btn-g-outline-primary" onclick="startGoogleLink()" id="btnResendGoogleLink" title="Tautkan Ulang / Ganti Akun Google">'
            + '<iconify-icon icon="solar:restart-bold" width="15"></iconify-icon>'
            + '<span>Kirim Ulang</span>'
            + '</button>'
            + '<button type="button" class="btn-g-outline-secondary" onclick="cancelPendingGoogleLink()" title="Batalkan Permintaan">'
            + '<span>Batal</span>'
            + '</button>'
            + '</div>'
            + '</div>';
    }

    function renderGoogleCardDisconnected() {
        var box = document.getElementById('mprofGoogleBox');
        var cnt = document.getElementById('cntGoogleStatus');
        if (cnt) cnt.textContent = 'BELUM';
        if (!box) return;

        box.innerHTML =
            '<div class="google-status-box status-disconnected row-smooth-in">'
            + '<div class="google-status-info">'
            + '<div class="google-logo-badge">' + GOOGLE_SVG_24 + '</div>'
            + '<div class="google-details">'
            + '<div class="google-status-pill"><span class="status-dot"></span><span>Belum Ditautkan</span></div>'
            + '<div class="google-email-text">Tautkan Akun Google</div>'
            + '<div class="google-sub-hint">Klik tombol untuk menghubungkan akun Google Anda secara instan.</div>'
            + '</div>'
            + '</div>'
            + '<div class="google-status-action">'
            + '<button type="button" class="btn-g-connect" id="btnConnectGoogle" onclick="startGoogleLink()">'
            + GOOGLE_SVG_16
            + '<span>Hubungkan</span>'
            + '</button>'
            + '</div>'
            + '</div>';
    }

    function renderGoogle(u) {
        if (u.email_google) {
            stopGooglePendingWatcher();
            renderGoogleCardConnected(u.email_google);
        } else if (u.pending_email_google) {
            renderGoogleCardPending(u.pending_email_google);
            startGooglePendingWatcher();
        } else {
            stopGooglePendingWatcher();
            renderGoogleCardDisconnected();
        }
    }

    // -------------------------------------------------------------------------
    // GOOGLE SSO (ONE-CLICK GIS + GMAIL VERIFICATION — IDENTIK LEARN/PROFILE.PHP)
    // -------------------------------------------------------------------------
    window.startGoogleLink = function () {
        if (!GOOGLE_CLIENT_ID) {
            if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                window.Ilmuna.alert('error', 'Konfigurasi Belum Lengkap', 'Google Client ID belum diatur pada config.php sistem.');
            } else {
                notifyToast('error', 'Konfigurasi Belum Lengkap', 'Google Client ID belum diatur.');
            }
            return;
        }

        if (window.google && window.google.accounts && window.google.accounts.oauth2) {
            var btn = document.getElementById('btnConnectGoogle') || document.getElementById('btnResendGoogleLink');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin-icon" width="16"></iconify-icon> <span>Menghubungkan...</span>';
            }

            var tokenClient = window.google.accounts.oauth2.initTokenClient({
                client_id: GOOGLE_CLIENT_ID,
                scope: 'openid email profile',
                prompt: 'select_account',
                callback: async function (tokenResponse) {
                    if (tokenResponse && tokenResponse.access_token) {
                        await sendGoogleLinkRequest(tokenResponse.access_token, btn, origHtml);
                    } else if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                },
                error_callback: function () {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                }
            });
            tokenClient.requestAccessToken();
        } else {
            notifyToast('info', 'Memuat Google SSO', 'Sedang memuat pustaka Google. Silakan coba beberapa saat lagi.');
        }
    };

    async function sendGoogleLinkRequest(accessToken, btn, origHtml) {
        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin-icon" width="16"></iconify-icon> <span>Menghubungkan...</span>';
            }

            var result = await postApi('link_google', { google_access_token: accessToken });

            if (result.success) {
                if (result.pending) {
                    if (result.email_google) {
                        renderGoogleCardPending(result.email_google);
                    }
                    startGooglePendingWatcher();

                    if (result.mail_failed && result.action_url) {
                        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
                            var openNow = await window.Ilmuna.confirm(
                                'info',
                                'Verifikasi Email Diperlukan',
                                result.message,
                                'Buka Link Verifikasi',
                                'Tutup'
                            );
                            if (openNow) {
                                window.open(result.action_url, '_blank');
                            }
                        } else {
                            notifyToast('info', 'Verifikasi Email Diperlukan', result.message);
                        }
                    } else if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                        window.Ilmuna.alert('info', 'Tautan Verifikasi Dikirim', result.message || 'Silakan periksa kotak masuk atau spam di Gmail Anda dan klik tautan untuk menyelesaikan penautan.');
                    } else {
                        notifyToast('info', 'Verifikasi Dikirim', result.message);
                    }
                } else {
                    renderGoogleCardConnected(result.email_google);
                    notifyToast('success', 'Berhasil Ditautkan', result.message || 'Akun Google berhasil ditautkan ke akun Anda!');
                }
            } else {
                if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                    window.Ilmuna.alert('error', 'Gagal Menautkan', result.message || 'Gagal menautkan akun Google.');
                } else {
                    notifyToast('error', 'Gagal', result.message || 'Gagal menautkan akun Google.');
                }
            }
        } catch (err) {
            notifyToast('error', 'Kesalahan Sistem', 'Gagal memproses penautan akun Google.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    }

    window.cancelPendingGoogleLink = async function () {
        var yakin = false;
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            yakin = await window.Ilmuna.confirm(
                'warning',
                'Batalkan Verifikasi Google?',
                'Permintaan penautan akun Google yang belum diverifikasi akan dibatalkan.',
                'Ya, Batalkan',
                'Kembali'
            );
        }
        if (!yakin) return;

        try {
            var result = await postApi('cancel_link_google', {});
            if (result.success) {
                stopGooglePendingWatcher();
                renderGoogleCardDisconnected();
                notifyToast('success', 'Dibatalkan', result.message);
            } else {
                notifyToast('error', 'Gagal', result.message || 'Gagal membatalkan permintaan.');
            }
        } catch (err) {
            notifyToast('error', 'Kesalahan', 'Gagal membatalkan permintaan.');
        }
    };

    window.openUnlinkGoogleModal = async function () {
        var yakin = false;
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            yakin = await window.Ilmuna.confirm(
                'error',
                'Putuskan Tautan Google?',
                'Anda tidak akan dapat masuk menggunakan Google SSO dengan email ini sampai ditautkan kembali.',
                'Ya, Putuskan',
                'Batal'
            );
        }
        if (!yakin) return;

        try {
            var result = await postApi('unlink_google', {});
            if (result.success) {
                stopGooglePendingWatcher();
                renderGoogleCardDisconnected();
                notifyToast('success', 'Berhasil Diputuskan', result.message);
            } else {
                notifyToast('error', 'Gagal', result.message || 'Gagal memutuskan tautan.');
            }
        } catch (err) {
            notifyToast('error', 'Kesalahan Sistem', 'Gagal memutuskan tautan Google.');
        }
    };

    // -------------------------------------------------------------------------
    // LAZY STATUS WATCHER (POLL & ON-FOCUS CHECK UNTUK ZERO PAGE RELOAD)
    // -------------------------------------------------------------------------
    async function checkGoogleLinkStatusLazily(notifyOnSuccess) {
        var cardGoogle = document.getElementById('cardGoogleIntegration');
        if (!cardGoogle) return;

        try {
            var res = await fetch(ENDPOINT + '&api_action=check_google_link_status', { credentials: 'same-origin' });
            var data = await res.json();
            if (data && data.success) {
                if (data.connected) {
                    stopGooglePendingWatcher();
                    renderGoogleCardConnected(data.email_google);
                    if (notifyOnSuccess) {
                        if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                            window.Ilmuna.alert('success', 'Akun Google Terhubung', 'Selamat! Verifikasi Gmail berhasil diselesaikan. Akun Google Anda sekarang aktif untuk masuk instan.');
                        } else {
                            notifyToast('success', 'Akun Google Terhubung', 'Verifikasi Gmail berhasil diselesaikan!');
                        }
                    }
                } else if (!data.pending && cardGoogle.querySelector('.status-pending')) {
                    stopGooglePendingWatcher();
                    renderGoogleCardDisconnected();
                }
            }
        } catch (e) {
            // Silent fail on background poll
        }
    }

    function startGooglePendingWatcher() {
        stopGooglePendingWatcher();
        googlePendingInterval = setInterval(function () {
            checkGoogleLinkStatusLazily(true);
        }, 5000);
    }

    function stopGooglePendingWatcher() {
        if (googlePendingInterval) {
            clearInterval(googlePendingInterval);
            googlePendingInterval = null;
        }
    }

    window.addEventListener('focus', function () {
        var cardGoogle = document.getElementById('cardGoogleIntegration');
        if (cardGoogle && cardGoogle.querySelector('.status-pending')) {
            checkGoogleLinkStatusLazily(true);
        }
    });

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

    function applyProfileData(d) {
        if (!d || !d.user) return;
        var u = d.user;
        if (u.full_name) {
            CURRENT_FULLNAME = u.full_name;
        }
        syncShellAvatars(u.photo_url || '', CURRENT_FULLNAME);
        var rolesWrap = document.getElementById('mprofRolesWrap');
        if (rolesWrap) {
            rolesWrap.innerHTML = (u.roles || []).map(function (rl) {
                return '<span class="mprof-badge mprof-badge--teal">' + esc(String(rl).toUpperCase()) + '</span>';
            }).join('');
        }
        var metaWrap = document.getElementById('mprofIdentityMeta');
        if (metaWrap) {
            metaWrap.innerHTML =
                '<span class="mprof-meta-item"><iconify-icon icon="solar:user-bold" style="color:#0D7A87;"></iconify-icon> <strong>' + esc(u.full_name) + '</strong> (@' + esc(u.username) + ')</span>'
                + '<span class="mprof-meta-item"><iconify-icon icon="solar:letter-bold" style="color:#0D7A87;"></iconify-icon> ' + esc(u.email || '-') + '</span>'
                + (u.nama_kelas ? '<span class="mprof-meta-item"><iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;"></iconify-icon> ' + esc(u.nama_kelas) + '</span>' : '')
                + '<span class="mprof-meta-item"><iconify-icon icon="solar:clock-circle-linear" style="color:#64748B;"></iconify-icon> Login terakhir: ' + esc(u.last_login || 'Baru saja') + '</span>';
        }
        renderGoogle(u);
        renderSessions(d.sessions || []);
    }

    window.MPROF = {
        load: async function (useInitial) {
            if (useInitial && INITIAL_PROFILE) {
                applyProfileData(INITIAL_PROFILE);
                return;
            }
            try {
                var r = await fetch(ENDPOINT + '&api_action=bootstrap', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var res = await r.json();
                if (!res || !res.success) return;
                applyProfileData(res.data);
            } catch (e) {
                if (!INITIAL_PROFILE && window.Ilmuna) Ilmuna.error('Kesalahan', 'Gagal memuat data profil.');
            }
        },
        unlinkGoogle: function () {
            window.openUnlinkGoogleModal();
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
        MPROF.load(true);
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
