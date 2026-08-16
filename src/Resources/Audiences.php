<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Audiences — named contact lists. Resend-compatible: a migrating app's
 * `audiences.*` calls map straight over. (MillionSend's dynamic-filter
 * `segments` are a separate, richer resource — see {@see Segments}.)
 */
final class Audiences
{
    public function __construct(private readonly HttpClient $http) {}

    /** @param array{name: string} $params @return array<mixed> */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/audiences', ['name' => $params['name']]);
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/audiences/' . rawurlencode($id));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/audiences', null, Util::listQuery($options));
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/audiences/' . rawurlencode($id));
    }
}
