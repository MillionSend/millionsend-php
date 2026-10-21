<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** A received email's attachments, each with a signed download link valid for one hour. */
final class ReceivingAttachments
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * GET /emails/receiving/:emailId/attachments — every attachment unless `limit` is given.
     *
     * @param array{limit?: int, after?: string, before?: string} $options
     * @return array<mixed>
     */
    public function list(string $emailId, array $options = []): array
    {
        return $this->http->request(
            'GET',
            Util::path('emails', 'receiving', $emailId, 'attachments'),
            null,
            Util::listQuery($options),
        );
    }

    /** @return array<mixed> */
    public function get(string $emailId, string $id): array
    {
        return $this->http->request('GET', Util::path('emails', 'receiving', $emailId, 'attachments', $id));
    }
}
