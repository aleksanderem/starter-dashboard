<?php
/**
 * HubSpot Forms: anti-spam decision logic.
 *
 * Pure functions with no WordPress calls, so they can be unit tested in
 * isolation (tests/test-antispam.php). The WordPress/Elementor glue lives in
 * class-antispam-guard.php.
 */

defined('ABSPATH') || exit;

class Starter_HubSpot_Antispam {

    const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const TURNSTILE_TOKEN_MAX_LENGTH = 2048;
    const TIMESTAMP_VERSION = 'v1';
    const KEY_MAX_LENGTH = 256;

    /**
     * Visitor IP. REMOTE_ADDR unless the site is explicitly configured as
     * sitting behind a trusted proxy (e.g. Cloudflare).
     *
     * Behind a trusted proxy: CF-Connecting-IP first, then the RIGHTMOST
     * X-Forwarded-For entry (the one appended by the proxy itself; entries to
     * its left are supplied by the client and can be forged).
     */
    public static function client_ip(array $server, $trust_proxy) {
        $remote = self::valid_ip($server['REMOTE_ADDR'] ?? '');
        if (!$trust_proxy) {
            return $remote;
        }

        $cloudflare = self::valid_ip($server['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cloudflare !== '') {
            return $cloudflare;
        }

        $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $rightmost = self::valid_ip(end($parts));
            if ($rightmost !== '') {
                return $rightmost;
            }
        }

        return $remote;
    }

    /**
     * Validated, normalized IP or '' for anything else (ports, garbage, overlong values).
     */
    public static function valid_ip($value) {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 45) {
            return '';
        }
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = @inet_pton($value);
        return $packed === false ? '' : inet_ntop($packed);
    }

    /**
     * Rate-limit subject for an IP. IPv6 is grouped by /64 because a single
     * client usually controls a whole /64.
     */
    public static function rate_limit_subject($ip) {
        $ip = self::valid_ip($ip);
        if ($ip === '' || strpos($ip, ':') === false) {
            return $ip;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) !== 16) {
            return $ip;
        }
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Sliding-window rate limit decision.
     *
     * @param array $hits   Unix timestamps of earlier accepted submissions.
     * @return array{allowed: bool, hits: int[]} hits = pruned list, plus $now when allowed.
     */
    public static function rate_limit_decide($hits, $now, $max, $window) {
        $max = (int) $max;
        $window = (int) $window;
        $hits = is_array($hits) ? $hits : [];
        if ($max <= 0 || $window <= 0) {
            return ['allowed' => true, 'hits' => []];
        }

        $recent = array_values(array_filter($hits, function ($hit) use ($now, $window) {
            return is_int($hit) && $hit > $now - $window && $hit <= $now;
        }));

        if (count($recent) >= $max) {
            return ['allowed' => false, 'hits' => $recent];
        }
        $recent[] = $now;
        return ['allowed' => true, 'hits' => $recent];
    }

    /**
     * Signed "form rendered at" token: v1.{unix time}.{hmac}.
     */
    public static function sign_timestamp($timestamp, $key) {
        $timestamp = (int) $timestamp;
        return self::TIMESTAMP_VERSION . '.' . $timestamp . '.' . self::timestamp_signature($timestamp, $key);
    }

    /**
     * Check a signed timestamp token.
     *
     * @return string 'ok' | 'missing' | 'invalid' | 'expired' | 'too-fast'
     */
    public static function verify_timestamp($token, $key, $now, $min_seconds, $max_age) {
        $token = trim((string) $token);
        if ($token === '') {
            return 'missing';
        }
        if (strlen($token) > 128 || !preg_match('/^v1\.(\d{1,12})\.([a-f0-9]{64})$/', $token, $match)) {
            return 'invalid';
        }
        $timestamp = (int) $match[1];
        if (!hash_equals(self::timestamp_signature($timestamp, $key), $match[2])) {
            return 'invalid';
        }
        // Small allowance for clock differences between servers
        if ($timestamp > $now + 60) {
            return 'invalid';
        }
        if ($now - $timestamp > (int) $max_age) {
            return 'expired';
        }
        if ($now - $timestamp < (int) $min_seconds) {
            return 'too-fast';
        }
        return 'ok';
    }

    private static function timestamp_signature($timestamp, $key) {
        return hash_hmac('sha256', self::TIMESTAMP_VERSION . '|' . (int) $timestamp, (string) $key);
    }

    /**
     * Verify a Turnstile token. Fails closed on anything other than an explicit
     * success from Cloudflare.
     *
     * @param callable $http_post function(string $url, array $body): ?array{code:int, body:string}
     *                            Returns null on network failure.
     * @return array{ok: bool, error: string}
     */
    public static function turnstile_verify($token, $secret, $remote_ip, callable $http_post) {
        $token = trim((string) $token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing-token'];
        }
        if (strlen($token) > self::TURNSTILE_TOKEN_MAX_LENGTH) {
            return ['ok' => false, 'error' => 'invalid-token'];
        }
        if (trim((string) $secret) === '') {
            return ['ok' => false, 'error' => 'not-configured'];
        }

        $body = ['secret' => $secret, 'response' => $token];
        if ($remote_ip !== '') {
            $body['remoteip'] = $remote_ip;
        }

        $response = $http_post(self::TURNSTILE_VERIFY_URL, $body);
        return self::turnstile_evaluate($response);
    }

    /**
     * @param ?array $response {code:int, body:string} or null on network failure.
     * @return array{ok: bool, error: string}
     */
    public static function turnstile_evaluate($response) {
        if (!is_array($response)) {
            return ['ok' => false, 'error' => 'network-error'];
        }
        if ((int) ($response['code'] ?? 0) !== 200) {
            return ['ok' => false, 'error' => 'http-' . (int) ($response['code'] ?? 0)];
        }
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data) || !array_key_exists('success', $data)) {
            return ['ok' => false, 'error' => 'bad-response'];
        }
        if ($data['success'] !== true) {
            $codes = isset($data['error-codes']) && is_array($data['error-codes'])
                ? implode(',', array_map('strval', $data['error-codes']))
                : '';
            $codes = preg_replace('/[^a-z0-9,\-]/', '', strtolower($codes));
            return ['ok' => false, 'error' => $codes !== '' ? $codes : 'rejected'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Turnstile site/secret keys: letters, digits, '-' and '_' only.
     */
    public static function sanitize_key($value) {
        $value = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $value));
        return substr($value, 0, self::KEY_MAX_LENGTH);
    }

    /**
     * Integer setting clamped to [min, max]; non-numeric input falls back to $default.
     */
    public static function sanitize_int($value, $default, $min, $max) {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            return (int) $default;
        }
        return max((int) $min, min((int) $max, (int) $value));
    }

    /**
     * Keyed, truncated IP hash for the rejection log: lets admins spot repeat
     * offenders without storing the address.
     */
    public static function ip_hash($ip, $key) {
        if ($ip === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'ip|' . $ip, (string) $key), 0, 12);
    }

    /**
     * Masked display value for a stored secret (last 4 characters only).
     */
    public static function mask_secret($secret) {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        $visible = strlen($secret) > 8 ? substr($secret, -4) : '';
        return str_repeat('•', 8) . $visible;
    }
}
