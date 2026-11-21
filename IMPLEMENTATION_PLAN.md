# 🗺️ IMPLEMENTATION PLAN — MARGO ONE (ILMUNA SSO)
**Subdomain Produksi:** `https://margo-one.ilmuna.my.id`  
**Workspace Lokal:** `C:\laragon\www\SSO`  
**Referensi Wajib:** [SSO_BLUEPRINT.md](file:///C:/laragon/www/SSO/SSO_BLUEPRINT.md) · [LEARN_README_DESIGN_REF.md](file:///C:/laragon/www/SSO/docs/LEARN_README_DESIGN_REF.md)

---

## 🎯 RINGKASAN KEPUTUSAN ARSITEKTUR
1. **100% 1 Tema dengan `learn.ilmuna.my.id` & WordPress `lumina-gold`**:
   - Aset CSS (`lumina-lms.css`, `ilmuna-unified-responsive.css`), `assets/js/alert.js` (`window.Ilmuna`), template modal/tabel/kalender, **logo resmi Margo One (`assets/images/logo-margo-one.png` / `landscape-logo-margo-one.png`)**, favicon, dan `gunungan.png` sudah tersedia di folder ini.
   - Halaman Auth (`login.php`, `authorize.php`, `gateway.php`) meniru 1:1 split-panel overlap Lumina Gold dari `C:\laragon\www\learn\login.php` & `gateway.php`.
   - Dashboard Admin Margo One menerapkan **Data-First Zero Title**, **Zero KPI Cards**, **Segmented Tabs** (`.curriculum-main-tabs`), **Solar Icons** (`solar:*`), **Zero Page Reload**, dan **Mobile 360px+**.
2. **Standar Runtime & Hosting**:
   - PHP Native **100% kompatibel PHP 7.4 (`ea-php74`) hingga PHP 8.3** (dilarang `match`, `?->`, union types, `readonly`, named args; fallback `mb_*`).
   - MySQL PDO InnoDB `utf8mb4_unicode_ci`, Timezone `Asia/Jakarta` (`+07:00`).
   - Isolasi `/api/sso/` dari Guardian Dome WAF HTML interstitial menggunakan `api/sso/.htaccess` + `api/sso/.user.ini` (`auto_prepend_file none`) + flag `ILMUNA_SKIP_GD_WAF_INTERSTITIAL`.
3. **Protokol Keamanan Zero-Trust**:
   - Browser Flow: **OAuth 2.0 Authorization Code + PKCE (`S256`) + `state` + `nonce`**.
   - Server-to-Server Flow: **Public Key + Private Key** (`HMAC-SHA256` / `RSA-2048` / `DUAL`) + Timestamp (±300s) + Single-Use Nonce + Signed Response (`X-Ilmuna-Response-Signature`).
   - Token & Sesi: **JWT `RS256`** (verifikasi publik via `/api/sso/jwks.php`) + **Refresh Token Family Rotation** (deteksi reuse otomatis mencabut seluruh family) + **Global Single Logout (SLO)**.

---

## 📋 TAHAPAN IMPLEMENTASI (PHASE-BY-PHASE)

### FASE 0 — Fondasi Infrastruktur, Database & Engine Kriptografi Core
**Target:** Proyek `C:\laragon\www\SSO` dapat dijalankan di Laragon, auto-migrasi seluruh tabel SSO, dan memiliki engine enkripsi/signing/JWT siap pakai.

- [x] **0.1 `config.php` & `database.php`**
  - Replikasi struktur `learn/config.php` (`APP_NAME = 'Margo One — Ilmuna SSO'`, `APP_DOMAIN = 'margo-one.ilmuna.my.id'`, `BASE_URL`, `BASE_PATH`, `renderFavicon()`, `renderIconifyScript()`, `loadCentralTemplates()`, guard `ILMUNA_SKIP_GD_WAF_INTERSTITIAL` untuk Guardian Dome WAF, konfigurasi Google OAuth 2.0).
  - PDO Singleton `getDBConnection()` dengan `SET time_zone = '+07:00'`.
- [x] **0.2 `.htaccess` & `deploy.php`**
  - Clean URLs (tanpa `.php`), passthrough header `HTTP_AUTHORIZATION`, proteksi file sensitif (`.env`, `.git`, `.ht*`), Guardian Dome prepend untuk halaman browser (kecuali folder `api/sso/`).
  - `deploy.php` untuk auto-sync Git di cPanel.
- [x] **0.3 `includes/sso-security.php`** *(adaptasi langsung `learn/includes/wp-api-security.php`)*
  - Auto-migrasi tabel: `sso_clients`, `sso_sessions`, `sso_auth_codes`, `sso_refresh_tokens`, `sso_nonces`, `sso_rate_limits`, `sso_audit_logs`, dan `sso_jwks_keys` (penyimpanan kunci RSA-2048 IdP terenkripsi AES-256-CBC+HMAC untuk menandatangani JWT `RS256`).
  - Auto-seed 2 klien awal bila tabel `sso_clients` masih kosong:
    1. `Ilmuna Learn LMS` (`https://learn.ilmuna.my.id`, redirect `https://learn.ilmuna.my.id/sso-callback`)
    2. `Portal WordPress Ilmuna` (`https://ilmuna.my.id`, redirect `https://ilmuna.my.id/?margo_one_callback=1`)
  - Enkripsi AES-256-CBC+HMAC at-rest, Canonical Request Signing (`HMAC-SHA256` / `RSA-2048` / `DUAL`), Anti-Replay Nonce, Domain/IP Allowlist, Rate Limiter, Signed Response, dan Audit Logger.
- [x] **0.4 `includes/sso-jwt.php`** *(Zero-Dependency Native OpenSSL PHP 7.4+)*
  - `margo_jwt_sign_rs256(array $payload, $privatePem, $kid)` — Base64URL encode + `openssl_sign(..., OPENSSL_ALGO_SHA256)`.
  - `margo_jwt_verify_rs256($jwt, $publicPem, $expectedIss, $expectedAud)` — verifikasi tanda tangan, `exp`, `nbf`, `iss`, `aud`.
  - `margo_jwt_build_jwks(array $activeKeys)` — konversi RSA Public PEM (`openssl_pkey_get_details`) menjadi standar RFC 7517 `{"keys":[{kty,use,kid,alg,n,e}]}`.
  - Helper PKCE S256: `margo_pkce_verify($codeVerifier, $codeChallenge)`.

**Definisi Selesai Fase 0:** Semua file lulus `php -l`; CLI test auto-migrasi tabel + generate/verify JWT RS256 + JWKS + PKCE S256 100% lulus.

---

### FASE 1 — Portal Autentikasi, App Launcher & Single Logout (Visual Lumina Gold 1:1)
**Target:** Seluruh alur browser pengguna selesai dengan tampilan 100% identik `learn/login.php` & `learn/gateway.php`.

- [x] **1.1 `auth.php`**
  - Adaptasi `learn/auth.php`:
    - `loginUser($identifier, $password)` (mendukung `username`, `email`, `email_google`, `nisn_nip` dengan placeholder unik anti-`HY093`).
    - `loginUserByGoogle($emailGoogle, $googleId)` (**ATURAN KETAT**: hanya izinkan jika `users.email_google` sudah terisi/linked — dilarang auto-register!).
    - Integrasi sesi global database `sso_sessions` (`margo_create_sso_session()`, `margo_validate_sso_session()`, `margo_revoke_sso_session()`).
    - Resolver identitas terpadu (`roles[]`, `primary_role`, `guru_id` anti-orphan `guru.id_user`, `siswa_id` + `nama_lengkap` + `id_kelas`).
- [x] **1.2 `login.php`**
  - Paritas visual 100% dengan `learn/login.php` (viewport lock `100dvh`, split-panel overlap, `#particleCanvas`, `.glow-orb`, `.page-transition`, tombol Masuk & Google OAuth, CSRF token, honeypot, rate-limit brute force login).
  - Konteks aplikasi dinamis: jika dipanggil dari `/authorize?client_id=...`, panel kiri menampilkan nama & ikon aplikasi tujuan (misal *"Masuk ke Ilmuna Learn"* atau *"Masuk ke Portal Ilmuna"*).
- [x] **1.3 `authorize.php`**
  - Validasi ketat `client_id` (`pk_sso_...`), `redirect_uri` (exact match whitelist), `response_type=code`, `code_challenge` + `code_challenge_method=S256`, `scope`, dan `state`.
  - Cek hak role user terhadap `client.allowed_roles`.
  - Jika belum login → simpan parameter di session terenkripsi & redirect ke `/login`.
  - Jika sudah login & sesi SSO valid → buat `sso_auth_codes` (sekali pakai, TTL 60 detik) → redirect 302 ke `redirect_uri?code=...&state=...`.
- [x] **1.4 `gateway.php`** *(App Launcher + Multi-Role Switcher)*
  - Paritas visual 100% dengan `learn/gateway.php`.
  - Menampilkan kartu aplikasi aktif (`Ilmuna Learn`, `Portal ilmuna.my.id`, `Panel Admin Margo One`) sesuai role pengguna + status sesi aktif.
- [x] **1.5 `profile.php` & `logout.php`**
  - `profile.php`: ubah password terpusat, tautkan/putus akun Google (`users.email_google`), lihat & cabut sesi perangkat aktif.
  - `logout.php`: **Global Single Logout (SLO)** — tandai `sso_sessions.revoked_at = NOW()`, cabut `sso_refresh_tokens`, kirim back-channel webhook bertanda tangan ke seluruh `sso_clients.logout_webhook_url`, bersihkan cookie/session, dan redirect aman ke `post_logout_redirect_uri` (whitelist).

**Definisi Selesai Fase 1:** Login lokal, Google OAuth, OAuth2 `/authorize` → `code`, App Launcher `/gateway`, dan Global Logout bekerja mulus di desktop & mobile 360px+.

---

### FASE 2 — Server-to-Server OAuth2/OIDC API (`api/sso/`)
**Target:** Endpoint machine-to-machine bebas Guardian Dome WAF dan terproteksi penuh Public/Private Key.

- [x] **2.1 Isolasi WAF Folder `api/sso/`**
  - Buat `api/sso/.htaccess` (`php_value auto_prepend_file none` di `lsapi_module` & `php7_module`) + `api/sso/.user.ini` (`auto_prepend_file = none`).
  - Setiap file PHP di `api/sso/` mendefinisikan `define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);` sebelum `require_once '../../config.php';`.
- [x] **2.2 `api/sso/token.php`** (`POST`)
  - Autentikasi klien wajib via `sso-security.php` (Public/Private Key Signature + Timestamp + Nonce + Domain/IP + Rate Limit).
  - Dukung `grant_type=authorization_code`: verifikasi `code_hash`, `used_at IS NULL`, belum expired, `client_id`, `redirect_uri`, dan PKCE `S256` (`code_verifier`) → tandai `used_at = NOW()` secara atomik → terbitkan `id_token` (JWT `RS256`), `access_token`, `refresh_token` (family baru), & payload `user` bebas data sensitif.
  - Dukung `grant_type=refresh_token`: verifikasi token hash; **jika `rotated_at IS NOT NULL` (reuse terdeteksi!) → cabut seluruh `family_id` & kembalikan `401 TOKEN_REUSE_DETECTED`**; jika valid → rotasi token baru & kembalikan JWT baru.
  - Sertakan `X-Ilmuna-Response-Timestamp` & `X-Ilmuna-Response-Signature`.
- [x] **2.3 `api/sso/userinfo.php`** (`GET`)
  - Validasi Bearer `access_token` (atau JWT) + cek `sso_sessions.revoked_at IS NULL` → kembalikan profil terkini + `roles[]`, `guru_id`, `siswa_id`, `kelas_id`.
- [x] **2.4 `api/sso/introspect.php`** (`POST`)
  - Memungkinkan `learn.ilmuna.my.id` / `ilmuna.my.id` mengecek real-time apakah `session_id` / `access_token` masih aktif (`{"active":true/false}`).
- [x] **2.5 `api/sso/jwks.php`** (`GET`)
  - Mengembalikan RSA-2048 Public Key aktif dalam format JWK Set standar (`Cache-Control: public, max-age=3600`).

**Definisi Selesai Fase 2:** Full CLI test suite memverifikasi PKCE S256, replay code/nonce blocked, bad signature blocked, refresh rotation + reuse family revocation, dan validasi JWT via `jwks.php`.

---

### FASE 3 — Dashboard Admin Margo One (Data-First Lumina Gold)
**Target:** Administrator dapat mengelola seluruh ekosistem SSO tanpa menyentuh database secara manual dan tanpa page reload.

- [x] **3.1 Shell Layout Admin (`includes/header.php`, `footer.php`, `sidebar.php`, `menu.php`)**
  - Disalin & dirampingkan dari `learn/includes/` khusus menu Admin Margo One (Iconify Solar Icons, `window.Ilmuna` alert.js, PWA head).
- [x] **3.2 `dashboard/admin/index.php` (atau panel terpadu dengan Segmented Tabs `.curriculum-main-tabs`)**
  - **Tab 1 — Aplikasi Klien (`sso_clients`)**: Tambah/edit klien, salin **Public Key** (`pk_sso_...`), lihat/salin **Private Key** (`sk_sso_...`), **Rotasi Kunci**, **Generate RSA-2048 PEM** (tampil sekali), atur `sig_mode` (`HMAC-SHA256` / `RSA-SHA256` / `DUAL`), `redirect_uris`, `logout_webhook_url`, whitelist domain/IP, rate limit, toggle Aktif/Nonaktif, dan **1-Klik Uji Koneksi Signed Token**.
  - **Tab 2 — Sesi Aktif Global (`sso_sessions`)**: Lihat siapa sedang login, metode login (`password` / `google_oauth`), IP, perangkat, tombol **Paksa Keluar (Force Logout)** per sesi maupun **Cabut Semua Sesi User**.
  - **Tab 3 — Manajemen Identitas & Multi-Role (`users`, `roles`, `user_roles`)**: Pencarian cepat akun, cek status tautan Google SSO (`email_google`), reset password aman, kelola peran lintas aplikasi, deteksi orphan `guru.id_user`.
  - **Tab 4 — Kunci Penandatangan JWT (JWKS) & Audit Keamanan (`sso_jwks_keys`, `sso_audit_logs`)**: Status RSA-2048 IdP + tombol Rotasi Kunci JWT (dengan grace period overlap kunci lama agar token aktif tidak langsung putus) + tabel log audit 100 event terakhir + tombol unduh SDK klien.

**Definisi Selesai Fase 3:** Nol page reload untuk semua operasi CRUD, lulus audit Data-First (zero H1 hero, zero KPI cards), nyaman di layar HP 360px.

---

### FASE 4 — Client SDK & Integrasi Siap Pasang (`learn` + WordPress)
**Target:** Integrasi plug-and-play dengan zero downtime dan jalur login darurat lokal tetap aman.

- [x] **4.1 `sdk/ilmuna-sso-client.php`** *(Drop-in PHP 7.4+ SDK)*
  - Helper `buildAuthorizeUrl()` (otomatis generate `state` + PKCE `code_verifier`/`code_challenge`), `exchangeCode($code, $state)` (signed `HMAC`/`RSA`/`DUAL` + verifikasi `X-Ilmuna-Response-Signature` + verifikasi JWT `RS256` dengan JWKS cache), `refreshAccessToken()`, `handleLogoutWebhook()`.
- [x] **4.2 Template Integrasi `learn.ilmuna.my.id` (`sdk/learn-integration/`)**
  - `sso-callback.php` yang memanggil `establishUserSession($pdo, $user)` di `learn/auth.php` secara mulus.
  - `sso-logout-webhook.php` untuk menerima SLO dari Margo One.
  - Instruksi feature flag `ILMUNA_SSO_ENABLED` (default `false` sampai diuji penuh) + tombol *"Masuk dengan Margo One"* di `learn/login.php` + login darurat admin (`?local_admin=1`).
- [x] **4.3 Plugin / Addon WordPress `ilmuna.my.id` (`sdk/margo-one-wp-sso/margo-one-wp-sso.php`)**
  - Pengaturan WP Admin (`Settings → Margo One SSO`): SSO URL (`https://margo-one.ilmuna.my.id`), Public Key, Private Key, pemetaan role (`admin` → `administrator`, `guru` → `editor`, `siswa` → `subscriber`).
  - Tombol **"Masuk dengan Margo One"** di `wp-login.php` & shortcode `[margo_one_login_button]` bertema 100% **`lumina-gold`** (`z-index: 60 !important; isolation: isolate !important;`, solid opaque, Light & Dark Mode).
  - Handler callback `/?margo_one_callback=1` + back-channel SLO webhook.

---

### FASE 5 — Matriks Verifikasi Otomatis End-to-End
Sebelum dinyatakan selesai, jalankan satu skrip uji CLI komprehensif yang memverifikasi seluruh 12 skenario:
1. `php -l` nol error pada 100% berkas PHP (plus verifikasi nol sintaks PHP 8-only).
2. Auto-migrasi skema & seed klien default (`learn.ilmuna.my.id`, `ilmuna.my.id`) + RSA-2048 JWKS key.
3. Alur lengkap `/authorize` → `authorization_code` → `POST /api/sso/token.php` dengan PKCE `S256` valid → `200 OK` + JWT `RS256` terverifikasi via `jwks.php`.
4. PKCE `code_verifier` salah → ditolak `400 INVALID_PKCE`.
5. `authorization_code` dipakai 2 kali (replay) → ditolak `400 CODE_ALREADY_USED`.
6. Signature `HMAC-SHA256` / `RSA-2048` / `DUAL` salah → ditolak `401`.
7. Timestamp selisih >300s → ditolak `401 TIMESTAMP_EXPIRED`.
8. Nonce dipakai ulang → ditolak `401 REPLAY_DETECTED`.
9. Domain / `redirect_uri` tidak terdaftar di whitelist → ditolak `403`.
10. Refresh Token Rotation berhasil + pemakaian ulang token lama langsung mencabut seluruh token family (`401 TOKEN_REUSE_DETECTED`).
11. Global Single Logout (SLO) mencabut sesi di DB → `introspect.php` langsung mengembalikan `{"active":false}`.
12. Audit payload `userinfo.php` & `id_token` → nol kebocoran PII sensitif (`password_hash`, `alamat`, `no_hp`, `private_key`).

---

### FASE 6 — Checklist Deployment Produksi (`margo-one.ilmuna.my.id`)
1. Buat subdomain `margo-one.ilmuna.my.id` di cPanel (SSL AutoSSL HTTPS aktif, PHP `ea-php74` atau `ea-php81+`, ekstensi `pdo_mysql`, `openssl`, `curl`, `mbstring`).
2. Hubungkan ke database identitas terpadu (`uofruaju_learn_ilmuna` atau DB SSO khusus sesuai kebijakan hosting).
3. Push ke repositori Git Margo One & jalankan `https://margo-one.ilmuna.my.id/deploy.php?secret=...`.
4. Verifikasi `https://margo-one.ilmuna.my.id/api/sso/jwks.php` mengembalikan JSON murni (bukan HTML Guardian Dome WAF).
5. Login Admin Margo One → salin Public & Private Key untuk `learn.ilmuna.my.id` → uji login end-to-end dengan `ILMUNA_SSO_ENABLED = true`.
6. Pasang plugin `margo-one-wp-sso.zip` di WordPress `ilmuna.my.id` → uji login 1 klik lintas `margo-one` ↔ `learn` ↔ `ilmuna` + uji Single Logout!
