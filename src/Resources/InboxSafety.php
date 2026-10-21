<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** An inbox's safety rules: who can write in and what the inbox may send (MillionSend extension). */
final class InboxSafety
{
    /** Safety-rule camelCase aliases => wire snake_case. Shared with {@see Inboxes::create()}. */
    public const WIRE_MAP = [
        'acceptFrom' => 'accept_from',
        'ownerAddresses' => 'owner_addresses',
        'extractCodes' => 'extract_codes',
        'sendPolicy' => 'send_policy',
        'requireApproval' => 'require_approval',
        'privateCodes' => 'private_codes',
        'sendCap' => 'send_cap',
        'forwardTo' => 'forward_to',
    ];

    public function __construct(private readonly HttpClient $http) {}

    /** @return array<mixed> */
    public function get(string $inboxId): array
    {
        return $this->http->request('GET', Util::path('inboxes', $inboxId, 'safety'));
    }

    /**
     * PATCH /inboxes/:id/safety — omitted rules keep their value; inbox-scoped keys get a 403.
     *
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function update(string $inboxId, array $params): array
    {
        return $this->http->request('PATCH', Util::path('inboxes', $inboxId, 'safety'), Util::body($params, self::WIRE_MAP));
    }
}
