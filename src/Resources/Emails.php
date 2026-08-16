<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

final class Emails
{
    /** Send-payload camelCase => wire snake_case. Shared with {@see Batch}. */
    public const WIRE_MAP = [
        'from' => 'from',
        'to' => 'to',
        'subject' => 'subject',
        'html' => 'html',
        'text' => 'text',
        'cc' => 'cc',
        'bcc' => 'bcc',
        'replyTo' => 'reply_to',
        'scheduledAt' => 'scheduled_at',
        'tags' => 'tags',
    ];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /emails — supports an Idempotency-Key via $options['idempotencyKey'].
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string} $options
     * @return array<mixed>
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request(
            'POST',
            '/emails',
            Util::pick($params, self::WIRE_MAP) ?: new \stdClass(),
            [],
            $options['idempotencyKey'] ?? null,
        );
    }

    /**
     * Alias of {@see send()}, mirroring Resend.
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string} $options
     * @return array<mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->send($params, $options);
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/emails/' . rawurlencode($id));
    }

    /** POST /emails/:id/cancel — only scheduled, unsent emails. @return array<mixed> */
    public function cancel(string $id): array
    {
        return $this->http->request('POST', '/emails/' . rawurlencode($id) . '/cancel');
    }
}
