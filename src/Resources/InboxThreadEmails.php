<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** The messages of a thread, both directions. */
final class InboxThreadEmails
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /inboxes/:id/threads/:threadId/emails — oldest first; `after` pages toward newer.
     * `includeQuarantined` also lists held messages (full-access team keys only).
     *
     * @param array{limit?: int, after?: string, before?: string, includeQuarantined?: bool, include_quarantined?: bool} $options
     * @return array<mixed>
     */
    public function list(string $inboxId, string $threadId, array $options = []): array
    {
        return $this->http->request(
            'GET',
            Util::path('inboxes', $inboxId, 'threads', $threadId, 'emails'),
            null,
            Util::query($options, Receiving::WIRE_MAP),
        );
    }

    /**
     * GET /inboxes/:id/threads/:threadId/emails/:emailId — `includeQuarantined`
     * reads a held message (full-access team keys only).
     *
     * @param array{includeQuarantined?: bool, include_quarantined?: bool} $options
     * @return array<mixed>
     */
    public function get(string $inboxId, string $threadId, string $emailId, array $options = []): array
    {
        return $this->http->request(
            'GET',
            Util::path('inboxes', $inboxId, 'threads', $threadId, 'emails', $emailId),
            null,
            Util::query($options, Receiving::WIRE_MAP),
        );
    }

    /**
     * POST …/emails/:emailId/reply — `html`/`text`, `cc`, `bcc`, `subject`,
     * `replyAll`, `requiresApproval`. The inbox's rules may hold the reply for a
     * person: then the API answers 202 and `kind` is `draft`.
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string, idempotency_key?: string} $options
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     */
    public function reply(string $inboxId, string $threadId, string $emailId, array $params, array $options = []): array
    {
        return $this->http->requestOutcome(
            Util::path('inboxes', $inboxId, 'threads', $threadId, 'emails', $emailId, 'reply'),
            Util::body($params, Receiving::WIRE_MAP),
            Util::optionHeaders($options),
        );
    }

    /**
     * POST …/emails/:emailId/forward — `to`, `cc`, `bcc`, a `html`/`text` note,
     * `subject`, `requiresApproval`. Same outcome as {@see reply()}.
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string, idempotency_key?: string} $options
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     */
    public function forward(string $inboxId, string $threadId, string $emailId, array $params, array $options = []): array
    {
        return $this->http->requestOutcome(
            Util::path('inboxes', $inboxId, 'threads', $threadId, 'emails', $emailId, 'forward'),
            Util::body($params, Receiving::WIRE_MAP),
            Util::optionHeaders($options),
        );
    }
}
