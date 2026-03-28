<?php
/**
 * MoneroToken — HMAC-signed ephemeral payment tokens.
 *
 * Packs payment details (cart ID, subaddress index, XMR amount, timestamp)
 * into a JSON payload, signs it with HMAC-SHA256, and returns a base64 blob.
 * The token lives only in browser JS memory — never in cookies, DB, or files.
 *
 * The HMAC key is auto-generated at module install and stored in
 * ps_configuration. It protects against token tampering in transit
 * (MITM, XSS, browser extensions). It does NOT protect data at rest
 * because there is no data at rest.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneroToken
{
    /** @var string|null Cached key to avoid repeated DB reads within a request */
    private static $cachedKey = null;

    /**
     * Read the HMAC signing key from PrestaShop configuration.
     * Auto-generated during module install (bin2hex(random_bytes(32))).
     *
     * @return string Raw binary key (32 bytes)
     * @throws RuntimeException If no key is configured
     */
    public static function getKey()
    {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }

        $hexKey = Configuration::get('MONERO_HMAC_KEY');
        if (empty($hexKey)) {
            throw new RuntimeException('MONERO_HMAC_KEY is not configured. Reinstall the module.');
        }

        self::$cachedKey = hex2bin($hexKey);
        return self::$cachedKey;
    }

    /**
     * Check whether the HMAC key is available.
     *
     * @return bool
     */
    public static function isKeyAvailable()
    {
        $hexKey = Configuration::get('MONERO_HMAC_KEY');
        return !empty($hexKey) && strlen($hexKey) === 64;
    }

    /**
     * Create a signed token embedding payment details.
     *
     * Token format: base64( HMAC_32bytes + JSON_payload )
     *
     * The payload is readable (just base64), but cannot be tampered with
     * because the HMAC signature would no longer match.
     *
     * @param int    $cartId          PrestaShop cart ID
     * @param int    $subaddrIndex    Wallet subaddress index to monitor
     * @param string $xmrAmountAtomic Expected XMR in atomic units (piconero)
     * @param int    $timestamp       Token creation time (unix timestamp)
     * @return string Base64-encoded signed token
     */
    public static function create($cartId, $subaddrIndex, $xmrAmountAtomic, $timestamp)
    {
        $payload = json_encode([
            'cart_id' => (int) $cartId,
            'index'   => (int) $subaddrIndex,
            'amount'  => (string) $xmrAmountAtomic,
            'ts'      => (int) $timestamp,
        ]);

        // 32-byte raw HMAC signature
        $signature = hash_hmac('sha256', $payload, self::getKey(), true);

        return base64_encode($signature . $payload);
    }

    /**
     * Verify a token's signature and expiry.
     *
     * @param string $token Base64-encoded signed token from the browser
     * @return array|false  Decoded payload array, or false if invalid/expired
     */
    public static function verify($token)
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false || strlen($decoded) <= 32) {
            return false;
        }

        // Split: first 32 bytes = HMAC signature, rest = JSON payload
        $receivedSig = substr($decoded, 0, 32);
        $payload = substr($decoded, 32);

        // Recompute and compare (timing-safe)
        $expectedSig = hash_hmac('sha256', $payload, self::getKey(), true);
        if (!hash_equals($expectedSig, $receivedSig)) {
            return false;
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['cart_id'], $data['index'], $data['amount'], $data['ts'])) {
            return false;
        }

        // Check expiry
        $ttl = (int) Configuration::get('MONERO_TOKEN_TTL');
        if ($ttl <= 0) {
            $ttl = 1800; // default 30 minutes
        }

        if (time() - (int) $data['ts'] > $ttl) {
            return false;
        }

        return $data;
    }
}
