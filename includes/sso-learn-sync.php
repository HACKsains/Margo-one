<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · ISOLATED LEARN USER SYNC & RANDOM CREDENTIALS ENGINE
 * ==============================================================================
 * Arsitektur Pemisahan Total (Pure Isolated Identity):
 *   - Tabel `users` Margo One SSO (`uofruaju_margo_one.users`) 100% TERPISAH
 *     dari `uofruaju_learn_ilmuna.users` (username, password, foto, email_google,
 *     google_id TIDAK PERNAH digabung atau disalin mentah!).
 *   - Tombol Sinkron di Panel Admin SSO HANYA membaca alamat Gmail & nomor
 *     referensi (ID / NISN / NIS / NIP) dari database Learn secara READ-ONLY.
 *   - Setiap akun baru di SSO otomatis dibuatkan:
 *       * Username acak unik berbasis nomor ID/NISN/NIS/NIP
 *       * Kata sandi acak kuat berbasis nomor ID/NISN/NIS/NIP + random_bytes
 *       * email_google = NULL, google_id = NULL, foto_profil = NULL (terpisah!)
 *   - Kredensial SSO baru tersebut langsung dikirimkan ke alamat Gmail user
 *     menggunakan Google Apps Script Relay v3.0 (EmailRelay.gs).
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/sso-security.php';
require_once BASE_PATH . '/includes/email-verify-google-template.php';

/**
 * Koneksi PDO khusus READ-ONLY ke database sumber Ilmuna Learn (uofruaju_learn_ilmuna).
 * Tidak pernah menulis ke database Learn.
 */
function margo_learn_sync_connect_readonly()
{
    static $learnPdo = null;
    if ($learnPdo instanceof PDO) {
        return $learnPdo;
    }

    $learnDb = defined('LEARN_DB_NAME') ? preg_replace('/[^a-zA-Z0-9_]/', '', (string)LEARN_DB_NAME) : 'uofruaju_learn_ilmuna';
    if ($learnDb === '') {
        throw new RuntimeException('Nama database Ilmuna Learn (LEARN_DB_NAME) belum dikonfigurasi.');
    }

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $learnDb . ';charset=' . DB_CHARSET;

    $attempts = [];
    if (defined('LEARN_DB_USER') && LEARN_DB_USER !== '') {
        $attempts[] = [LEARN_DB_USER, defined('LEARN_DB_PASS') ? LEARN_DB_PASS : DB_PASS];
    }
    if (defined('DB_USER') && DB_USER !== '') {
        $attempts[] = [DB_USER, DB_PASS];
    }
    $hostNow = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = (php_sapi_name() === 'cli')
        || strpos($hostNow, 'localhost') === 0
        || strpos($hostNow, '127.0.0.1') === 0
        || substr($hostNow, -5) === '.test';
    if ($isLocal) {
        $attempts[] = ['root', ''];
    }

    $errors = [];
    foreach ($attempts as $cred) {
        try {
            $pdo = new PDO($dsn, (string)$cred[0], (string)$cred[1], $opts);
            $pdo->exec("SET time_zone = '+07:00'");
            $learnPdo = $pdo;
            return $learnPdo;
        } catch (PDOException $e) {
            $errors[] = $cred[0] . ': ' . $e->getMessage();
        }
    }

    throw new RuntimeException('Tidak dapat membaca database Ilmuna Learn (' . $learnDb . '): ' . implode(' | ', $errors));
}

/**
 * Ekstrak alamat Gmail / email valid prioritas tinggi dari data Learn (HANYA mengambil email, bukan password/username/google_id).
 */
function margo_learn_sync_pick_gmail(array $candidates)
{
    $validOther = '';
    foreach ($candidates as $raw) {
        $em = strtolower(trim((string)$raw));
        if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        // Prioritaskan @gmail.com / @googlemail.com terlebih dahulu
        if (preg_match('/@(gmail\.com|googlemail\.com)$/i', $em)) {
            return $em;
        }
        if ($validOther === '') {
            $validOther = $em;
        }
    }
    return $validOther;
}

/**
 * Ambil daftar kandidat user dari database Learn (READ-ONLY — hanya Gmail, nama, role, dan nomor ID/NISN/NIS/NIP).
 */
function margo_learn_sync_fetch_candidates(PDO $ssoPdo)
{
    margo_sso_ensure_schema($ssoPdo);
    $learnPdo = margo_learn_sync_connect_readonly();

    $uCols = $learnPdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    $selCols = ['id'];
    foreach (['full_name', 'email', 'email_google', 'nisn_nip', 'status', 'is_active'] as $c) {
        if (in_array($c, $uCols, true)) {
            $selCols[] = $c;
        }
    }
    $learnUsers = $learnPdo->query("SELECT `" . implode('`, `', $selCols) . "` FROM `users` ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Ambil peta role dari Learn
    $learnRolesMap = [];
    try {
        $rRows = $learnPdo->query("
            SELECT ur.user_id, r.code
            FROM `user_roles` ur
            JOIN `roles` r ON r.id = ur.role_id
            ORDER BY ur.is_primary DESC, r.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rRows as $rr) {
            $uid = (int)$rr['user_id'];
            if (!isset($learnRolesMap[$uid])) {
                $learnRolesMap[$uid] = [];
            }
            $code = strtolower(trim((string)$rr['code']));
            if ($code !== '' && !in_array($code, $learnRolesMap[$uid], true)) {
                $learnRolesMap[$uid][] = $code;
            }
        }
    } catch (Throwable $e) {
        // Non-fatal
    }

    // Ambil nomor NISN/NIS & gmail siswa (READ-ONLY)
    $siswaByUser = [];
    try {
        if ($learnPdo->query("SHOW TABLES LIKE 'siswa'")->fetch()) {
            $sCols = $learnPdo->query("SHOW COLUMNS FROM `siswa`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('id_user', $sCols, true)) {
                $sSel = ['id_user'];
                foreach (['nisn', 'nis', 'gmail', 'nama_lengkap'] as $c) {
                    if (in_array($c, $sCols, true)) {
                        $sSel[] = $c;
                    }
                }
                foreach ($learnPdo->query("SELECT `" . implode('`, `', $sSel) . "` FROM `siswa` WHERE `id_user` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    $siswaByUser[(int)$sr['id_user']] = $sr;
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal
    }

    // Ambil nomor NIP & email guru (READ-ONLY)
    $guruByUser = [];
    try {
        if ($learnPdo->query("SHOW TABLES LIKE 'guru'")->fetch()) {
            $gCols = $learnPdo->query("SHOW COLUMNS FROM `guru`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('id_user', $gCols, true)) {
                $gSel = ['id_user'];
                foreach (['nip', 'email', 'nama_guru'] as $c) {
                    if (in_array($c, $gCols, true)) {
                        $gSel[] = $c;
                    }
                }
                foreach ($learnPdo->query("SELECT `" . implode('`, `', $gSel) . "` FROM `guru` WHERE `id_user` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                    $guruByUser[(int)$gr['id_user']] = $gr;
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal
    }

    // Index existing SSO users untuk deteksi status sinkronisasi (TIDAK menyentuh Learn)
    $ssoByRef   = [];
    $ssoByEmail = [];
    $ssoByNisn  = [];
    $ssoUsers = $ssoPdo->query("SELECT id, username, full_name, email, nisn_nip, sync_ref_id, credentials_sent_at FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ssoUsers as $su) {
        $ref = trim((string)($su['sync_ref_id'] ?? ''));
        if ($ref !== '') {
            $ssoByRef[$ref] = $su;
        }
        $em = strtolower(trim((string)($su['email'] ?? '')));
        if ($em !== '') {
            $ssoByEmail[$em] = $su;
        }
        $nn = trim((string)($su['nisn_nip'] ?? ''));
        if ($nn !== '' && $nn !== '-') {
            $ssoByNisn[$nn] = $su;
        }
    }

    $candidates = [];
    foreach ($learnUsers as $lu) {
        $lid = (int)$lu['id'];
        if (isset($lu['is_active']) && (int)$lu['is_active'] === 0) {
            continue;
        }
        if (isset($lu['status']) && strtolower(trim((string)$lu['status'])) === 'inactive') {
            continue;
        }

        $sRow = $siswaByUser[$lid] ?? [];
        $gRow = $guruByUser[$lid] ?? [];

        // HANYA ambil Gmail/email (prioritas: email_google Learn -> siswa.gmail -> guru.email -> users.email)
        $gmail = margo_learn_sync_pick_gmail([
            $lu['email_google'] ?? '',
            $sRow['gmail'] ?? '',
            $gRow['email'] ?? '',
            $lu['email'] ?? '',
        ]);
        $isGmailDomain = ($gmail !== '' && (bool)preg_match('/@(gmail\.com|googlemail\.com)$/i', $gmail));

        // Nomor identitas untuk dasar generasi username & password acak (NISN / NIS / NIP / ID)
        $refNumber = '';
        $refType   = 'ID';
        foreach ([
            ['val' => $sRow['nisn'] ?? '', 'type' => 'NISN'],
            ['val' => $sRow['nis'] ?? '', 'type' => 'NIS'],
            ['val' => $gRow['nip'] ?? '', 'type' => 'NIP'],
            ['val' => $lu['nisn_nip'] ?? '', 'type' => 'NISN/NIP'],
        ] as $candRef) {
            $v = trim((string)$candRef['val']);
            if ($v !== '' && $v !== '-' && $v !== '0') {
                $refNumber = $v;
                $refType   = $candRef['type'];
                break;
            }
        }
        if ($refNumber === '') {
            $refNumber = (string)$lid;
            $refType   = 'ID';
        }

        $fullName = trim((string)($sRow['nama_lengkap'] ?? $gRow['nama_guru'] ?? $lu['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Pengguna #' . $lid;
        }

        $roles = $learnRolesMap[$lid] ?? [];
        if (empty($roles)) {
            if (!empty($gRow)) {
                $roles = ['guru'];
            } elseif (!empty($sRow)) {
                $roles = ['siswa'];
            } else {
                $roles = ['siswa'];
            }
        }

        $syncRefKey = 'learn:' . $lid;
        $existingSso = null;
        if (isset($ssoByRef[$syncRefKey])) {
            $existingSso = $ssoByRef[$syncRefKey];
        } elseif ($gmail !== '' && isset($ssoByEmail[$gmail])) {
            $existingSso = $ssoByEmail[$gmail];
        } elseif ($refType !== 'ID' && isset($ssoByNisn[$refNumber])) {
            $existingSso = $ssoByNisn[$refNumber];
        }

        $candidates[] = [
            'learn_id'            => $lid,
            'sync_ref_id'         => $syncRefKey,
            'full_name'           => $fullName,
            'gmail'               => $gmail,
            'is_gmail_domain'     => $isGmailDomain,
            'has_email'           => ($gmail !== ''),
            'ref_number'          => $refNumber,
            'ref_type'            => $refType,
            'roles'               => $roles,
            'primary_role'        => $roles[0],
            'already_in_sso'      => ($existingSso !== null),
            'sso_user_id'         => $existingSso ? (int)$existingSso['id'] : null,
            'sso_username'        => $existingSso ? (string)$existingSso['username'] : '',
            'credentials_sent_at' => $existingSso ? ($existingSso['credentials_sent_at'] ?? null) : null,
        ];
    }

    return $candidates;
}

/**
 * Buat string acak kriptografis bebas karakter membingungkan (tanpa 0/O/1/l/I)
 */
function margo_learn_sync_rand_chars($alphabet, $length)
{
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Bangkitkan USERNAME ACAK & PASSWORD ACAK baru khusus Margo One SSO
 * berdasarkan nomor ID / NISN / NIS / NIP (100% terpisah dari akun Learn).
 */
function margo_learn_sync_generate_credentials(PDO $ssoPdo, $refNumber, $learnId, $roleCode = 'siswa')
{
    $digitsOnly = preg_replace('/\D+/', '', (string)$refNumber);
    if ($digitsOnly === '') {
        $digitsOnly = str_pad((string)max(1, (int)$learnId), 4, '0', STR_PAD_LEFT);
    }
    // Ambil potongan 4-6 digit dari nomor ID/NISN/NIS/NIP sebagai basis acak
    $numSlice = strlen($digitsOnly) > 6 ? substr($digitsOnly, -6) : str_pad($digitsOnly, 4, '0', STR_PAD_LEFT);
    // Acak urutan digit seed dengan salt acak agar tidak bisa ditebak langsung
    $seedHashNum = substr(preg_replace('/\D+/', '', hash('sha256', $digitsOnly . '|' . bin2hex(random_bytes(8)))), 0, 2);
    if (strlen($seedHashNum) < 2) {
        $seedHashNum = (string)random_int(10, 99);
    }

    $prefixMap = [
        'admin'       => 'adm',
        'guru'        => 'gru',
        'ketua_kelas' => 'ktk',
        'siswa'       => 'sws',
    ];
    $rPrefix = $prefixMap[strtolower((string)$roleCode)] ?? 'mgo';

    // 1. Username acak unik berbasis nomor ID/NISN/NIS/NIP
    $username = '';
    for ($try = 0; $try < 25; $try++) {
        $randTail = margo_learn_sync_rand_chars('abcdefghjkmnpqrstuvwxyz23456789', 4);
        $cand = $rPrefix . '_' . substr($numSlice, -4) . $seedHashNum . '_' . $randTail;
        $chk = $ssoPdo->prepare("SELECT id FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1");
        $chk->execute([$cand]);
        if (!$chk->fetchColumn()) {
            $username = $cand;
            break;
        }
    }
    if ($username === '') {
        $username = 'mgo_' . bin2hex(random_bytes(5));
    }

    // 2. Password acak kuat (min 12 karakter) berbasis nomor ID/NISN/NIS/NIP + entropi kriptografis
    $pUpper = margo_learn_sync_rand_chars('ABCDEFGHJKLMNPQRSTUVWXYZ', 2);
    $pLower = margo_learn_sync_rand_chars('abcdefghjkmnpqrstuvwxyz', 3);
    $pSym   = margo_learn_sync_rand_chars('@#!$%', 1);
    $pTail  = margo_learn_sync_rand_chars('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789', 3);
    $pNum   = substr($numSlice, -4);
    $password = $pUpper . $pLower . $pNum . $pSym . $pTail;

    return [
        'username' => $username,
        'password' => $password,
    ];
}

/**
 * Render & kirim email kredensial akun Margo One SSO baru ke Gmail user via EmailRelay.gs (Relay v3.0)
 */
function margo_learn_sync_send_credentials_email($toGmail, $fullName, $ssoUsername, $ssoPassword, $refNumber, $refType, $roleCode, $isReset = false)
{
    if (!filter_var($toGmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Alamat email tujuan tidak valid: ' . $toGmail];
    }

    $cfg = ilmuna_smtp_config();
    if (empty($cfg['is_enabled'])) {
        return ['success' => false, 'message' => 'Pengiriman email dinonaktifkan di konfigurasi server.'];
    }

    $c_navy = '#1D3B5E';
    $c_teal = '#0D7A87';
    $c_sky  = '#52B2DC';

    $siteName = defined('APP_NAME') ? APP_NAME : 'Margo One — Ilmuna SSO';
    $siteTag  = defined('APP_TAGLINE') ? APP_TAGLINE : 'Identity Provider & Single Sign-On Ekosistem Ilmuna';
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://margo-one.ilmuna.my.id';
    $loginUrl = $baseUrl . '/login';
    $tahun    = date('Y');

    $namaEsc = htmlspecialchars((string)$fullName, ENT_QUOTES, 'UTF-8');
    $userEsc = htmlspecialchars((string)$ssoUsername, ENT_QUOTES, 'UTF-8');
    $passEsc = htmlspecialchars((string)$ssoPassword, ENT_QUOTES, 'UTF-8');
    $refEsc  = htmlspecialchars((string)$refType . ': ' . (string)$refNumber, ENT_QUOTES, 'UTF-8');
    $roleEsc = htmlspecialchars(strtoupper((string)$roleCode), ENT_QUOTES, 'UTF-8');
    $loginEsc = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $logoFile = BASE_PATH . '/assets/images/logo-margo-one.png';
    if (!file_exists($logoFile)) {
        $logoFile = BASE_PATH . '/assets/images/logo.png';
    }
    $attachments = [];
    $logoSrc     = $baseUrl . '/assets/images/logo-margo-one.png';
    if (file_exists($logoFile)) {
        $attachments[] = [
            'path'        => $logoFile,
            'name'        => 'logo-margo-one.png',
            'mime'        => 'image/png',
            'cid'         => 'ilmuna_logo',
            'disposition' => 'inline',
        ];
        $logoSrc = 'cid:ilmuna_logo';
    }

    $headline = $isReset ? 'Pembaruan Kredensial Akun Margo One SSO' : 'Akun Margo One SSO Anda Telah Dibuat';
    $subject  = ($isReset ? 'Pembaruan Akun SSO' : 'Informasi Akun Baru') . ' - ' . $siteName;

    $body = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1D3B5E;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F1F5F9;padding:28px 0 36px;">
        <tr>
            <td align="center" style="padding:0 12px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(29,59,94,0.08);border:1px solid #E2E8F0;">
                    <tr>
                        <td style="background:linear-gradient(135deg, {$c_navy} 0%, #152C47 50%, {$c_teal} 100%);padding:30px 28px 24px;text-align:center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 14px;">
                                <tr>
                                    <td align="center" style="width:74px;height:74px;background-color:#FFFFFF;border-radius:18px;box-shadow:0 6px 20px rgba(0,0,0,0.18);border:2px solid {$c_sky};text-align:center;vertical-align:middle;">
                                        <img src="{$logoSrc}" alt="{$siteName}" width="52" height="52" style="width:52px;height:52px;max-width:52px;display:block;margin:0 auto;object-fit:contain;" />
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:0 0 6px;color:#FFFFFF;font-size:20px;font-weight:800;line-height:1.3;">{$headline}</h1>
                            <p style="margin:0;color:{$c_sky};font-size:12.5px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;">{$siteName}</p>
                            <p style="margin:4px 0 0;color:#E2E8F0;font-size:11.5px;">{$siteTag}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 30px 20px;">
                            <p style="margin:0 0 12px;font-size:14.5px;color:{$c_navy};line-height:1.6;">
                                <strong>Assalamu'alaikum Warahmatullahi Wabarakatuh,</strong><br>
                                Yth. <strong style="color:{$c_teal};">{$namaEsc}</strong> ({$roleEsc})
                            </p>
                            <p style="margin:0 0 18px;font-size:13.5px;color:#475569;line-height:1.7;">
                                Akun <strong>Margo One SSO</strong> Anda telah dibuat secara <strong>terpisah penuh</strong> dari akun lokal aplikasi lain.
                                Gunakan kredensial acak khusus di bawah ini untuk masuk ke portal <strong>{$siteName}</strong>:
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F8FAFC;border:1.5px solid #CBD5E1;border-left:4.5px solid {$c_teal};border-radius:12px;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:{$c_teal};margin-bottom:4px;">Username Margo One SSO</div>
                                        <div style="font-family:Consolas,monospace;font-size:18px;font-weight:800;color:{$c_navy};margin-bottom:14px;word-break:break-all;">{$userEsc}</div>

                                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:{$c_teal};margin-bottom:4px;">Kata Sandi SSO (Acak)</div>
                                        <div style="font-family:Consolas,monospace;font-size:18px;font-weight:800;color:{$c_navy};margin-bottom:10px;word-break:break-all;">{$passEsc}</div>

                                        <div style="font-size:11.5px;color:#64748B;">Referensi: {$refEsc}</div>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 22px;">
                                <tr>
                                    <td align="center" style="border-radius:10px;background:linear-gradient(135deg, {$c_teal} 0%, {$c_navy} 100%);box-shadow:0 6px 18px rgba(13,122,135,0.35);">
                                        <a href="{$loginEsc}" target="_blank" style="display:inline-block;padding:13px 30px;font-size:14.5px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">
                                            Masuk ke Margo One SSO
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F0F9FF;border-left:4px solid {$c_sky};border-radius:8px;margin-bottom:16px;">
                                <tr>
                                    <td style="padding:13px 16px;font-size:12.5px;color:{$c_navy};line-height:1.6;">
                                        <strong>Catatan Keamanan Penting:</strong><br>
                                        &bull; Akun Margo One SSO ini memiliki username, kata sandi, foto profil, dan tautan Google yang <strong>terpisah total</strong> dari Ilmuna Learn.<br>
                                        &bull; Segera ubah kata sandi Anda setelah login pertama di menu <strong>Profil &amp; Keamanan</strong>.<br>
                                        &bull; Anda juga dapat menautkan akun Google SSO secara mandiri melalui konfirmasi Gmail di halaman Profil.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 20px;background-color:#F8FAFC;border-top:1px solid #E2E8F0;text-align:center;">
                            <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:{$c_navy};">{$siteName}</p>
                            <p style="margin:0;font-size:11px;color:#94A3B8;">&copy; {$tahun} {$siteName}. Hak Cipta Dilindungi.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return ilmuna_raw_smtp_send([
        'host'        => $cfg['host'] ?: 'mail.ilmuna.my.id',
        'port'        => (int)($cfg['port'] ?: 465),
        'secure'      => strtolower($cfg['secure'] ?: 'ssl'),
        'user'        => $cfg['user'] ?: '',
        'pass'        => $cfg['pass'] ?: '',
        'from'        => $cfg['from'] ?: ($cfg['user'] ?: 'noreply@ilmuna.my.id'),
        'from_name'   => $cfg['from_name'] ?: 'Margo One — Ilmuna SSO',
        'to'          => $toGmail,
        'subject'     => $subject,
        'body'        => $body,
        'attachments' => $attachments,
    ]);
}

/**
 * Eksekusi sinkronisasi 1 kandidat dari Learn -> buat akun Margo One SSO terpisah + kirim kredensial ke Gmail
 */
function margo_learn_sync_execute_one(PDO $ssoPdo, array $cand, $resendIfExists = false, $adminUserId = null)
{
    margo_sso_ensure_schema($ssoPdo);

    $learnId   = (int)($cand['learn_id'] ?? 0);
    $gmail     = strtolower(trim((string)($cand['gmail'] ?? '')));
    $fullName  = trim((string)($cand['full_name'] ?? ('Pengguna #' . $learnId)));
    $refNumber = trim((string)($cand['ref_number'] ?? (string)$learnId));
    $refType   = trim((string)($cand['ref_type'] ?? 'ID'));
    $roles     = !empty($cand['roles']) && is_array($cand['roles']) ? $cand['roles'] : ['siswa'];
    $primary   = (string)($roles[0] ?? 'siswa');
    $syncRef   = 'learn:' . $learnId;

    if ($learnId <= 0 || $gmail === '' || !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'status'  => 'skipped_no_email',
            'message' => 'User Learn #' . $learnId . ' tidak memiliki alamat Gmail/email valid.',
        ];
    }

    // Cek apakah akun SSO sudah ada
    $existing = null;
    $stEx = $ssoPdo->prepare("SELECT id, username, email FROM `users` WHERE `sync_ref_id` = ? OR LOWER(`email`) = LOWER(?) LIMIT 1");
    $stEx->execute([$syncRef, $gmail]);
    $existing = $stEx->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existing && !$resendIfExists) {
        return [
            'success'      => true,
            'status'       => 'already_exists',
            'sso_user_id'  => (int)$existing['id'],
            'sso_username' => (string)$existing['username'],
            'gmail'        => $gmail,
            'message'      => 'Sudah terdaftar di Margo One SSO (@' . $existing['username'] . ').',
        ];
    }

    $creds = margo_learn_sync_generate_credentials($ssoPdo, $refNumber, $learnId, $primary);
    $isReset = false;

    if ($existing) {
        // Mode resend: buat ulang password acak baru untuk akun SSO eksisting (username tetap atau gunakan yang ada)
        $isReset     = true;
        $ssoUserId   = (int)$existing['id'];
        $ssoUsername = (string)$existing['username'];
        $ssoPassword = $creds['password'];

        $ssoPdo->prepare("UPDATE `users` SET `password_hash` = ?, `email` = ?, `nisn_nip` = COALESCE(NULLIF(`nisn_nip`, ''), ?), `sync_source` = 'learn', `sync_ref_id` = COALESCE(`sync_ref_id`, ?) WHERE `id` = ?")
            ->execute([password_hash($ssoPassword, PASSWORD_DEFAULT), $gmail, $refNumber, $syncRef, $ssoUserId]);
    } else {
        // Buat akun SSO BARU 100% terpisah (email_google=NULL, google_id=NULL, foto_profil=NULL)
        $ssoUsername = $creds['username'];
        $ssoPassword = $creds['password'];

        $ins = $ssoPdo->prepare("
            INSERT INTO `users`
                (`username`, `email`, `email_google`, `google_id`, `password_hash`, `full_name`, `nisn_nip`, `foto_profil`, `status`, `is_active`, `sync_source`, `sync_ref_id`)
            VALUES
                (?, ?, NULL, NULL, ?, ?, ?, NULL, 'active', 1, 'learn', ?)
        ");
        $ins->execute([
            $ssoUsername,
            $gmail,
            password_hash($ssoPassword, PASSWORD_DEFAULT),
            $fullName,
            $refNumber,
            $syncRef,
        ]);
        $ssoUserId = (int)$ssoPdo->lastInsertId();

        // Assign role di tabel user_roles milik Margo One SSO
        $roleMap = [];
        foreach ($ssoPdo->query("SELECT id, code FROM `roles`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roleMap[strtolower((string)$r['code'])] = (int)$r['id'];
        }
        $insUr = $ssoPdo->prepare("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `is_primary`) VALUES (?, ?, ?)");
        $assignedAny = false;
        foreach ($roles as $idx => $rc) {
            $rcLow = strtolower(trim((string)$rc));
            if (isset($roleMap[$rcLow])) {
                $insUr->execute([$ssoUserId, $roleMap[$rcLow], $idx === 0 ? 1 : 0]);
                $assignedAny = true;
            }
        }
        if (!$assignedAny && isset($roleMap['siswa'])) {
            $insUr->execute([$ssoUserId, $roleMap['siswa'], 1]);
        }
    }

    // Kirim username acak & password acak ke Gmail user melalui EmailRelay.gs
    $mailRes = margo_learn_sync_send_credentials_email(
        $gmail,
        $fullName,
        $ssoUsername,
        $ssoPassword,
        $refNumber,
        $refType,
        $primary,
        $isReset
    );
    $mailOk  = !empty($mailRes['success']);
    $mailMsg = (string)($mailRes['message'] ?? ($mailOk ? 'Terkirim' : 'Gagal kirim email'));

    if ($mailOk) {
        $ssoPdo->prepare("UPDATE `users` SET `credentials_sent_at` = ? WHERE `id` = ?")
            ->execute([date('Y-m-d H:i:s'), $ssoUserId]);
    }

    // Catat log sinkronisasi khusus SSO
    try {
        $ssoPdo->prepare("
            INSERT INTO `sso_user_sync_logs`
                (`source_app`, `source_ref`, `target_email`, `sso_user_id`, `generated_username`, `status`, `message`)
            VALUES
                ('learn', ?, ?, ?, ?, ?, ?)
        ")->execute([
            $syncRef,
            $gmail,
            $ssoUserId,
            $ssoUsername,
            $mailOk ? ($isReset ? 'resent' : 'sent') : 'mail_failed',
            substr($mailMsg, 0, 250),
        ]);
    } catch (Throwable $eLog) {
        // Non-fatal
    }

    margo_sso_log_audit($ssoPdo, $isReset ? 'admin_learn_sync_resent' : 'admin_learn_sync_created', [
        'user_id' => $adminUserId ?: $ssoUserId,
        'detail'  => [
            'sso_user_id' => $ssoUserId,
            'username'    => $ssoUsername,
            'target_mail' => $gmail,
            'ref'         => $syncRef,
            'mail_ok'     => $mailOk,
        ],
    ]);

    return [
        'success'      => $mailOk,
        'account_ok'   => true,
        'status'       => $mailOk ? ($isReset ? 'resent' : 'sent') : 'mail_failed',
        'sso_user_id'  => $ssoUserId,
        'sso_username' => $ssoUsername,
        'gmail'        => $gmail,
        'message'      => $mailOk
            ? (($isReset ? 'Kredensial baru dikirim ulang' : 'Akun SSO dibuat & kredensial dikirim') . ' ke ' . $gmail . ' (@' . $ssoUsername . ')')
            : ('Akun SSO @' . $ssoUsername . ' dibuat, tetapi pengiriman Gmail gagal: ' . $mailMsg),
    ];
}
