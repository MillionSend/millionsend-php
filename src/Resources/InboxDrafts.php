<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Drafts, standalone or replies; some wait for a person to approve them. */
final class InboxDrafts
{
    private const WIRE_MAP = ['threadId' => 'thread_id', 'replyToEmailId' => 'reply_to_email_id'];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /inboxes/:id/drafts — unsent drafts, most recently updated first.
     *
     * @param array{limit?: int, after?: string, before?: string} $options
     * @return array<mixed>
     */
    public function list(string $inboxId, array $options = []): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'drafts'), null, Util::listQuery($options));
    }

    /**
     * POST /inboxes/:id/drafts — `to`, `cc`, `bcc`, `subject`, `html`, `text`, and
     * for a reply draft both `threadId` and `replyToEmailId`. A second reply draft
     * for the same email returns the first.
     *
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function create(string $inboxId, array $params): array
    {
        return $this->http->request('POST', Util::path('inboxes', $inboxId, 'drafts'), Util::body($params, self::WIRE_MAP));
    }

    /** @return array<mixed> */
    public function get(string $inboxId, string $draftId): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'drafts', $draftId));
    }

    /**
     * PATCH /inboxes/:id/drafts/:draftId — a `null` value clears the field.
     *
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function update(string $inboxId, string $draftId, array $params): array
    {
        return $this->http->request('PATCH', Util::path('inboxes', $inboxId, 'drafts', $draftId), Util::body($params, self::WIRE_MAP));
    }

    /** @return array<mixed> */
    public function remove(string $inboxId, string $draftId): array
    {
        return $this->http->request('DELETE', Util::path('inboxes', $inboxId, 'drafts', $draftId));
    }

    /**
     * POST /inboxes/:id/drafts/:draftId/send — no body. Held again as a draft
     * (`kind` `draft`, HTTP 202) when the rules ask for approval. A key restricted
     * to the inbox cannot send a draft that waits for approval (`approval_required`).
     *
     * @param array{idempotencyKey?: string, idempotency_key?: string} $options
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     */
    public function send(string $inboxId, string $draftId, array $options = []): array
    {
        return $this->http->requestOutcome(
            Util::path('inboxes', $inboxId, 'drafts', $draftId, 'send'),
            null,
            Util::optionHeaders($options),
        );
    }

    /**
     * POST /inboxes/:id/drafts/:draftId/reject — approvers only (MillionSend extension).
     *
     * @return array<mixed>
     */
    public function reject(string $inboxId, string $draftId): array
    {
        return $this->http->request('POST', Util::path('inboxes', $inboxId, 'drafts', $draftId, 'reject'));
    }
}
