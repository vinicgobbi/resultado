<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Web Push sender using VAPID authentication.
 *
 * Messages are sent without payload, so no content encryption is needed:
 * the service worker wakes up and fetches its waiting messages by itself.
 */
class WebPush
{
    public static function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /**
     * Generate a new P-256 key pair.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false || ! openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('Não foi possível gerar as chaves VAPID.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Não foi possível ler a chave VAPID gerada.');
        }
        $publicKey = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [
            'public_key' => self::base64Url($publicKey),
            'private_key' => base64_encode($privatePem),
        ];
    }

    /**
     * Wake the given subscriptions up, removing expired ones. Each service
     * worker then fetches its own waiting messages.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return int number of subscriptions that accepted the push
     */
    public function send(Collection $subscriptions, int $ttl = 600): int
    {
        if (! self::isConfigured() || $subscriptions->isEmpty()) {
            return 0;
        }

        $tokens = [];

        foreach ($subscriptions as $subscription) {
            $audience = $this->audience($subscription->endpoint);
            $tokens[$audience] ??= $this->vapidToken($audience);
        }

        $responses = Http::pool(fn (Pool $pool): array => $subscriptions
            ->map(fn (PushSubscription $subscription) => $pool->as((string) $subscription->id)
                ->connectTimeout(5)
                ->timeout(10)
                ->withHeaders([
                    'TTL' => (string) $ttl,
                    'Urgency' => 'high',
                    'Authorization' => sprintf(
                        'vapid t=%s, k=%s',
                        $tokens[$this->audience($subscription->endpoint)],
                        config('services.webpush.public_key'),
                    ),
                ])
                ->withBody('', 'application/octet-stream')
                ->post($subscription->endpoint))
            ->all(), concurrency: 20);

        $expired = [];
        $delivered = 0;

        foreach ($responses as $id => $response) {
            if (! $response instanceof Response) {
                continue;
            }

            if ($response->successful()) {
                $delivered++;
            } elseif (in_array($response->status(), [404, 410], true)) {
                $expired[] = (int) $id;
            }
        }

        PushSubscription::query()->whereKey($expired)->delete();

        return $delivered;
    }

    private function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Endpoint de push inválido: {$endpoint}");
        }

        return "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
    }

    /**
     * Signed ES256 JSON Web Token for the given push service origin.
     */
    private function vapidToken(string $audience): string
    {
        $header = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'aud' => $audience,
            'exp' => now()->addHours(12)->getTimestamp(),
            'sub' => config('services.webpush.subject'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $privateKey = openssl_pkey_get_private((string) base64_decode((string) config('services.webpush.private_key')));

        if ($privateKey === false || ! openssl_sign("{$header}.{$claims}", $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Chave privada VAPID inválida.');
        }

        return "{$header}.{$claims}.".self::base64Url(self::derToRawSignature($signature));
    }

    /**
     * Convert an ASN.1 DER ECDSA signature into the 64 byte r||s form used by JWS.
     */
    private static function derToRawSignature(string $der): string
    {
        $offset = 2;
        $integers = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integers[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $integers[0].$integers[1];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
