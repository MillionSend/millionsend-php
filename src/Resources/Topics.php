<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Subscription topics — granular unsubscribe categories for a team. */
final class Topics
{
    private const CREATE_MAP = [
        'name' => 'name',
        'description' => 'description',
        'defaultSubscription' => 'default_subscription',
    ];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * @param array{name: string, description?: string, defaultSubscription: string} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/topics', Util::pick($params, self::CREATE_MAP) ?: new \stdClass());
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/topics/' . rawurlencode($id));
    }

    /** GET /topics — a bare `{ data }` list (topics are unpaginated). @return array<mixed> */
    public function list(): array
    {
        return $this->http->request('GET', '/topics');
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/topics/' . rawurlencode($id));
    }
}
