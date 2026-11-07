<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/** Verify Google credentials; campus sessions still use the existing Api library. */
class Google_identity {
    public function client_id(): string
    {
        return trim(getenv('GOOGLE_CLIENT_ID') ?: '');
    }

    public function verify(string $token): array
    {
        if ($this->client_id() === '') throw new RuntimeException('Google Sign-In is not configured', 503);
        if (strlen($token) > 16384 || substr_count($token, '.') !== 2) throw new UnexpectedValueException('Invalid Google credential');
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), TRUE);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || strlen($header['kid']) > 255) {
            throw new UnexpectedValueException('Invalid Google credential');
        }
        $lava = lava_instance();
        $lava->call->library('cache');
        $name = 'google-jwks-' . hash('sha256', $this->client_id());
        $cached = $lava->cache->get($name);
        if (!is_array($cached) || ($cached['expires_at'] ?? 0) <= time()) {
            $cached = $this->fetch_keys();
            $lava->cache->write($cached, $name, max(1, $cached['expires_at'] - time()));
        }
        // A newly published kid can precede cache expiry. Bound refreshes to once
        // per minute so arbitrary token headers cannot cause unbounded downloads.
        $kid = $header['kid'] ?? null;
        $known = array_column($cached['jwks']['keys'] ?? [], 'kid');
        if (is_string($kid) && !in_array($kid, $known, TRUE) && ($cached['fetched_at'] ?? 0) <= time() - 60) {
            $cached = $this->fetch_keys();
            $lava->cache->write($cached, $name, max(1, $cached['expires_at'] - time()));
        }
        // Restrict every trusted key to RS256, regardless of token-supplied headers.
        $keys = [];
        foreach ($cached['jwks']['keys'] ?? [] as $key) {
            if (($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256') {
                $key['alg'] = 'RS256';
                $keys[] = $key;
            }
        }
        $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'RS256'));
        return self::validate_claims($claims, $this->client_id());
    }

    public static function validate_claims(array $claims, string $client_id): array
    {
        if (!in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], TRUE)
            || ($claims['aud'] ?? null) !== $client_id
            || (isset($claims['azp']) && $claims['azp'] !== $client_id)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > time()
            || ($claims['email_verified'] ?? null) !== TRUE
            || !is_string($claims['email'] ?? null) || strlen($claims['email']) > 255
            || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            || !is_string($claims['sub'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $claims['sub'])
            || !is_string($claims['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $claims['nonce'])) {
            throw new UnexpectedValueException('Invalid Google credential');
        }
        return $claims;
    }

    private function fetch_keys(): array
    {
        $body = '';
        $max_age = 300;
        $curl = curl_init('https://www.googleapis.com/oauth2/v3/certs');
        // Wamp's OpenSSL build may not ship a CA file. Use Windows' trusted
        // certificate store where supported, retaining peer/hostname checks.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => FALSE, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => TRUE, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$max_age) {
                if (preg_match('/^Cache-Control:.*?max-age=(\d+)/i', $line, $match)) $max_age = min(86400, (int) $match[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $jwks = json_decode($body, TRUE);
        if (!$ok || $status !== 200 || !is_array($jwks['keys'] ?? null) || !$jwks['keys']) {
            throw new RuntimeException('Google verification is temporarily unavailable', 503);
        }
        return ['jwks' => $jwks, 'fetched_at' => time(), 'expires_at' => time() + max(0, $max_age)];
    }
}
