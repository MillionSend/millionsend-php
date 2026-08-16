<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Contacts — addressable by id OR email (email wins when both are present), and
 * either audience-scoped (pass `audienceId`) or top-level.
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
     * @param array{audienceId?: string, email: string, firstName?: string, lastName?: string, unsubscribed?: bool, properties?: array<string,mixed>} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        $path = isset($params['audienceId'])
            ? '/audiences/' . rawurlencode((string) $params['audienceId']) . '/contacts'
            : '/contacts';

        return $this->http->request('POST', $path, Util::pick($params, self::CREATE_MAP) ?: new \stdClass());
    }

    /** @param string|array<string,mixed> $contact @return array<mixed> */
    public function get(string|array $contact): array
    {
        return $this->http->request('GET', self::path(self::normalize($contact)));
    }

    /**
     * PATCH — a null value clears a field; omit a key to leave it unchanged.
     *
     * @param array{id?: string, email?: string, audienceId?: string, firstName?: string|null, lastName?: string|null, unsubscribed?: bool, properties?: array<string,mixed>} $params
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

    /** @param array{audienceId?: string, limit?: int, after?: string, before?: string} $options @return array<mixed> */
    public function list(array $options = []): array
    {
        $path = isset($options['audienceId'])
            ? '/audiences/' . rawurlencode((string) $options['audienceId']) . '/contacts'
            : '/contacts';

        return $this->http->request('GET', $path, null, Util::listQuery($options));
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

    /** Email wins over id; audience-scoped when audienceId is set. @param array<string,mixed> $addr */
    private static function path(array $addr): string
    {
        $key = rawurlencode((string) ($addr['email'] ?? $addr['id'] ?? ''));

        return isset($addr['audienceId'])
            ? '/audiences/' . rawurlencode((string) $addr['audienceId']) . "/contacts/{$key}"
            : "/contacts/{$key}";
    }
}
