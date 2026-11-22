<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1: sends a data message to one Android app install. Authenticates with the
 * Firebase service account (config services.fcm.credentials, base64 JSON from .env): a signed JWT is exchanged for
 * an OAuth access token, cached for 50 minutes.
 */
class FcmClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null
     */
    public static function credentials(): ?array
    {
        $raw = (string) config('services.fcm.credentials');
        $json = $raw === '' ? null : json_decode((string) base64_decode($raw, true), true);

        return is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key']) ? $json : null;
    }

    /**
     * Sends a data-only message (every value a string). Returns "ok", "unregistered" (the install is gone: forget
     * the token) or "failed".
     *
     * @param  array<string, string>  $data
     */
    public function send(string $token, array $data): string
    {
        $credentials = self::credentials() ?? throw new RuntimeException('FCM is not configured.');

        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send', [
                'message' => [
                    'token' => $token,
                    'data' => (object) $data,
                    'android' => ['priority' => 'high', 'ttl' => '86400s'],
                ],
            ]);

        if ($response->successful()) {
            return 'ok';
        }

        $status = (string) $response->json('error.status');
        $codes = collect((array) $response->json('error.details'))->pluck('errorCode')->filter()->all();

        return $response->status() === 404 || $status === 'NOT_FOUND' || in_array('UNREGISTERED', $codes, true)
            ? 'unregistered'
            : 'failed';
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm:access-token:'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $uri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64Url((string) json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $uri,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            $signature = '';
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM key could not sign.');
            }
            $segments[] = self::base64Url($signature);

            return (string) Http::asForm()->timeout(10)->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
