<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Contacts — team-global (one record per email, case-insensitive), addressable
 * by id OR email (email wins when both are present).
 */
final class Contacts
{
    public readonly ContactTopics $topics;

    private const CREATE_MAP = [
        'email' => 'email',
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'unsubscribed' => 'unsubscribed',
        'properties' => 'properties',
    ];

    private const UPDATE_MAP = [
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'unsubscribed' => 'unsubscribed',
        'properties' => 'properties',
    ];

    public function __construct(private readonly HttpClient $http)
    {
        $this->topics = new ContactTopics($http);
    }

    /**
     * POST /contacts — 409 validation_error when the email already exists on the team.
     *
     * @param array{email: string, firstName?: string, lastName?: string, unsubscribed?: bool, properties?: array<string,mixed>} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/contacts', Util::pick($params, self::CREATE_MAP) ?: new \stdClass());
    }

    /** @param string|array<string,mixed> $contact @return array<mixed> */
    public function get(string|array $contact): array
    {
        return $this->http->request('GET', self::path(self::normalize($contact)));
    }

    /**
     * PATCH — a null value clears a field; omit a key to leave it unchanged.
     *
     * @param array{id?: string, email?: string, firstName?: string|null, lastName?: string|null, unsubscribed?: bool, properties?: array<string,mixed>} $params
     * @return array<mixed>
     */
    public function update(array $params): array
    {
        return $this->http->request('PATCH', self::path($params), Util::pick($params, self::UPDATE_MAP) ?: new \stdClass());
    }

    /** @param string|array<string,mixed> $contact @return array<mixed> */
    public function remove(string|array $contact): array
    {
        return $this->http->request('DELETE', self::path(self::normalize($contact)));
    }

    /** @param array{limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/contacts', null, Util::listQuery($options));
    }

    /**
     * Convenience alias of `$contacts->topics->update(...)`.
     *
     * @param array{id?: string, email?: string, topics: list<array{id: string, subscription: string}>} $params
     * @return array<mixed>
     */
    public function updateTopics(array $params): array
    {
        return $this->topics->update($params);
    }

    /**
     * @param string|array<string,mixed> $contact
     * @return array<string,mixed>
     */
    private static function normalize(string|array $contact): array
    {
        return is_string($contact) ? ['id' => $contact] : $contact;
    }

    /** Email wins over id. @param array<string,mixed> $addr */
    private static function path(array $addr): string
    {
        return '/contacts/' . rawurlencode((string) ($addr['email'] ?? $addr['id'] ?? ''));
    }
}
