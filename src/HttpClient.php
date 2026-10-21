<?php

declare(strict_types=1);

namespace MillionSend;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MillionSend\Exceptions\ErrorException;

/**
 * Thin wrapper over Guzzle: adds auth/User-Agent, maps a non-2xx response to an
 * {@see ErrorException}, and a transport failure to the same with a null status.
 */
final class HttpClient
{
    public const VERSION = '0.9.1';

    /** Seconds a long-poll answer may take to arrive after the server's own `wait` deadline. */
    private const LONG_POLL_MARGIN = 10.0;

    private readonly string $baseUrl;
    private readonly string $userAgent;
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?ClientInterface $http = null,
        ?string $userAgent = null,
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        bool $allowInsecureHttp = false,
    ) {
        if ($this->timeout <= 0 || $this->connectTimeout <= 0) {
            throw new \InvalidArgumentException('HTTP timeouts must be greater than zero.');
        }
        $resolved = $baseUrl ?? (getenv('MILLIONSEND_BASE_URL') ?: null) ?? 'https://api.millionsend.com';
        $this->baseUrl = rtrim($resolved, '/');
        // The API key travels as a bearer header, so plain http is loopback-only by default.
        if (!$allowInsecureHttp && Util::isInsecureHttpUrl($this->baseUrl)) {
            throw new \InvalidArgumentException(
                "Refusing to send the API key over plain http to {$this->baseUrl}. "
                . "Use https, or pass ['allowInsecureHttp' => true]."
            );
        }
        $this->http = $http ?? new GuzzleClient();
        $base = 'millionsend-php/' . self::VERSION;
        $this->userAgent = $userAgent !== null ? "{$base} {$userAgent}" : $base;
    }

    /**
     * Keeps the API key out of var_dump/print_r output.
     *
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'userAgent' => $this->userAgent];
    }

    /**
     * @param array<mixed>|object|null $body    Object bodies encode as JSON objects; list arrays stay JSON arrays.
     * @param array<string,scalar>     $query
     * @param array<string,string>     $headers Extra per-request headers (see {@see Util::optionHeaders()}).
     * @return array<mixed>
     * @throws ErrorException
     */
    public function request(
        string $method,
        string $path,
        array|object|null $body = null,
        array $query = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): array {
        return $this->send($method, $path, $body, $query, $idempotencyKey, $headers)[1];
    }

    /**
     * POST a send a person may have to approve (replies, forwards, draft sends):
     * `['kind' => 'sent', 'email' => …]`, or on an HTTP 202
     * `['kind' => 'draft', 'draft' => …]`, the draft the API held for approval.
     *
     * @param array<mixed>|object|null $body
     * @param array<string,string>     $headers
     * @return array{kind: 'sent', email: array<mixed>}|array{kind: 'draft', draft: array<mixed>}
     * @throws ErrorException
     */
    public function requestOutcome(string $path, array|object|null $body = null, array $headers = []): array
    {
        [$status, $data] = $this->send('POST', $path, $body, [], null, $headers);

        return $status === 202 ? ['kind' => 'draft', 'draft' => $data] : ['kind' => 'sent', 'email' => $data];
    }

    /**
     * @param array<mixed>|object|null $body
     * @param array<string,scalar>     $query
     * @param array<string,string>     $headers
     * @return array{0: int, 1: array<mixed>}
     * @throws ErrorException
     */
    private function send(
        string $method,
        string $path,
        array|object|null $body,
        array $query,
        ?string $idempotencyKey,
        array $headers,
    ): array {
        // URL normalization resolves an empty, "." or ".." segment away, so an id
        // such as ".." would send the request to another resource
        // (DELETE /inboxes/{id}/labels/.. deletes the inbox).
        foreach (array_slice(explode('/', $path), 1) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('An id in the request path is empty, "." or "..".');
            }
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
            ...$headers,
        ];
        // Idempotency is POST-only on the wire; sending it elsewhere is a no-op.
        if ($idempotencyKey !== null && $method === 'POST') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $timeout = $this->timeout;
        // A `wait` query asks the API to hold its answer up to that many seconds
        // (long-poll), so the deadline has to outlast it.
        $wait = $query['wait'] ?? null;
        if (is_numeric($wait) && $wait > 0) {
            $timeout = max($timeout, (float) $wait + self::LONG_POLL_MARGIN);
        }

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'timeout' => $timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, $this->baseUrl . $path, $options);
        } catch (GuzzleException $e) {
            throw new ErrorException('application_error', $e->getMessage(), null);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? null : json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $retryAfter = filter_var($response->getHeaderLine('retry-after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            throw ErrorException::fromBody($decoded, $status, $retryAfter === false ? null : $retryAfter);
        }

        return [$status, is_array($decoded) ? $decoded : []];
    }
}
