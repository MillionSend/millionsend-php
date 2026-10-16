<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Per-inbox labels, up to 100. */
final class InboxLabels
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /inboxes/:id/labels — every label, oldest first (not paginated).
     *
     * @return array<mixed>
     */
    public function list(string $inboxId): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'labels'));
    }

    /**
     * POST /inboxes/:id/labels — `color` is `#RRGGBB`, random when omitted.
     *
     * @param array{name: string, color?: string} $params
     * @return array<mixed>
     */
    public function create(string $inboxId, array $params): array
    {
        return $this->http->request('POST', Util::path('inboxes', $inboxId, 'labels'), Util::body($params));
    }

    /**
     * @param array{name?: string, color?: string} $params
     * @return array<mixed>
     */
    public function update(string $inboxId, string $labelId, array $params): array
    {
        return $this->http->request('PATCH', Util::path('inboxes', $inboxId, 'labels', $labelId), Util::body($params));
    }

    /**
     * DELETE /inboxes/:id/labels/:labelId — also removes it from every thread.
     *
     * @return array<mixed>
     */
    public function remove(string $inboxId, string $labelId): array
    {
        return $this->http->request('DELETE', Util::path('inboxes', $inboxId, 'labels', $labelId));
    }
}
