<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Dynamic segments — a saved filter over the team's contacts (MillionSend
 * extension, no Resend equivalent). `get` returns a live `contact_count`.
 */
final class Segments
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * @param array{name: string, filter: array<string,mixed>} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/segments', Util::pick($params, [
            'name' => 'name',
            'filter' => 'filter',
        ]) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/segments/' . rawurlencode($id));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/segments', null, Util::listQuery($options));
    }

    /** @param array{name?: string, filter?: array<string,mixed>} $params @return array<mixed> */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/segments/' . rawurlencode($id), Util::pick($params, [
            'name' => 'name',
            'filter' => 'filter',
        ]) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/segments/' . rawurlencode($id));
    }
}
