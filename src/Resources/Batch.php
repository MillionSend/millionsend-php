<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

final class Batch
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /emails/batch — 1..100 emails in one call, sent as a bare JSON array.
     * Supports an Idempotency-Key via $options['idempotencyKey'].
     *
     * @param list<array<string,mixed>> $params
     * @param array{idempotencyKey?: string} $options
     * @return array<mixed>
     */
    public function send(array $params, array $options = []): array
    {
        $body = array_map(
            static fn (array $email): array => Util::pick($email, Emails::WIRE_MAP),
            array_values($params),
        );

        return $this->http->request(
            'POST',
            '/emails/batch',
            $body,
            [],
            $options['idempotencyKey'] ?? null,
        );
    }

    /**
     * Alias of {@see send()}, mirroring Resend.
     *
     * @param list<array<string,mixed>> $params
     * @param array{idempotencyKey?: string} $options
     * @return array<mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->send($params, $options);
    }
}
