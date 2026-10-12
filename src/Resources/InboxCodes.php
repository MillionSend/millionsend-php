<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Verification codes and sign-in links pulled from received mail (MillionSend extension). */
final class InboxCodes
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /inboxes/:id/codes/latest — the newest delivered code or link. Options:
     * `from` (an address, or a domain covering its subdomains), `wait` (long-poll
     * up to 50 s), `maxAge` (seconds, 60..86400).
     *
     * @param array{from?: string, wait?: int, maxAge?: int, max_age?: int} $options
     * @return array<mixed>
     */
    public function latest(string $inboxId, array $options = []): array
    {
        return $this->http->request(
            'GET',
            Util::path('inboxes', $inboxId, 'codes', 'latest'),
            null,
            Util::query($options, ['maxAge' => 'max_age']),
        );
    }
}
