<?php

namespace KonekQR\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Client of the Konek QR REST API (/v1). Methods return the decoded JSON
 * objects of https://api.konekqr.web.id/openapi.yaml and throw KonekQRException on error answers.
 */
class Client
{
    public const VERSION = '0.1.0';

    public function __construct(
        private Factory $http,
        private string $apiKey,
        private string $baseUrl = 'https://api.konekqr.web.id',
        private string $language = 'id',
        private int $timeout = 30,
    ) {
        if (! preg_match('/^kq_(live|test)_[A-Z2-7]{32}$/', $apiKey)) {
            throw new InvalidArgumentException('Set KONEKQR_API_KEY to a Konek QR API key (kq_live_… or kq_test_…).');
        }
    }

    /**
     * Creates an invoice. The Idempotency-Key is $idempotencyKey, else the
     * referenceId, else a random UUID: pass your order id to make retries safe.
     * The same key within 24 hours replays the first invoice (not 409
     * reference_taken); it is returned as it is now, so check its 'status':
     * it may have been paid, cancelled or expired since.
     *
     * @param  array<string, mixed>  $params  amount, qrisId, description, referenceId, expiresInMinutes, returnUrl, metadata, expectedNmid, requireDetection
     * @return array<string, mixed> the invoice
     */
    public function createPayment(array $params, ?string $idempotencyKey = null): array
    {
        if (($params['metadata'] ?? null) === []) {
            $params['metadata'] = (object) []; // an empty PHP array would be sent as [], not {}
        }
        $key = $idempotencyKey ?? $params['referenceId'] ?? (string) Str::uuid();

        $response = $this->request('POST', '/v1/payments', ['json' => $params], ['Idempotency-Key' => $key]);
        $payment = $response->json()['payment'];
        if ($response->header('Idempotency-Replayed') === 'true') {
            try {
                return $this->getPayment($payment['id']);
            } catch (KonekQRException $e) {
                if ($e->errorCode !== 'insufficient_scope') { // a key without payments:read gets it as created
                    throw $e;
                }
            }
        }

        return $payment;
    }

    /** @return array<string, mixed> */
    public function getPayment(string $id): array
    {
        return $this->send('GET', '/v1/payments/'.rawurlencode($id))['payment'];
    }

    /**
     * @param  array{status?: string, referenceId?: string, before?: string, limit?: int}  $query
     * @return array<int, array<string, mixed>> newest first
     */
    public function listPayments(array $query = []): array
    {
        return $this->send('GET', '/v1/payments', ['query' => $query])['payments'];
    }

    /** @return array<string, mixed> */
    public function cancelPayment(string $id): array
    {
        return $this->send('POST', '/v1/payments/'.rawurlencode($id).'/cancel')['payment'];
    }

    /**
     * Marks a test invoice paid as the Listener would (test keys only).
     *
     * @return array<string, mixed>
     */
    public function simulatePayment(string $id): array
    {
        return $this->send('POST', '/v1/payments/'.rawurlencode($id).'/simulate')['payment'];
    }

    /** @return array<int, array<string, mixed>> */
    public function listQris(): array
    {
        return $this->send('GET', '/v1/qris')['qris'];
    }

    /** @return array<int, array<string, mixed>> */
    public function listDevices(): array
    {
        return $this->send('GET', '/v1/devices')['devices'];
    }

    /**
     * One page of account events after $after, oldest first. Store 'next'
     * and pass it as $after next time. $wait (0–30 s) long-polls.
     *
     * @param  array<int, string>  $types
     * @return array{events: array<int, array<string, mixed>>, next: ?string}
     */
    public function events(?string $after = null, array $types = [], int $wait = 0): array
    {
        $query = array_filter(['after' => $after, 'types' => implode(',', $types), 'wait' => $wait ?: null]);

        return $this->send('GET', '/v1/events', ['query' => $query], [], $this->timeout + $wait);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options = [], array $headers = [], ?int $timeout = null): array
    {
        return $this->request($method, $path, $options, $headers, $timeout)->json();
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $path, array $options = [], array $headers = [], ?int $timeout = null): Response
    {
        $response = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders(['Accept-Language' => $this->language, 'User-Agent' => 'konekqr-laravel/'.self::VERSION] + $headers)
            ->timeout($timeout ?? $this->timeout)
            ->send($method, $path, $options);
        if ($response->failed() || ! is_array($response->json())) {
            throw KonekQRException::fromResponse($response);
        }

        return $response;
    }
}
