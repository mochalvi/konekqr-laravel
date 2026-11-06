<?php

namespace KonekQR\Laravel;

/**
 * Standard Webhooks verification for Konek QR webhooks.
 */
class Webhook
{
    /**
     * Verifies the signature of a webhook and returns its event.
     *
     * @param  string|array<int, string>  $secrets  whsec_… (several while rotating)
     * @param  string  $body  the raw request body
     * @return array<string, mixed>
     *
     * @throws WebhookException
     */
    public static function verify(string|array $secrets, string $id, string $timestamp, string $signatures, string $body, int $tolerance = 300, ?int $now = null): array
    {
        $now ??= time();
        if ($id === '' || ! ctype_digit($timestamp)) {
            throw new WebhookException('missing webhook-id or webhook-timestamp');
        }
        if (abs($now - (int) $timestamp) > $tolerance) {
            throw new WebhookException('webhook-timestamp is too far from the current time');
        }
        $given = [];
        foreach (explode(' ', $signatures) as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');
            if ($version === 'v1') {
                $given[] = $value;
            }
        }
        foreach ((array) $secrets as $secret) {
            $key = base64_decode(preg_replace('/^whsec_/', '', $secret), true);
            if ($key === false || $key === '') {
                throw new WebhookException('invalid webhook secret');
            }
            $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
            foreach ($given as $value) {
                if (hash_equals($expected, $value)) {
                    $event = json_decode($body, true);
                    if (! is_array($event)) {
                        throw new WebhookException('the body is not a JSON object');
                    }

                    return $event;
                }
            }
        }
        throw new WebhookException('no matching webhook-signature');
    }
}
