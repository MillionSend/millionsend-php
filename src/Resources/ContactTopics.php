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
     * Two shapes: resend-php's `update($idOrEmail, $topics)` where `$topics` is
     * the list itself (or `['topics' => $list]`), or a single array carrying the
     * address (`email` wins over `id`) alongside `topics`.
     *
     * @param string|array{id?: string, email?: string, topics: list<array{id: string, subscription: string}>} $contact
     * @param list<array{id: string, subscription: string}>|array{topics: list<array{id: string, subscription: string}>} $topics
     * @return array<mixed>
     */
    public function update(string|array $contact, array $topics = []): array
    {
        if (is_array($contact)) {
            $topics = $contact['topics'] ?? [];
            $contact = (string) ($contact['email'] ?? $contact['id'] ?? '');
        }
        $list = $topics['topics'] ?? $topics;

        return $this->http->request('PATCH', '/contacts/' . rawurlencode($contact) . '/topics', array_values($list));
    }
}
