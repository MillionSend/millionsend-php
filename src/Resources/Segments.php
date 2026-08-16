<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Dynamic segments — a saved filter over an audience's contacts (MillionSend
 * extension, no Resend equivalent, served at /segments2). `get` returns a live
 * `contact_count`.
 */
final class Segments
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * @param array{name: string, audienceId: string, filter: array<string,mixed>} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/segments2', Util::pick($params, [
            'name' => 'name',
            'audienceId' => 'audience_id',
            'filter' => 'filter',
        ]) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/segments2/' . rawurlencode($id));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/segments2', null, Util::listQuery($options));
    }

    /** @param array{name?: string, filter?: array<string,mixed>} $params @return array<mixed> */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/segments2/' . rawurlencode($id), Util::pick($params, [
            'name' => 'name',
            'filter' => 'filter',
        ]) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/segments2/' . rawurlencode($id));
    }
}
