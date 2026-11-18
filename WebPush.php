<?php
/**
 * webpush-lite-php — Send Web Push notifications from PHP with zero dependencies.
 *
 * - Payload encryption: RFC 8291 (aes128gcm)
 * - Server identification: RFC 8292 (VAPID, ES256)
 * - Requires: PHP 8.1+, ext-openssl (with EC support), ext-curl
 *
 * @license MIT
 * @link    https://github.com/asiltechsoft/webpush-lite-php
 */

declare(strict_types=1);

namespace AsilTechSoft\WebPush;

final class WebPush
{
    /** @param string $publicKey  VAPID public key (base64url, 65-byte raw P-256 point)
     *  @param string $privateKey VAPID private key (PEM)
     *  @param string $subject    "mailto:you@example.com" or "https://your-site.com" */
    public function __construct(
        private string $publicKey,
        private string $privateKey,
        private string $subject,
    ) {
        if (!openssl_pkey_get_private($privateKey)) {
            throw new \InvalidArgumentException('Invalid VAPID private key (PEM expected).');
        }
        if (strlen(self::b64uDecode($publicKey)) !== 65) {
            throw new \InvalidArgumentException('Invalid VAPID public key (65-byte base64url expected).');
        }
    }

    /**
     * Generates a new VAPID key pair. Store both values; generate them only once.
     * @return array{public: string, private: string}
     */
    public static function generateVapidKeys(): array
    {
        $key = self::newKey();
        openssl_pkey_export($key, $pem);
        return ['public' => self::b64u(self::rawPublicKey($key)), 'private' => $pem];
    }

    /**
     * Sends a notification to one subscription.
     *
     * @param array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription
     *        The object returned by PushSubscription.toJSON() in the browser.
     * @param string $payload  Usually a JSON string your service worker understands.
     * @param int    $ttl      Seconds the push service keeps the message if the device is offline.
     * @param string $urgency  very-low | low | normal | high
     * @return Result
     */
    public function send(array $subscription, string $payload, int $ttl = 86400, string $urgency = 'normal'): Result
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        if (!preg_match('#^https://\S+$#', $endpoint)) {
            throw new \InvalidArgumentException('Subscription endpoint must be an https URL.');
        }
        $body = self::encrypt($payload, (string)($subscription['keys']['p256dh'] ?? ''), (string)($subscription['keys']['auth'] ?? ''));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->vapidHeader($endpoint),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . max(0, $ttl),
                'Urgency: ' . $urgency,
            ],
        ]);
        $response = (string)curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return new Result($status, $status === 0 ? $error : $response);
    }

    /** Builds the "Authorization: vapid t=..., k=..." header value for an endpoint. */
    public function vapidHeader(string $endpoint): string
    {
        $u = parse_url($endpoint);
        $claims = ['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => $this->subject];
        $data = self::b64u('{"typ":"JWT","alg":"ES256"}') . '.' . self::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($data, $der, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign VAPID token.');
        }
        return 'vapid t=' . $data . '.' . self::b64u(self::derToRaw($der)) . ', k=' . $this->publicKey;
    }

    /** Encrypts a payload for a subscription (RFC 8291, aes128gcm). Returns the raw request body. */
    public static function encrypt(string $payload, string $p256dh, string $auth): string
    {
        $uaPublic   = self::b64uDecode($p256dh);
        $authSecret = self::b64uDecode($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Invalid subscription keys.');
        }
        if (strlen($payload) > 3993) {
            throw new \LengthException('Payload too large (max 3993 bytes).');
        }

        $ephemeral = self::newKey();
        $asPublic  = self::rawPublicKey($ephemeral);
        $shared    = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($uaPublic)), $ephemeral, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH key agreement failed.');
        }

        $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /* ---------- helpers ---------- */

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            throw new \RuntimeException('OpenSSL could not create an EC key (P-256).');
        }
        return $key;
    }

    private static function rawPublicKey(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicPem(string $raw): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Converts a DER-encoded ECDSA signature to the 64-byte r||s form JWT expects. */
    private static function derToRaw(string $der): string
    {
        $o = 2;
        if (ord($der[1]) & 0x80) {
            $o += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$o + 1]);
            $v = substr($der, $o + 2, $len);
            $o += 2 + $len;
            $out .= str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }
}

/** Outcome of a send() call. */
final class Result
{
    public function __construct(public readonly int $status, public readonly string $body) {}

    /** The push service accepted the message (2xx). */
    public function ok(): bool { return $this->status >= 200 && $this->status < 300; }

    /** The subscription is gone (404/410): delete it from your database. */
    public function expired(): bool { return $this->status === 404 || $this->status === 410; }
}
