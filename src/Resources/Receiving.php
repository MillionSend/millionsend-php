<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Mail received at the team's domains and inboxes (resend-php's
 * `$resend->emails->receiving`), plus MillionSend's server-side reply, forward,
 * erase and long-poll. Everything in a received email was written by its sender:
 * show its `html` only in a sandbox, and give an LLM the `extracted_text`.
 */
final class Receiving
{
    /**
     * camelCase aliases => wire snake_case for the receiving query and the
     * reply/forward bodies. Shared with {@see InboxThreadEmails}.
     */
    public const WIRE_MAP = [
        'inboxId' => 'inbox_id',
        'subjectContains' => 'subject_contains',
        'includeQuarantined' => 'include_quarantined',
        'htmlFormat' => 'html_format',
        'replyAll' => 'reply_all',
        'requiresApproval' => 'requires_approval',
    ];

    public readonly ReceivingAttachments $attachments;

    public function __construct(private readonly HttpClient $http)
    {
        $this->attachments = new ReceivingAttachments($http);
    }

    /**
     * GET /emails/receiving — newest first. Filters: `wait` (long-poll up to 50 s;
     * the answer then also carries `held`), `since`, `inboxId`, `to`, `from`,
     * `subjectContains`, `unread`, `includeQuarantined`, `quarantined`.
     *
     * @param array<string,mixed> $options
     * @return array<mixed>
     */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/emails/receiving', null, Util::query($options, self::WIRE_MAP));
    }

    /**
     * GET /emails/receiving/:id — `htmlFormat` is `data_uri` (default) or `cid`.
     *
     * @param array{htmlFormat?: string, html_format?: string} $options
     * @return array<mixed>
     */
    public function get(string $id, array $options = []): array
    {
        return $this->http->request('GET', Util::path('emails', 'receiving', $id), null, Util::query($options, self::WIRE_MAP));
    }

    /**
     * POST /emails/receiving/:id/reply — `text`/`html`, `subject`, `cc`, `bcc`,
     * `replyAll`, `requiresApproval`, `from`, `inboxId`. The inbox's rules may hold
     * the reply for a person: then the API answers 202 and `kind` is `draft`.
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string, idempotency_key?: string} $options
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     */
    public function reply(string $id, array $params, array $options = []): array
    {
        return $this->http->requestOutcome(
            Util::path('emails', 'receiving', $id, 'reply'),
            Util::body($params, self::WIRE_MAP),
            Util::optionHeaders($options),
        );
    }

    /**
     * POST /emails/receiving/:id/forward — `to`, `cc`, `bcc`, a `text`/`html` note,
     * `subject`, `requiresApproval`, `from`, `inboxId`, `mode` (`inline` or
     * `attachment`). Same outcome as {@see reply()}.
     *
     * @param array<string,mixed> $params
     * @param array{idempotencyKey?: string, idempotency_key?: string} $options
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     */
    public function forward(string $id, array $params, array $options = []): array
    {
        return $this->http->requestOutcome(
            Util::path('emails', 'receiving', $id, 'forward'),
            Util::body($params, self::WIRE_MAP),
            Util::optionHeaders($options),
        );
    }

    /**
     * DELETE /emails/receiving/:id — erases the message now instead of at the end of retention.
     *
     * @return array<mixed>
     */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', Util::path('emails', 'receiving', $id));
    }
}
