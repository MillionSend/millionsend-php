<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Mail held by an inbox's rules, kept until a person releases it (MillionSend extension). */
final class InboxQuarantine
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /inboxes/:id/quarantine — newest first; full-access team keys only.
     *
     * @param array{limit?: int, after?: string, before?: string} $options
     * @return array<mixed>
     */
    public function list(string $inboxId, array $options = []): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'quarantine'), null, Util::listQuery($options));
    }

    /**
     * POST /inboxes/:id/quarantine/:emailId/release — `trustSender` also allowlists the sender.
     *
     * @param array{trustSender?: bool, trust_sender?: bool} $params
     * @return array<mixed>
     */
    public function release(string $inboxId, string $emailId, array $params = []): array
    {
        return $this->http->request(
            'POST',
            Util::path('inboxes', $inboxId, 'quarantine', $emailId, 'release'),
            Util::body($params, ['trustSender' => 'trust_sender']),
        );
    }
}
