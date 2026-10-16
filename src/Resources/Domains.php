<?php

declare(strict_types=1);

namespace MillionSend\Resources;

use MillionSend\HttpClient;
use MillionSend\Util;

/**
 * Sending domains and their DNS records. On an instance with a shared inbox
 * domain, the team's subdomain of it is listed last with `shared: true` and its
 * `sending_mode`; it is read-only (403 `forbidden`) and sends only from an
 * inbox's own address, so pick sender domains among the `shared: false` rows.
 */
final class Domains
{
    private const WIRE_MAP = [
        'customReturnPath' => 'custom_return_path',
        'openTracking' => 'open_tracking',
        'clickTracking' => 'click_tracking',
        'trackingSubdomain' => 'tracking_subdomain',
        'receivingCatchAll' => 'receiving_catch_all',
    ];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /domains — the response carries the DNS `records` to publish.
     *
     * @param array{name: string, region?: string, customReturnPath?: string, openTracking?: bool, clickTracking?: bool, trackingSubdomain?: string} $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/domains', Util::body($params, self::WIRE_MAP));
    }

    /** @return array<mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/domains/' . rawurlencode($id));
    }

    /**
     * @param array{limit?: int, after?: string, before?: string} $options
     * @return array<mixed>
     */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/domains', null, Util::listQuery($options));
    }

    /**
     * PATCH /domains/:id — `trackingSubdomain => null` clears it;
     * `capabilities => ['receiving' => 'enabled']` turns receiving on (a verified
     * domain); `receivingCatchAll` accepts mail for addresses without an inbox (a
     * paid plan on Cloud). resend-php's `tls` passes through; the server answers
     * 422 for it.
     *
     * @param array{openTracking?: bool, clickTracking?: bool, trackingSubdomain?: string|null, receivingCatchAll?: bool, tls?: mixed, capabilities?: mixed} $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/domains/' . rawurlencode($id), Util::body($params, self::WIRE_MAP));
    }

    /**
     * POST /domains/:id/verify — re-checks DNS and returns the domain.
     *
     * @return array<mixed>
     */
    public function verify(string $id): array
    {
        return $this->http->request('POST', '/domains/' . rawurlencode($id) . '/verify');
    }

    /** @return array<mixed> */
    public function remove(string $id): array
    {
        return $this->http->request('DELETE', '/domains/' . rawurlencode($id));
    }
}
