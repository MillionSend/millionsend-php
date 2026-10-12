<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Conversations in an inbox, each in one folder. */
final class InboxThreads
{
    private const WIRE_MAP = [
        'includeQuarantined' => 'include_quarantined',
        'hasAttachment' => 'has_attachment',
        'startDate' => 'start_date',
        'endDate' => 'end_date',
        'labelId' => 'label_id',
    ];

    public readonly InboxThreadEmails $emails;

    public function __construct(private readonly HttpClient $http)
    {
        $this->emails = new InboxThreadEmails($http);
    }

    /**
     * GET /inboxes/:id/threads — most recently active first. Filters: `folders`
     * and `labels` (lists, sent comma-separated), `read`, `includeQuarantined`.
     *
     * @param array<string,mixed> $options
     * @return array<mixed>
     */
    public function list(string $inboxId, array $options = []): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'threads'), null, Util::query($options, self::WIRE_MAP));
    }

    /**
     * GET /inboxes/:id/threads/search — subject, sender and recipients. Filters:
     * those of {@see list()} plus `query`, `from`/`to`/`cc`/`bcc` (lists),
     * `hasAttachment`, `startDate`, `endDate`.
     *
     * @param array<string,mixed> $options
     * @return array<mixed>
     */
    public function search(string $inboxId, array $options = []): array
    {
        return $this->http->request(
            'GET',
            Util::path('inboxes', $inboxId, 'threads', 'search'),
            null,
            Util::query($options, self::WIRE_MAP),
        );
    }

    /** @return array<mixed> */
    public function get(string $inboxId, string $threadId): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'threads', $threadId));
    }

    /**
     * PATCH /inboxes/:id/threads/:threadId — `read`, `folder`, `labelId`.
     *
     * @param array{read?: bool, folder?: string, labelId?: string, label_id?: string} $params
     * @return array<mixed>
     */
    public function update(string $inboxId, string $threadId, array $params): array
    {
        return $this->http->request(
            'PATCH',
            Util::path('inboxes', $inboxId, 'threads', $threadId),
            Util::body($params, self::WIRE_MAP),
        );
    }

    /**
     * DELETE /inboxes/:id/threads/:threadId — moves it to trash, purged after 30 days.
     *
     * @return array<mixed>
     */
    public function remove(string $inboxId, string $threadId): array
    {
        return $this->http->request('DELETE', Util::path('inboxes', $inboxId, 'threads', $threadId));
    }
}
