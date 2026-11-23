<?php
// ==============================================================================
// GUARDIAN DOME (Patch-3.0) — LAYER PERLINDUNGAN TERDEPAN MARGO ONE SSO
// Target: margo-one.ilmuna.my.id (Identity Provider Pusat)
// Halaman browser dilindungi penuh; folder /api/sso/ terisolasi via flag & .htaccess
// ==============================================================================
if (php_sapi_name() !== 'cli' && (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL') || !ILMUNA_SKIP_GD_WAF_INTERSTITIAL)) {
    $gd_waf_candidates = [
        '/home/uofruaju/guardian-dome/guardian-dome-waf.php',
        'C:/laragon/Guardian Dome/guardian-dome-waf.php',
        'C:/laragon/guardian-dome/guardian-dome-waf.php',
        dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-waf.php',
        dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-waf.php',
        dirname(__DIR__) . '/Guardian Dome/guardian-dome-waf.php',
        dirname(__DIR__) . '/guardian-dome/guardian-dome-waf.php',
        __DIR__ . '/../Guardian Dome/guardian-dome-waf.php',
        __DIR__ . '/../guardian-dome/guardian-dome-waf.php',
        __DIR__ . '/guardian-dome-waf.php'
    ];
    foreach ($gd_waf_candidates as $gd_waf) {
        if ($gd_waf && file_exists($gd_waf)) {
            require_once $gd_waf;
            break;
        }
    }
}

// Guardian Dome Telegram Sentinel Monitoring
if (php_sapi_name() !== 'cli' && (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL') || !ILMUNA_SKIP_GD_WAF_INTERSTITIAL)) {
    $gd_tg_candidates = [
        '/home/uofruaju/guardian-dome/guardian-dome-telegram/bootstrap.php',
        'C:/laragon/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        'C:/laragon/guardian-dome/guardian-dome-telegram/bootstrap.php',
        dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
        dirname(__DIR__) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        dirname(__DIR__) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
        __DIR__ . '/../Guardian Dome/guardian-dome-telegram/bootstrap.php',
        __DIR__ . '/../guardian-dome/guardian-dome-telegram/bootstrap.php',
        __DIR__ . '/guardian-dome-telegram/bootstrap.php'
    ];
    foreach ($gd_tg_candidates as $gd_tg) {
        if ($gd_tg && file_exists($gd_tg)) {
            require_once $gd_tg;
            break;
        }
    }
}

/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO (IDENTITY PROVIDER) CONFIGURATION
 * ==============================================================================
 * Subdomain Produksi : https://margo-one.ilmuna.my.id
 * Tema Resmi         : Lumina Gold & Nusantara (#1D3B5E, #0D7A87, #52B2DC, #F59E0B)
 * Kompatibilitas     : PHP 7.4 (ea-php74) hingga PHP 8.3 · MySQL PDO InnoDB utf8mb4
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

// ==============================================================================
// PHP 7.4 COMPATIBILITY POLYFILLS & SAFE MULTIBYTE HELPERS
// ==============================================================================
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp((string)$haystack, (string)$needle, strlen((string)$needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $needleStr = (string)$needle;
        if ($needleStr === '') {
            return true;
        }
        return substr((string)$haystack, -strlen($needleStr)) === $needleStr;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return (string)$needle !== '' && strpos((string)$haystack, (string)$needle) !== false;
    }
}

if (!function_exists('margo_mb_strlen')) {
    function margo_mb_strlen($str) {
        $s = (string)$str;
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
if (!function_exists('margo_mb_substr')) {
    function margo_mb_substr($str, $start, $length = null) {
        $s = (string)$str;
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
}
if (!function_exists('margo_mb_strtoupper')) {
    function margo_mb_strtoupper($str) {
        $s = (string)$str;
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }
}
if (!function_exists('margo_mb_strtolower')) {
    function margo_mb_strtolower($str) {
        $s = (string)$str;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

// ==============================================================================
// SESSION BOOTSTRAP (AMAN UNTUK BROWSER & AJAX PORTAL, DILEWATI UNTUK /api/sso/ & CLI)
// ==============================================================================
$scriptPathForSess = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
$isStatelessSsoApi = (strpos($scriptPathForSess, '/api/sso/') !== false);
if (!$isStatelessSsoApi && session_status() === PHP_SESSION_NONE && !headers_sent() && php_sapi_name() !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    session_name('MARGO_ONE_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ==============================================================================
// APPLICATION CONSTANTS
// ==============================================================================
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Margo One — Ilmuna SSO');
}
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', 'Margo One');
}
if (!defined('APP_TAGLINE')) {
    define('APP_TAGLINE', 'Identity Provider & Single Sign-On Ekosistem Ilmuna');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
if (!defined('APP_DOMAIN')) {
    define('APP_DOMAIN', 'margo-one.ilmuna.my.id');
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// Base URL Auto-detection (mendukung domain produksi & Laragon subfolder /SSO)
if (!defined('BASE_URL')) {
    if (php_sapi_name() === 'cli') {
        define('BASE_URL', 'https://' . APP_DOMAIN);
    } else {
        $isHttpsUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $protocol = $isHttpsUrl ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        $scriptDir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : ''));
        $basePath = preg_replace('#/(dashboard(?:/.*)?|api(?:/.*)?|sdk(?:/.*)?|includes(?:/.*)?)$#', '', $scriptDir);
        $basePath = rtrim((string)$basePath, '/');
        if ($basePath === '.' || $basePath === './') {
            $basePath = '';
        }
        define('BASE_URL', $protocol . $host . $basePath);
    }
}

// Issuer resmi JWT / OIDC
if (!defined('SSO_ISSUER')) {
    $hostCheck = strtolower((string)(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    if ($hostCheck === APP_DOMAIN || php_sapi_name() === 'cli') {
        define('SSO_ISSUER', 'https://' . APP_DOMAIN);
    } else {
        define('SSO_ISSUER', rtrim(BASE_URL, '/'));
    }
}

// Durasi Token & Sesi Default
if (!defined('SSO_AUTH_CODE_TTL')) {
    define('SSO_AUTH_CODE_TTL', 60); // 60 detik sekali pakai
}
if (!defined('SSO_ACCESS_TOKEN_TTL')) {
    define('SSO_ACCESS_TOKEN_TTL', 900); // 15 menit
}
if (!defined('SSO_ID_TOKEN_TTL')) {
    define('SSO_ID_TOKEN_TTL', 3600); // 1 jam
}
if (!defined('SSO_REFRESH_TOKEN_TTL')) {
    define('SSO_REFRESH_TOKEN_TTL', 86400 * 14); // 14 hari (family rotation)
}
if (!defined('SSO_SESSION_TTL')) {
    define('SSO_SESSION_TTL', 86400 * 7); // 7 hari sesi global SSO
}
if (!defined('SSO_SESSION_COOKIE')) {
    define('SSO_SESSION_COOKIE', 'margo_one_sso_session');
}

// ==============================================================================
// CENTRALIZED FAVICON, PWA, ICONIFY & UI TEMPLATES
// ==============================================================================
define('APP_FAVICON_FILE', '/assets/images/logo.ico');
define('APP_FAVICON', BASE_URL . APP_FAVICON_FILE);
define('APP_LOGO_URL', BASE_URL . '/assets/images/logo-margo-one.png');
define('APP_LOGO_LANDSCAPE_URL', BASE_URL . '/assets/images/landscape-logo-margo-one.png');

define('ICONIFY_CDN_URL', 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js');
define('ICONIFY_OFFICIAL_SET', 'solar');

define('INCLUDES_PATH', BASE_PATH . '/includes');
define('TEMPLATE_MODAL_PATH', BASE_PATH . '/includes/modal-template.php');
define('TEMPLATE_CALENDAR_PATH', BASE_PATH . '/includes/calendar-template.php');
define('TEMPLATE_TABLE_PATH', BASE_PATH . '/includes/table-template.php');
define('ALERT_JS_PATH', BASE_PATH . '/assets/js/alert.js');

function getFaviconUrl() {
    $relativePath = defined('APP_FAVICON_FILE') ? APP_FAVICON_FILE : '/assets/images/logo.ico';
    $absolutePath = BASE_PATH . $relativePath;
    $version = file_exists($absolutePath) ? filemtime($absolutePath) : APP_VERSION;
    return BASE_URL . $relativePath . '?v=' . $version;
}

function renderPwaHead() {
    $base = BASE_URL;
    $root = BASE_PATH;
    $ver = function ($rel) use ($root) {
        $f = $root . $rel;
        return file_exists($f) ? filemtime($f) : APP_VERSION;
    };
    $e = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };

    $out  = '    <!-- PWA (Progressive Web App) Margo One -->' . "\n";
    if (file_exists($root . '/manifest.json')) {
        $out .= '    <link rel="manifest" href="' . $e($base . '/manifest.json?v=' . $ver('/manifest.json')) . '">' . "\n";
    }
    $out .= '    <meta name="theme-color" content="#0D7A87">' . "\n";
    $out .= '    <meta name="mobile-web-app-capable" content="yes">' . "\n";
    $out .= '    <meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $out .= '    <meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    $out .= '    <meta name="apple-mobile-web-app-title" content="' . $e(APP_SHORT_NAME) . '">' . "\n";
    if (file_exists($root . '/assets/pwa/apple-touch-icon.png')) {
        $out .= '    <link rel="apple-touch-icon" sizes="180x180" href="' . $e($base . '/assets/pwa/apple-touch-icon.png') . '">' . "\n";
    }
    if (file_exists($root . '/assets/js/pwa.js')) {
        $out .= '    <script src="' . $e($base . '/assets/js/pwa.js?v=' . $ver('/assets/js/pwa.js')) . '" data-base="' . $e($base) . '" defer></script>';
    }
    return $out;
}

function renderFavicon() {
    $url = htmlspecialchars(getFaviconUrl(), ENT_QUOTES, 'UTF-8');
    return '    <!-- Centralized Website Favicon via config.php -->' . "\n"
         . '    <link rel="icon" type="image/x-icon" href="' . $url . '">' . "\n"
         . '    <link rel="shortcut icon" type="image/x-icon" href="' . $url . '">' . "\n"
         . renderPwaHead();
}

// ==============================================================================
// CENTRALIZED SEO, OPEN GRAPH, JSON-LD & VERIFICATION CONFIG (MARGO ONE SSO)
// Isi kode verifikasi Google Search Console / Bing / GA4 di sini atau config.local.php
// ==============================================================================
if (!defined('SEO_GOOGLE_SITE_VERIFICATION')) define('SEO_GOOGLE_SITE_VERIFICATION', '');
if (!defined('SEO_BING_VERIFICATION'))        define('SEO_BING_VERIFICATION', '');
if (!defined('SEO_YANDEX_VERIFICATION'))      define('SEO_YANDEX_VERIFICATION', '');
if (!defined('SEO_GA4_MEASUREMENT_ID'))       define('SEO_GA4_MEASUREMENT_ID', '');

function renderSeoMeta(array $opts = []) {
    $e = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };
    $canonicalBase = 'https://' . APP_DOMAIN;
    $title       = (string)($opts['title'] ?? (APP_NAME . ' — Portal Single Sign-On Ekosistem Ilmuna'));
    $description = (string)($opts['description'] ?? 'Margo One adalah portal Single Sign-On (SSO) resmi ekosistem digital Ilmuna. Masuk satu kali dengan keamanan OAuth 2.0, PKCE, dan RS256 untuk mengakses Ilmuna Learn LMS, Portal Ilmuna, dan layanan akademik.');
    $keywords    = (string)($opts['keywords'] ?? 'Margo One, Ilmuna SSO, Single Sign-On Ilmuna, Login Ilmuna Learn, Portal Akademik Ilmuna, margo-one.ilmuna.my.id');
    $canonical   = (string)($opts['canonical'] ?? ($canonicalBase . '/login'));
    $robots      = (string)($opts['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1');
    $ogImage     = (string)($opts['image'] ?? ($canonicalBase . '/assets/images/landscape-logo-margo-one.png'));
    $includeLd   = !isset($opts['json_ld']) || !empty($opts['json_ld']);

    $out  = '    <!-- SEO & Social Meta Tags (Margo One SSO) -->' . "\n";
    $out .= '    <meta name="description" content="' . $e($description) . '">' . "\n";
    $out .= '    <meta name="keywords" content="' . $e($keywords) . '">' . "\n";
    $out .= '    <meta name="author" content="Ilmuna Digital Ecosystem">' . "\n";
    $out .= '    <meta name="robots" content="' . $e($robots) . '">' . "\n";
    $out .= '    <link rel="canonical" href="' . $e($canonical) . '">' . "\n";

    if (SEO_GOOGLE_SITE_VERIFICATION !== '') {
        $out .= '    <meta name="google-site-verification" content="' . $e(SEO_GOOGLE_SITE_VERIFICATION) . '">' . "\n";
    }
    if (SEO_BING_VERIFICATION !== '') {
        $out .= '    <meta name="msvalidate.01" content="' . $e(SEO_BING_VERIFICATION) . '">' . "\n";
    }
    if (SEO_YANDEX_VERIFICATION !== '') {
        $out .= '    <meta name="yandex-verification" content="' . $e(SEO_YANDEX_VERIFICATION) . '">' . "\n";
    }

    // Open Graph (Facebook, WhatsApp, LinkedIn, Telegram)
    $out .= '    <meta property="og:type" content="website">' . "\n";
    $out .= '    <meta property="og:locale" content="id_ID">' . "\n";
    $out .= '    <meta property="og:site_name" content="' . $e(APP_NAME) . '">' . "\n";
    $out .= '    <meta property="og:title" content="' . $e($title) . '">' . "\n";
    $out .= '    <meta property="og:description" content="' . $e($description) . '">' . "\n";
    $out .= '    <meta property="og:url" content="' . $e($canonical) . '">' . "\n";
    $out .= '    <meta property="og:image" content="' . $e($ogImage) . '">' . "\n";

    // Twitter / X Card
    $out .= '    <meta name="twitter:card" content="summary_large_image">' . "\n";
    $out .= '    <meta name="twitter:title" content="' . $e($title) . '">' . "\n";
    $out .= '    <meta name="twitter:description" content="' . $e($description) . '">' . "\n";
    $out .= '    <meta name="twitter:image" content="' . $e($ogImage) . '">' . "\n";

    if ($includeLd) {
        $ld = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'       => 'Organization',
                    'name'        => 'Ilmuna Digital Ecosystem',
                    'url'         => 'https://ilmuna.my.id',
                    'logo'        => $canonicalBase . '/assets/images/logo-margo-one.png',
                ],
                [
                    '@type'               => 'WebApplication',
                    'name'                => APP_NAME,
                    'alternateName'       => 'Margo One SSO',
                    'url'                 => $canonical,
                    'applicationCategory' => 'EducationalApplication',
                    'operatingSystem'     => 'Web, Android, iOS',
                    'inLanguage'          => 'id-ID',
                    'description'         => $description,
                    'publisher'           => [
                        '@type' => 'Organization',
                        'name'  => 'Ilmuna Digital Ecosystem',
                        'url'   => 'https://ilmuna.my.id',
                    ],
                ],
            ],
        ];
        $out .= '    <script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    if (SEO_GA4_MEASUREMENT_ID !== '') {
        $gaId = $e(SEO_GA4_MEASUREMENT_ID);
        $out .= '    <script async src="https://www.googletagmanager.com/gtag/js?id=' . $gaId . '"></script>' . "\n";
        $out .= "    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $gaId . "');</script>\n";
    }

    return $out;
}

function renderIconifyScript() {
    return '    <!-- Centralized Iconify Solar Icons Engine via config.php -->' . "\n"
         . '    <script src="' . ICONIFY_CDN_URL . '" defer></script>';
}

function loadCentralTemplates(array $templates = ['modal', 'calendar', 'table']) {
    foreach ($templates as $tpl) {
        switch (strtolower(trim((string)$tpl))) {
            case 'modal':
                if (file_exists(TEMPLATE_MODAL_PATH)) {
                    require_once TEMPLATE_MODAL_PATH;
                }
                break;
            case 'calendar':
            case 'kalender':
                if (file_exists(TEMPLATE_CALENDAR_PATH)) {
                    require_once TEMPLATE_CALENDAR_PATH;
                }
                break;
            case 'table':
            case 'tabel':
                if (file_exists(TEMPLATE_TABLE_PATH)) {
                    require_once TEMPLATE_TABLE_PATH;
                }
                break;
        }
    }
}

// ==============================================================================
// DATABASE CREDENTIALS (DEDICATED ISOLATED SSO DB: uofruaju_margo-one)
// ==============================================================================
// Mendukung override opsional dari file lokal config.local.php (tidak di-commit ke Git)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('DB_TYPE'))    define('DB_TYPE', 'mysql');
if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_PORT'))    define('DB_PORT', '3306');
if (!defined('DB_NAME'))    define('DB_NAME', 'uofruaju_margo-one');
if (!defined('DB_USER'))    define('DB_USER', 'uofruaju_margo-one-db');
if (!defined('DB_PASS'))    define('DB_PASS', 'M@r90On3');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Dedicated Agenda Database (berjalan di DB dedicated Margo One: uofruaju_margo-one)
if (!defined('AGENDA_DB_NAME')) define('AGENDA_DB_NAME', DB_NAME);
if (!defined('AGENDA_DB_USER')) define('AGENDA_DB_USER', DB_USER);
if (!defined('AGENDA_DB_PASS')) define('AGENDA_DB_PASS', DB_PASS);

// Database Ilmuna Learn (READ-ONLY: sumber Kaldik, kelas, guru, siswa untuk sinkronisasi)
if (!defined('LEARN_DB_NAME')) define('LEARN_DB_NAME', 'uofruaju_learn_ilmuna');
if (!defined('LEARN_DB_USER')) define('LEARN_DB_USER', 'uofruaju_Learn_Ilmuna');
if (!defined('LEARN_DB_PASS')) define('LEARN_DB_PASS', 'Ilmuna28@');

// ==============================================================================
// GOOGLE OAUTH 2.0 CONFIGURATION (STRICT LINKED-ONLY MODE)
// Client ID bersifat publik (Google Identity Services / Browser OAuth).
// Client Secret sensitif disimpan terenkripsi di tabel sso_settings (via Panel Admin)
// atau melalui config.local.php (gitignored) / environment variable server.
// ==============================================================================
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '601762704297-evhn06rui0pce1jnk9fcco0uko8pnn0c.apps.googleusercontent.com');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
}

// ==============================================================================
// GOOGLE APPS SCRIPT RELAY & SMTP CONFIGURATION (VERIFIKASI GMAIL PENAUTAN SSO)
// ==============================================================================
if (!defined('ILMUNA_GOOGLE_RELAY_URL')) {
    define('ILMUNA_GOOGLE_RELAY_URL', 'https://script.google.com/macros/s/AKfycbyfBUsTy6pTx-738xeXs_-MahXE_xAP3DOqfVNEfBE0TBgIjKPPlQ2z7FZHXsoR_i0Cwg/exec');
}
if (!defined('ILMUNA_GOOGLE_RELAY_TOKEN')) {
    define('ILMUNA_GOOGLE_RELAY_TOKEN', '1LmuN@28');
}
if (!defined('ILMUNA_GOOGLE_RELAY_PUBLIC_KEY')) {
    define('ILMUNA_GOOGLE_RELAY_PUBLIC_KEY', 'pk_ilmuna_7c7cbb2a802e0bce86aa7c682859e9ce');
}
if (!defined('ILMUNA_GOOGLE_RELAY_PRIVATE_KEY')) {
    define('ILMUNA_GOOGLE_RELAY_PRIVATE_KEY', 'sk_ilmuna_8d5e0907150fb0054ce9be16fcde014c464ca4cbb28482ecbfd16cea0835c957');
}
if (!defined('ILMUNA_GOOGLE_RELAY_BACKUP_URLS')) {
    define('ILMUNA_GOOGLE_RELAY_BACKUP_URLS', []);
}
if (!defined('ILMUNA_SMTP_HOST'))      define('ILMUNA_SMTP_HOST', 'mail.ilmuna.my.id');
if (!defined('ILMUNA_SMTP_PORT'))      define('ILMUNA_SMTP_PORT', 465);
if (!defined('ILMUNA_SMTP_SECURE'))    define('ILMUNA_SMTP_SECURE', 'ssl');
if (!defined('ILMUNA_SMTP_USER'))      define('ILMUNA_SMTP_USER', 'noreply@ilmuna.my.id');
if (!defined('ILMUNA_SMTP_PASS'))      define('ILMUNA_SMTP_PASS', 'Ilmuna28@');
if (!defined('ILMUNA_SMTP_FROM'))      define('ILMUNA_SMTP_FROM', 'noreply@ilmuna.my.id');
if (!defined('ILMUNA_SMTP_FROM_NAME')) define('ILMUNA_SMTP_FROM_NAME', 'Margo One — Ilmuna SSO');
if (!defined('ILMUNA_SMTP_ENABLED'))   define('ILMUNA_SMTP_ENABLED', true);

function getGoogleOAuthConfig($forceRefresh = false) {
    static $cached = null;
    if ($cached !== null && !$forceRefresh) {
        return $cached;
    }
    $cid = defined('GOOGLE_CLIENT_ID') ? trim((string)GOOGLE_CLIENT_ID) : '';
    $sec = defined('GOOGLE_CLIENT_SECRET') ? trim((string)GOOGLE_CLIENT_SECRET) : '';

    try {
        $pdoG = getDBConnection();
        if (function_exists('margo_sso_get_setting')) {
            $dbCid = trim((string)margo_sso_get_setting($pdoG, 'google_client_id', ''));
            $dbSec = trim((string)margo_sso_get_setting($pdoG, 'google_client_secret', ''));
            if ($dbCid !== '') {
                $cid = $dbCid;
            }
            if ($dbSec !== '') {
                $sec = $dbSec;
            }
        }
    } catch (Throwable $eG) {
        // Fallback ke config.local.php / env
    }

    $cached = [
        'client_id'     => $cid,
        'client_secret' => $sec,
        'redirect_uri'  => BASE_URL . '/login.php',
    ];
    return $cached;
}

// ==============================================================================
// PDO SINGLETON CONNECTION (MySQL InnoDB utf8mb4 + Timezone +07:00)
// ==============================================================================
function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $errors = [];
    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'";
    }

    // 1. Koneksi langsung dengan kredensial utama (DB_NAME / DB_USER / DB_PASS atau config.local.php)
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
        $pdo->exec("SET time_zone = '+07:00'");
    } catch (PDOException $e1) {
        $errors[] = 'Primary MySQL: ' . $e1->getMessage();

        // 2. Fallback lokal Laragon / XAMPP (127.0.0.1 root tanpa password -> tetap memakai DB_NAME dedicated)
        $hostNow = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $isLocalEnv = (php_sapi_name() === 'cli')
            || strpos($hostNow, 'localhost') === 0
            || strpos($hostNow, '127.0.0.1') === 0
            || substr($hostNow, -5) === '.test';

        if ($isLocalEnv) {
            try {
                $dsnLocalNoDb = "mysql:host=127.0.0.1;port=" . DB_PORT . ";charset=" . DB_CHARSET;
                $tmpPdo = new PDO($dsnLocalNoDb, 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                $dsnLocal = "mysql:host=127.0.0.1;port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $pdo = new PDO($dsnLocal, 'root', '', $pdoOptions);
                $pdo->exec("SET time_zone = '+07:00'");
            } catch (PDOException $e2) {
                $errors[] = 'Local Laragon MySQL: ' . $e2->getMessage();
            }
        }

        if ($pdo === null) {
            throw new RuntimeException('Gagal terhubung ke MySQL Margo One SSO (' . DB_NAME . '): ' . implode(' | ', $errors));
        }
    }

    autoInitializeSsoDatabase($pdo);
    return $pdo;
}

/**
 * Auto-migrasi ringan seluruh tabel SSO (sekali per request)
 */
function autoInitializeSsoDatabase(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $secFile = BASE_PATH . '/includes/sso-security.php';
    if (file_exists($secFile)) {
        require_once $secFile;
        if (function_exists('margo_sso_ensure_schema')) {
            try {
                // Jika DB benar-benar baru (belum ada tabel users), jalankan seeder identitas otomatis
                $hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                if (!$hasUsers && file_exists(BASE_PATH . '/database.php')) {
                    require_once BASE_PATH . '/database.php';
                    if (function_exists('margo_sso_ensure_identity_tables')) {
                        margo_sso_ensure_identity_tables($pdo);
                    }
                }
                margo_sso_ensure_schema($pdo);

                // Inisialisasi database khusus Agenda & sinkronisasi Trigger Kaldik (idempoten, aman cPanel)
                $agendaEngineFile = BASE_PATH . '/includes/agenda-engine.php';
                if (file_exists($agendaEngineFile)) {
                    require_once $agendaEngineFile;
                    if (function_exists('margo_agenda_bootstrap')) {
                        @margo_agenda_bootstrap($pdo, false);
                    }
                }

                // Auto-sync sekali ke folder sibling /home/uofruaju/learn.ilmuna.my.id di cPanel bila belum tersinkron
                $syncFlagFile = sys_get_temp_dir() . '/margo_learn_slo_synced_v7.flag';
                if (!file_exists($syncFlagFile) && is_dir('/home/uofruaju/learn.ilmuna.my.id')) {
                    if (file_exists(BASE_PATH . '/database.php')) {
                        require_once BASE_PATH . '/database.php';
                        if (function_exists('margo_sso_sync_sibling_learn')) {
                            @margo_sso_sync_sibling_learn();
                            @file_put_contents($syncFlagFile, (string)time());
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[MargoOne] Auto-schema warning: ' . $e->getMessage());
            }
        }
    }
}
