<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Webhook endpoints. `get` is the only call that returns the `signing_secret`. */
final class Webhooks
{
    private const WIRE_MAP = ['signingSecret' => 'signing_secret'];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /webhooks — omit `signingSecret` to have one generated.
     *
     * @param array{endpoint: string, events: list<string>, signingSecret?: string} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/webhooks', Util::body($params, self::WIRE_MAP));
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/webhooks/' . rawurlencode($id));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/webhooks', null, Util::listQuery($options));
    }

    /**
     * @param array{endpoint?: string, events?: list<string>, status?: string} $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/webhooks/' . rawurlencode($id), Util::body($params, self::WIRE_MAP));
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/webhooks/' . rawurlencode($id));
    }
}
