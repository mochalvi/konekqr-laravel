<?php

namespace KonekQR\Laravel;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An error answer of the Konek QR API (RFC 9457 problem+json). Switch on $errorCode.
 */
class KonekQRException extends RuntimeException
{
    /**
     * @param  array<int, array{field: string, code: string, message: string}>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $detail = '',
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(trim("{$status} {$errorCode}: {$detail}"), $status);
    }

    public static function fromResponse(Response $response): self
    {
        $problem = $response->json();
        if (! is_array($problem)) {
            $problem = ['detail' => mb_substr($response->body(), 0, 200)];
        }
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->status(),
            (string) ($problem['code'] ?? ($response->successful() ? 'invalid_response' : 'http_error')),
            (string) ($problem['detail'] ?? $problem['title'] ?? ''),
            is_array($problem['errors'] ?? null) ? $problem['errors'] : [],
            ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
