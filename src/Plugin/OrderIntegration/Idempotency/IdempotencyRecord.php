<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Idempotency;

/**
 * Immutable snapshot of a completed mutating request, keyed by its
 * Idempotency-Key. Stored so an identical retry can be replayed verbatim
 * instead of re-executing the side effect (e.g. creating a second order).
 *
 * The response body is kept as the raw JSON string so replay is byte-for-byte
 * faithful (including 204 "null" bodies and field ordering).
 */
final class IdempotencyRecord
{
    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(
        public readonly string $key,
        public readonly string $bodyHash,
        public readonly int $statusCode,
        public readonly string $rawResponseBody,
        public readonly array $responseHeaders = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key'             => $this->key,
            'bodyHash'        => $this->bodyHash,
            'statusCode'      => $this->statusCode,
            'rawResponseBody' => $this->rawResponseBody,
            'responseHeaders' => $this->responseHeaders,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) $data['bodyHash'],
            (int) $data['statusCode'],
            (string) $data['rawResponseBody'],
            (array) ($data['responseHeaders'] ?? []),
        );
    }
}
