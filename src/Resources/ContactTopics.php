<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;

/** Per-contact topic subscriptions (opt in/out of a topic). */
final class ContactTopics
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * PATCH /contacts/:idOrEmail/topics — body is the bare subscription array.
     * Address by `email` (wins) or `id`.
     *
     * @param array{id?: string, email?: string, topics: list<array{id: string, subscription: string}>} $params
     * @return array<mixed>
     */
    public function update(array $params): array
    {
        $key = rawurlencode((string) ($params['email'] ?? $params['id'] ?? ''));

        return $this->http->request('PATCH', "/contacts/{$key}/topics", array_values($params['topics'] ?? []));
    }
}
