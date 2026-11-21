<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · ZERO-DEPENDENCY JWT RS256, JWKS & PKCE S256 ENGINE
 * ==============================================================================
 * Implementasi murni Native OpenSSL PHP 7.4 (ea-php74) hingga PHP 8.3:
 *   - Base64URL (RFC 4648 Section 5)
 *   - JWT Sign & Verify RS256 (RFC 7515 / RFC 7519)
 *   - JWK Set RFC 7517 Generator (RSA Public PEM -> {kty, use, kid, alg, n, e})
 *   - JWK -> RSA Public PEM Pure-PHP ASN.1 DER Converter (untuk klien / verifikasi)
 *   - PKCE S256 Generator & Constant-Time Verifier (RFC 7636)
 * ==============================================================================
 */

if (!function_exists('margo_base64url_encode')) {
    function margo_base64url_encode($data)
    {
        return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
    }
}

if (!function_exists('margo_base64url_decode')) {
    function margo_base64url_decode($data)
    {
        $s = strtr((string)$data, '-_', '+/');
        $rem = strlen($s) % 4;
        if ($rem > 0) {
            $s .= str_repeat('=', 4 - $rem);
        }
        $decoded = base64_decode($s, true);
        return ($decoded === false) ? false : $decoded;
    }
}

/**
 * Hitung PKCE S256 code_challenge dari code_verifier (RFC 7636)
 */
if (!function_exists('margo_pkce_challenge_s256')) {
    function margo_pkce_challenge_s256($codeVerifier)
    {
        return margo_base64url_encode(hash('sha256', (string)$codeVerifier, true));
    }
}

/**
 * Verifikasi PKCE S256 secara constant-time (RFC 7636: verifier 43-128 chars [A-Za-z0-9\-._~])
 */
if (!function_exists('margo_pkce_verify')) {
    function margo_pkce_verify($codeVerifier, $codeChallenge)
    {
        $codeVerifier  = (string)$codeVerifier;
        $codeChallenge = (string)$codeChallenge;
        if ($codeVerifier === '' || $codeChallenge === '') {
            return false;
        }
        $len = strlen($codeVerifier);
        if ($len < 43 || $len > 128) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9\-._~]+$/', $codeVerifier)) {
            return false;
        }
        $computed = margo_pkce_challenge_s256($codeVerifier);
        return hash_equals($codeChallenge, $computed);
    }
}

/**
 * Decode header & payload JWT tanpa verifikasi tanda tangan (untuk membaca kid)
 */
if (!function_exists('margo_jwt_decode_unverified')) {
    function margo_jwt_decode_unverified($jwt)
    {
        $parts = explode('.', (string)$jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $headerRaw  = margo_base64url_decode($parts[0]);
        $payloadRaw = margo_base64url_decode($parts[1]);
        if ($headerRaw === false || $payloadRaw === false) {
            return null;
        }
        $header  = json_decode($headerRaw, true);
        $payload = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }
        return [
            'header'  => $header,
            'payload' => $payload,
        ];
    }
}

/**
 * Tandatangani payload JWT menggunakan RSA-2048 SHA-256 (RS256)
 */
if (!function_exists('margo_jwt_sign_rs256')) {
    function margo_jwt_sign_rs256(array $payload, $privatePem, $kid)
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Ekstensi OpenSSL tidak tersedia untuk menandatangani JWT RS256.');
        }

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => (string)$kid,
        ];

        $headerJson  = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($headerJson === false || $payloadJson === false) {
            throw new RuntimeException('Gagal meng-encode header/payload JWT.');
        }

        $segments = [
            margo_base64url_encode($headerJson),
            margo_base64url_encode($payloadJson),
        ];
        $signingInput = $segments[0] . '.' . $segments[1];

        $signature = '';
        $pkey = @openssl_pkey_get_private((string)$privatePem);
        if ($pkey === false) {
            throw new RuntimeException('RSA Private Key PEM tidak valid untuk penandatanganan JWT.');
        }

        $ok = @openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('openssl_sign gagal menandatangani JWT RS256.');
        }

        return $signingInput . '.' . margo_base64url_encode($signature);
    }
}

/**
 * Helper internal ASN.1 DER length encoder (PHP 7.4+)
 */
if (!function_exists('margo_asn1_der_length')) {
    function margo_asn1_der_length($length)
    {
        $length = (int)$length;
        if ($length <= 0x7F) {
            return chr($length);
        }
        $temp = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($temp)) . $temp;
    }
}

/**
 * Helper internal ASN.1 DER INTEGER encoder
 */
if (!function_exists('margo_asn1_der_integer')) {
    function margo_asn1_der_integer($bytes)
    {
        $bytes = ltrim((string)$bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . margo_asn1_der_length(strlen($bytes)) . $bytes;
    }
}

/**
 * Konversi RSA JWK ({kty:'RSA', n:'...', e:'...'}) menjadi X.509 SubjectPublicKeyInfo PEM
 * agar klien dapat memverifikasi JWT RS256 langsung dari respon JSON /api/sso/jwks.php
 */
if (!function_exists('margo_jwk_to_pem')) {
    function margo_jwk_to_pem(array $jwk)
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return '';
        }
        $modulus  = margo_base64url_decode($jwk['n']);
        $exponent = margo_base64url_decode($jwk['e']);
        if ($modulus === false || $exponent === false) {
            return '';
        }

        $rsaSeqBody = margo_asn1_der_integer($modulus) . margo_asn1_der_integer($exponent);
        $rsaPublicKey = "\x30" . margo_asn1_der_length(strlen($rsaSeqBody)) . $rsaSeqBody;

        // BitString wrapping rsaPublicKey (leading 0x00 = unused bits)
        $bitString = "\x03" . margo_asn1_der_length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algoId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $spkiBody = $algoId . $bitString;
        $spki = "\x30" . margo_asn1_der_length(strlen($spkiBody)) . $spkiBody;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}

/**
 * Verifikasi JWT RS256 dengan RSA Public PEM atau array JWKS (RFC 7517).
 * Mengembalikan ['valid' => true, 'header' => [...], 'payload' => [...]]
 * atau ['valid' => false, 'code' => '...', 'message' => '...']
 */
if (!function_exists('margo_jwt_verify_rs256')) {
    function margo_jwt_verify_rs256($jwt, $publicPemOrJwks, $expectedIss = null, $expectedAud = null, $leeway = 60)
    {
        $jwt = trim((string)$jwt);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return ['valid' => false, 'code' => 'JWT_MALFORMED', 'message' => 'Format JWT tidak memiliki 3 segmen.'];
        }

        $headerJson  = margo_base64url_decode($parts[0]);
        $payloadJson = margo_base64url_decode($parts[1]);
        $signature   = margo_base64url_decode($parts[2]);

        if ($headerJson === false || $payloadJson === false || $signature === false) {
            return ['valid' => false, 'code' => 'JWT_BASE64_ERROR', 'message' => 'Segmen Base64URL JWT tidak valid.'];
        }

        $header  = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            return ['valid' => false, 'code' => 'JWT_JSON_ERROR', 'message' => 'Payload JSON JWT tidak valid.'];
        }

        // Strict algorithm enforcement (anti alg:none & HS256 confusion attack)
        if (($header['alg'] ?? '') !== 'RS256') {
            return ['valid' => false, 'code' => 'JWT_UNSUPPORTED_ALG', 'message' => 'Hanya algoritma RS256 yang diizinkan.'];
        }

        // Resolusi Public Key PEM (string PEM langsung atau JWK Set array)
        $publicPem = '';
        if (is_string($publicPemOrJwks)) {
            $publicPem = trim($publicPemOrJwks);
        } elseif (is_array($publicPemOrJwks)) {
            $keys = isset($publicPemOrJwks['keys']) && is_array($publicPemOrJwks['keys'])
                ? $publicPemOrJwks['keys']
                : $publicPemOrJwks;
            $kid = isset($header['kid']) ? (string)$header['kid'] : '';
            foreach ($keys as $k) {
                if (!is_array($k)) {
                    continue;
                }
                if ($kid !== '' && isset($k['kid']) && (string)$k['kid'] === $kid) {
                    $publicPem = !empty($k['public_pem']) ? (string)$k['public_pem'] : margo_jwk_to_pem($k);
                    break;
                }
            }
            // Fallback jika hanya ada 1 kunci dan kid cocok/kosong
            if ($publicPem === '' && $kid === '' && count($keys) === 1 && is_array($keys[0])) {
                $publicPem = !empty($keys[0]['public_pem']) ? (string)$keys[0]['public_pem'] : margo_jwk_to_pem($keys[0]);
            }
        }

        if ($publicPem === '') {
            return ['valid' => false, 'code' => 'JWT_KEY_NOT_FOUND', 'message' => 'Kunci publik (kid) penandatangan JWT tidak ditemukan.'];
        }

        $signingInput = $parts[0] . '.' . $parts[1];
        $verified = @openssl_verify($signingInput, $signature, $publicPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return ['valid' => false, 'code' => 'JWT_INVALID_SIGNATURE', 'message' => 'Verifikasi tanda tangan kriptografis RS256 gagal.'];
        }

        $now = time();
        $leeway = max(0, (int)$leeway);

        if (isset($payload['exp']) && ($now - $leeway) >= (int)$payload['exp']) {
            return ['valid' => false, 'code' => 'JWT_EXPIRED', 'message' => 'Token JWT telah kedaluwarsa.'];
        }
        if (isset($payload['nbf']) && ($now + $leeway) < (int)$payload['nbf']) {
            return ['valid' => false, 'code' => 'JWT_NOT_YET_VALID', 'message' => 'Token JWT belum aktif (nbf).'];
        }

        if ($expectedIss !== null && $expectedIss !== '') {
            $iss = rtrim((string)($payload['iss'] ?? ''), '/');
            $expIss = rtrim((string)$expectedIss, '/');
            if ($iss !== $expIss) {
                return ['valid' => false, 'code' => 'JWT_ISSUER_MISMATCH', 'message' => 'Issuer JWT tidak cocok.'];
            }
        }

        if ($expectedAud !== null && $expectedAud !== '') {
            $aud = isset($payload['aud']) ? $payload['aud'] : '';
            $audOk = is_array($aud)
                ? in_array((string)$expectedAud, $aud, true)
                : ((string)$aud === (string)$expectedAud);
            if (!$audOk) {
                return ['valid' => false, 'code' => 'JWT_AUDIENCE_MISMATCH', 'message' => 'Audience JWT tidak cocok dengan klien.'];
            }
        }

        return [
            'valid'   => true,
            'header'  => $header,
            'payload' => $payload,
        ];
    }
}

/**
 * Konversi daftar kunci aktif RSA Public PEM menjadi JWK Set standar RFC 7517
 * Format elemen $activeKeys: [['kid' => '...', 'public_pem' => '-----BEGIN PUBLIC KEY-----...'], ...]
 */
if (!function_exists('margo_jwt_build_jwks')) {
    function margo_jwt_build_jwks(array $activeKeys)
    {
        $jwks = [];
        foreach ($activeKeys as $row) {
            $kid = isset($row['kid']) ? trim((string)$row['kid']) : '';
            $pem = isset($row['public_pem']) ? trim((string)$row['public_pem']) : '';
            if ($kid === '' || $pem === '') {
                continue;
            }
            $res = @openssl_pkey_get_public($pem);
            if ($res === false) {
                continue;
            }
            $details = @openssl_pkey_get_details($res);
            if (!is_array($details) || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
                continue;
            }
            $jwks[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $kid,
                'alg' => 'RS256',
                'n'   => margo_base64url_encode($details['rsa']['n']),
                'e'   => margo_base64url_encode($details['rsa']['e']),
            ];
        }
        return ['keys' => $jwks];
    }
}
