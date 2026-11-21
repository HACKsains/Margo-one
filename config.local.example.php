<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · CONTOH KONFIGURASI LOKAL / PRODUKSI
 * ==============================================================================
 * Salin berkas ini menjadi `config.local.php` (terdaftar di .gitignore)
 * lalu isi kredensial Google OAuth 2.0 & database produksi Anda.
 * ==============================================================================
 */

// Database MySQL (opsional jika berbeda dari default config.php)
// define('DB_HOST', 'localhost');
// define('DB_PORT', '3306');
// define('DB_NAME', 'uofruaju_margo_one');
// define('DB_USER', 'uofruaju_margo_one');
// define('DB_PASS', 'ISI_PASSWORD_DB');

// Google OAuth 2.0 Credentials (Strict Linked-Only Mode)
define('GOOGLE_CLIENT_ID', 'ISI_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'ISI_GOOGLE_CLIENT_SECRET');
