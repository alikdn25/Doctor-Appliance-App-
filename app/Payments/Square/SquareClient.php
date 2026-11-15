<?php

namespace App\Payments\Square;

use App\Payments\PaymentProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Square APIs used here (OAuth, merchants, locations, payment links, and reading customers,
 * invoices and orders for the import).
 * Credentials and environment come from config/services.php (.env); nothing is hard-coded.
 */
class SquareClient
{
    public function baseUrl(): string
    {
        return config('services.square.environment') === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.square.application_id')) && filled(config('services.square.application_secret'));
    }

    /**
     * @param  list<string>  $scopes
     */
    public function authorizationUrl(string $state, string $redirectUri, array $scopes): string
    {
        $query = [
            'client_id' => config('services.square.application_id'),
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ];

        // Production: always show the Square login, so the Owner picks the right account.
        if (config('services.square.environment') === 'production') {
            $query['session'] = 'false';
        }

        return $this->baseUrl().'/oauth2/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: string, merchant_id: string}
     */
    public function obtainToken(string $code, string $redirectUri): array
    {
        return $this->oauth([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: string, merchant_id: string}
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->oauth(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function revokeToken(string $accessToken): void
    {
        Http::withHeaders([
            'Authorization' => 'Client '.config('services.square.application_secret'),
            'Square-Version' => config('services.square.api_version'),
        ])
            ->acceptJson()
            ->timeout(15)
            ->post($this->baseUrl().'/oauth2/revoke', [
                'client_id' => config('services.square.application_id'),
                'access_token' => $accessToken,
            ])
            ->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function merchant(string $accessToken): array
    {
        return (array) $this->api($accessToken)->get('/v2/merchants/me')->throw()->json('merchant');
    }

    /**
     * @return array<string, mixed>
     */
    public function location(string $accessToken, string $locationId): array
    {
        return (array) $this->api($accessToken)->get('/v2/locations/'.rawurlencode($locationId))->throw()->json('location');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPaymentLink(string $accessToken, array $body): array
    {
        $response = $this->api($accessToken)->post('/v2/online-checkout/payment-links', $body);

        if ($response->failed()) {
            $this->fail('square.payment_link', $response);
        }

        return (array) $response->json('payment_link');
    }

    /**
     * Refunds (part of) a payment. Square answers PENDING and confirms with refund.updated webhooks.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function refundPayment(string $accessToken, array $body): array
    {
        $response = $this->api($accessToken)->post('/v2/refunds', $body);

        if ($response->failed()) {
            $this->fail('square.refund', $response);
        }

        return (array) $response->json('refund');
    }

    public function deletePaymentLink(string $accessToken, string $linkId): void
    {
        $this->api($accessToken)->delete('/v2/online-checkout/payment-links/'.rawurlencode($linkId))->throw();
    }

    /**
     * The permissions the token was granted (OAuth token status).
     *
     * @return list<string>
     */
    public function tokenScopes(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['Square-Version' => config('services.square.api_version')])
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/oauth2/token/status');

        if ($response->failed()) {
            $this->fail('square.token_status', $response);
        }

        return array_values(array_filter((array) $response->json('scopes'), 'is_string'));
    }

    /**
     * One page of the seller's customers.
     *
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function customers(string $accessToken, ?string $cursor = null): array
    {
        return $this->page($accessToken, '/v2/customers', array_filter(['limit' => 100, 'cursor' => $cursor]), 'customers', 'square.customers');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function locations(string $accessToken): array
    {
        $response = $this->api($accessToken)->get('/v2/locations');

        if ($response->failed()) {
            $this->fail('square.locations', $response);
        }

        return array_values((array) $response->json('locations'));
    }

    /**
     * One page of a location's invoices.
     *
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function invoices(string $accessToken, string $locationId, ?string $cursor = null): array
    {
        return $this->page($accessToken, '/v2/invoices', array_filter(['location_id' => $locationId, 'limit' => 200, 'cursor' => $cursor]), 'invoices', 'square.invoices');
    }

    /**
     * Orders by id (line items, taxes, tenders), up to 100 at a time.
     *
     * @param  list<string>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function orders(string $accessToken, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $response = $this->api($accessToken)->post('/v2/orders/batch-retrieve', ['order_ids' => array_values($orderIds)]);

        if ($response->failed()) {
            $this->fail('square.orders', $response);
        }

        return array_values((array) $response->json('orders'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    private function page(string $accessToken, string $path, array $query, string $key, string $context): array
    {
        $response = $this->api($accessToken)->get($path, $query);

        if ($response->failed()) {
            $this->fail($context, $response);
        }

        $cursor = $response->json('cursor');

        return ['items' => array_values((array) $response->json($key)), 'cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null];
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($accessToken)
            ->withHeaders(['Square-Version' => config('services.square.api_version')])
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token?: string, expires_at?: string, merchant_id: string}
     */
    private function oauth(array $grant): array
    {
        $response = Http::withHeaders(['Square-Version' => config('services.square.api_version')])
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/oauth2/token', [
                'client_id' => config('services.square.application_id'),
                'client_secret' => config('services.square.application_secret'),
                ...$grant,
            ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            $this->fail('square.oauth', $response);
        }

        /** @var array{access_token: string, refresh_token?: string, expires_at?: string, merchant_id: string} */
        return $response->json();
    }

    private function fail(string $context, Response $response): never
    {
        // Square error codes/details only; never tokens.
        Log::warning("Square API error ({$context})", ['status' => $response->status(), 'errors' => $response->json('errors') ?? $response->json('type')]);

        throw new PaymentProviderException(__('payments.square.errors.api'));
    }
}
