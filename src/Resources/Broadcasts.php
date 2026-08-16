<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

final class Broadcasts
{
    private const WIRE_MAP = [
        'name' => 'name',
        'audienceId' => 'audience_id',
        'segmentId' => 'segment_id',
        'from' => 'from',
        'subject' => 'subject',
        'html' => 'html',
        'text' => 'text',
        'replyTo' => 'reply_to',
        'topicId' => 'topic_id',
    ];

    public function __construct(private readonly HttpClient $http) {}

    /** @param array<string,mixed> $params @return array<mixed> */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/broadcasts', Util::pick($params, self::WIRE_MAP) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/broadcasts/' . rawurlencode($id));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/broadcasts', null, Util::listQuery($options));
    }

    /** PATCH — draft only. @param array<string,mixed> $params @return array<mixed> */
    public function update(string $id, array $params): array
    {
        return $this->http->request(
            'PATCH',
            '/broadcasts/' . rawurlencode($id),
            Util::pick($params, self::WIRE_MAP) ?: new \stdClass(),
        );
    }

    /** DELETE — draft only. @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/broadcasts/' . rawurlencode($id));
    }

    /**
     * POST /broadcasts/:id/send — omit `scheduledAt` to send now.
     *
     * @param array{scheduledAt?: string} $options
     * @return array<mixed>
     */
    public function send(string $id, array $options = []): array
    {
        $body = Util::pick($options, ['scheduledAt' => 'scheduled_at']);

        return $this->http->request('POST', '/broadcasts/' . rawurlencode($id) . '/send', $body ?: new \stdClass());
    }

    /** POST /broadcasts/:id/cancel — scheduled only. @return array<mixed> */
    public function cancel(string $id): array
    {
        return $this->http->request('POST', '/broadcasts/' . rawurlencode($id) . '/cancel');
    }
}
