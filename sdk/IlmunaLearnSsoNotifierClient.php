<?php
/**
 * ==============================================================================
 * MARGO ONE SSO — ILMUNA LEARN REAL-TIME NOTIFIER & REST CLIENT SDK (PHP 7.4+)
 * ==============================================================================
 * Klien resmi Margo One SSO untuk berkomunikasi dengan REST API & WebSocket Ticket
 * Ilmuna Learn secara Zero-Trust Kriptografis (Public Key + Private Key / RSA).
 *
 * Fitur Keamanan:
 *   - Public Key (`pk_sso_*`) + Private Key (`sk_sso_*` / RSA-2048 PEM)
 *   - Mode HMAC-SHA256, RSA-SHA256, dan DUAL (HMAC + RSA sekaligus)
 *   - Anti-Replay Timestamp + Nonce + Verifikasi Signed JSON Response
 *   - Browser pengguna TIDAK pernah menerima Private Key; hanya menerima
 *     Single-Use WebSocket Ticket (`wst_*`) yang terikat ke kelas/peran user.
 * ==============================================================================
 */

if (!class_exists('IlmunaLearnSsoNotifierClient')) {
    class IlmunaLearnSsoNotifierClient
    {
        /** @var string */
        private $endpointUrl;
        /** @var string */
        private $publicKey;
        /** @var string */
        private $privateKey;
        /** @var string */
        private $sigMode;
        /** @var string */
        private $rsaPrivatePem;
        /** @var string */
        private $siteHost;
        /** @var bool */
        private $verifyResponse;
        /** @var bool */
        private $sslVerify;
        /** @var int */
        private $timeout;

        public function __construct(array $config)
        {
            $this->endpointUrl    = trim((string)($config['endpoint_url'] ?? 'https://learn.ilmuna.my.id/api/sso-broadcast.php'));
            $this->publicKey      = trim((string)($config['public_key'] ?? ''));
            $this->privateKey     = trim((string)($config['private_key'] ?? ''));
            $this->sigMode        = strtoupper(trim((string)($config['sig_mode'] ?? 'HMAC-SHA256')));
            $this->rsaPrivatePem  = trim((string)($config['rsa_private_pem'] ?? ''));
            $this->siteHost       = trim((string)($config['site_host'] ?? ($_SERVER['HTTP_HOST'] ?? 'margo-one.ilmuna.my.id')));
            $this->verifyResponse = isset($config['verify_response']) ? (bool)$config['verify_response'] : true;
            $this->sslVerify      = isset($config['ssl_verify']) ? (bool)$config['ssl_verify'] : true;
            $this->timeout        = max(2, (int)($config['timeout'] ?? 10));
        }

        private function canonicalPath()
        {
            $path = parse_url($this->endpointUrl, PHP_URL_PATH);
            $base = basename(is_string($path) ? $path : 'sso-broadcast.php');
            $base = preg_replace('/\.php$/i', '', $base);
            return '/api/' . strtolower($base ?: 'sso-broadcast');
        }

        private function canonicalQuery(array $query)
        {
            $norm = [];
            foreach ($query as $k => $v) {
                $norm[(string)$k] = is_array($v) ? implode(',', array_map('strval', $v)) : (string)$v;
            }
            ksort($norm, SORT_STRING);
            return http_build_query($norm, '', '&', PHP_QUERY_RFC3986);
        }

        /**
         * Lakukan request HTTP ter-tanda tangan (HMAC-SHA256 / RSA-2048 / DUAL) ke Ilmuna Learn
         */
        public function request($resource = 'all', array $params = [], $method = 'GET', array $bodyData = [])
        {
            if ($this->publicKey === '' || $this->privateKey === '') {
                throw new RuntimeException('Public Key dan Private Key Learn API wajib diisi.');
            }
            if (!function_exists('curl_init')) {
                throw new RuntimeException('Ekstensi PHP cURL tidak tersedia.');
            }

            $method  = strtoupper(trim((string)$method)) === 'POST' ? 'POST' : 'GET';
            $query   = array_merge(['resource' => $resource], $params);
            $rawBody = ($method === 'POST' && !empty($bodyData))
                ? json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '';

            $ts        = (string)time();
            $nonce     = bin2hex(random_bytes(16));
            $canonical = implode("\n", [
                $method,
                $this->canonicalPath(),
                $this->canonicalQuery($query),
                $ts,
                $nonce,
                hash('sha256', (string)$rawBody),
            ]);

            $headers = [
                'Accept: application/json',
                'X-Ilmuna-Public-Key: ' . $this->publicKey,
                'X-Ilmuna-Timestamp: ' . $ts,
                'X-Ilmuna-Nonce: ' . $nonce,
                'X-Ilmuna-Site: ' . $this->siteHost,
            ];

            if ($this->sigMode === 'HMAC-SHA256' || $this->sigMode === 'DUAL') {
                $headers[] = 'X-Ilmuna-Signature: ' . hash_hmac('sha256', $canonical, $this->privateKey);
            }
            if ($this->sigMode === 'RSA-SHA256' || $this->sigMode === 'DUAL') {
                if ($this->rsaPrivatePem === '') {
                    throw new RuntimeException('Mode ' . $this->sigMode . ' memerlukan rsa_private_pem.');
                }
                $rsaSig = '';
                if (!@openssl_sign($canonical, $rsaSig, $this->rsaPrivatePem, OPENSSL_ALGO_SHA256)) {
                    throw new RuntimeException('Gagal menandatangani request dengan RSA-2048 Private PEM.');
                }
                $headers[] = 'X-Ilmuna-RSA-Signature: ' . base64_encode($rsaSig);
            }

            $url = $this->endpointUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $respHeaders = [];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(4, $this->timeout),
                CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
                CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_HEADERFUNCTION => function ($chObj, $line) use (&$respHeaders) {
                    $len   = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
                $headers[] = 'Content-Type: application/json; charset=utf-8';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $rawResp  = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($rawResp === false) {
                throw new RuntimeException('Gagal menghubungi Ilmuna Learn API: ' . $curlErr);
            }

            if ($this->verifyResponse && isset($respHeaders['x-ilmuna-response-signature'], $respHeaders['x-ilmuna-response-timestamp'])) {
                $rTs  = $respHeaders['x-ilmuna-response-timestamp'];
                $rSig = strtolower($respHeaders['x-ilmuna-response-signature']);
                $exp  = hash_hmac('sha256', $rTs . "\n" . $rawResp, $this->privateKey);
                if (!hash_equals($exp, $rSig)) {
                    throw new RuntimeException('Tanda tangan respon server tidak valid (X-Ilmuna-Response-Signature mismatch).');
                }
            }

            $decoded = json_decode($rawResp, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Respon API tidak valid (HTTP ' . $httpCode . ').');
            }

            if ($httpCode !== 200 || empty($decoded['success'])) {
                $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : ('HTTP Error ' . $httpCode);
                throw new RuntimeException($msg . ' [' . ($decoded['error']['code'] ?? $httpCode) . ']');
            }

            return $decoded['data'] ?? [];
        }

        /**
         * Ambil paket lengkap sesuai kelas pengguna SSO + tiket WebSocket real-time dalam 1 panggilan
         */
        public function getStudentFeedWithWebSocketTicket(array $identity)
        {
            $params = array_merge($identity, ['include_ws_ticket' => '1']);
            return $this->request('student_feed', $params, 'GET');
        }

        /**
         * Terbitkan tiket WebSocket (`wst_*`) untuk browser pengguna SSO yang sedang login
         */
        public function issueWebSocketTicket(array $identity, $ttl = 300)
        {
            $params = array_merge($identity, ['ttl' => (int)$ttl]);
            return $this->request('ws_ticket', $params, 'GET');
        }
    }
}
