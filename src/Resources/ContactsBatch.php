<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/** Bulk contact creation (MillionSend extension; Resend imports contacts only via CSV). */
final class ContactsBatch
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /contacts/batch — 1..1000 contacts as a bare JSON array. Options:
     * `onConflict`/`on_conflict` (`error`, the default, `skip` or `upsert`) and
     * `batchValidation`/`batch_validation` (`strict`, the default, or
     * `permissive`, which reports per-item failures in `errors[]`).
     *
     * @param list<array<string,mixed>> $params
     * @param array{onConflict?: string, on_conflict?: string, batchValidation?: string, batch_validation?: string} $options
     * @return array<mixed>
     */
    public function create(array $params, array $options = []): array
    {
        $body = array_map(
            static fn (array $contact): array => Util::rename($contact, Contacts::WIRE_MAP),
            array_values($params),
        );
        $onConflict = $options['onConflict'] ?? $options['on_conflict'] ?? null;

        return $this->http->request(
            'POST',
            '/contacts/batch',
            $body,
            $onConflict === null ? [] : ['on_conflict' => (string) $onConflict],
            null,
            Util::optionHeaders($options),
        );
    }
}
