<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Inboxes: an address that receives mail, read by an agent (`kind` `agent`, the
 * default) or by people (`human`). Threads, labels and drafts mirror Resend's
 * Inboxes beta; safety, quarantine and codes are MillionSend extensions.
 */
final class Inboxes
{
    private const WIRE_MAP = [
        'emailAddress' => 'email_address',
        'domainId' => 'domain_id',
        'fromName' => 'from_name',
        'expiresIn' => 'expires_in',
    ];

    public readonly InboxSafety $safety;
    public readonly InboxCodes $codes;
    public readonly InboxQuarantine $quarantine;
    public readonly InboxLabels $labels;
    public readonly InboxThreads $threads;
    public readonly InboxDrafts $drafts;

    public function __construct(private readonly HttpClient $http)
    {
        $this->safety = new InboxSafety($http);
        $this->codes = new InboxCodes($http);
        $this->quarantine = new InboxQuarantine($http);
        $this->labels = new InboxLabels($http);
        $this->threads = new InboxThreads($http);
        $this->drafts = new InboxDrafts($http);
    }

    /**
     * POST /inboxes — `emailAddress` on one of your verified domains; omitted, the
     * address is on the shared domain, `username@<team>.<shared domain>` with
     * `username`, random without. `domainId` (a GET /domains id) replaces
     * `emailAddress`: the `shared: true` row works like omitting it, one of your
     * own domains needs `username`. Also `name` (1-320 characters), `fromName`,
     * `forwarding`, `kind` (`agent` or `human`), `expiresIn` (`1h` to `365d`;
     * omitted or null, it never expires) and `safety` (partial rules). On the
     * shared domain a chosen `username` or `fromName` is reviewed first: a 503
     * `name_review_unavailable` or a 429 tells when to retry in `getRetryAfter()`.
     *
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function create(array $params = []): array
    {
        $safety = $params['safety'] ?? null;
        if (is_array($safety)) {
            /** @var array<string,mixed> $safety */
            $params['safety'] = Util::body($safety, InboxSafety::WIRE_MAP);
        }

        return $this->http->request('POST', '/inboxes', Util::body($params, self::WIRE_MAP));
    }

    /**
     * GET /inboxes — newest first; `kind` filters to `agent` or `human`.
     *
     * @param array{limit?: int, after?: string, before?: string, kind?: string} $options
     * @return array<mixed>
     */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/inboxes', null, Util::query($options));
    }

    /**
     * GET /inboxes/:idOrEmail — by id or by address.
     *
     * @return array<mixed>
     */
    public function get(string $idOrEmail): array
    {
        return $this->http->request('GET', Util::path('inboxes', $idOrEmail));
    }

    /**
     * PATCH /inboxes/:id — `name`, `fromName` (`''` removes it), `expiresIn` (null
     * never expires). A new `fromName` on the shared domain is reviewed like on
     * {@see create()}, so this can answer 503 or 429 too.
     *
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', Util::path('inboxes', $id), Util::body($params, self::WIRE_MAP));
    }

    /**
     * DELETE /inboxes/:id — removes its threads, drafts and labels, and revokes its keys.
     *
     * @return array<mixed>
     */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', Util::path('inboxes', $id));
    }
}
