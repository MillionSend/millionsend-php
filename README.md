# millionsend-php

Official PHP SDK for [MillionSend](https://github.com/MillionSend) — a self-hostable, Resend-compatible email API on AWS SES.

The API is wire-compatible with Resend, and this SDK deliberately mirrors the
shape of [`resend-php`](https://github.com/resend/resend-php), so migrating is
mostly a find-and-replace: swap the factory, and point the base URL at your
instance.

## Install

```bash
composer require millionsend/millionsend-php
```

Requires PHP 8.1+.

## Quickstart

```php
use MillionSend\MillionSend;
use MillionSend\Exceptions\ErrorException;

$ms = MillionSend::client('ms_123', 'https://mail.acme.dev');

try {
    $email = $ms->emails->send([
        'from' => 'Acme <onboarding@acme.dev>',
        'to' => 'delivered@resend.dev',
        'subject' => 'Hello from MillionSend',
        'html' => '<strong>It works!</strong>',
    ]);

    echo "sent {$email['id']}\n";
} catch (ErrorException $e) {
    echo "{$e->getErrorName()}: {$e->getErrorMessage()}\n";
}
```

## Configuration

```php
MillionSend::client(
    apiKey: 'ms_123',                 // falls back to env MILLIONSEND_API_KEY; missing → throws
    baseUrl: 'https://mail.acme.dev', // falls back to env MILLIONSEND_BASE_URL, then http://localhost:3001
    options: [
        'client' => $guzzle,          // inject a GuzzleHttp\ClientInterface (proxies, tests)
        'userAgent' => 'acme-app/2.1', // suffix appended after the SDK's own token
    ],
);
```

MillionSend is self-hosted, so there is no cloud default — **set `baseUrl` (or
`MILLIONSEND_BASE_URL`) to your deployment in production.**

## Errors

Every non-2xx response throws `MillionSend\Exceptions\ErrorException`. Its
`getErrorName()` is a stable snake_case code you can branch on
(`validation_error`, `not_found`, `restricted_api_key`, `sending_paused`, …).
Client-side and transport failures (a request that never reached the API) throw
the same exception with `getStatusCode()` returning `null`.

```php
try {
    $email = $ms->emails->get($id);
} catch (ErrorException $e) {
    if ($e->getErrorName() === 'not_found') { /* … */ }
    // $e->getStatusCode(); // int, or null for transport failures
}
```

Successful calls return the decoded JSON body as an associative array.

## Resources

### Emails

```php
$ms->emails->send($payload, ['idempotencyKey' => $key]);   // POST /emails
$ms->emails->get($id);                                      // GET /emails/:id
$ms->emails->cancel($id);                                   // POST /emails/:id/cancel (scheduled only)
$ms->batch->send([$payloadA, $payloadB], ['idempotencyKey' => $key]); // up to 100
```

Send options are camelCase and mapped to the wire: `replyTo` → `reply_to`,
`scheduledAt` → `scheduled_at`. `to`/`cc`/`bcc`/`replyTo` accept a string or an array.

```php
$ms->emails->send([
    'from' => 'Acme <onboarding@acme.dev>',
    'to' => ['ada@acme.dev', 'grace@acme.dev'],
    'subject' => 'Launch',
    'html' => '<p>Hi</p>',
    'replyTo' => 'support@acme.dev',
    'tags' => [['name' => 'category', 'value' => 'launch']],
]);
```

### Audiences & contacts

```php
$audience = $ms->audiences->create(['name' => 'Registered users']);
$ms->audiences->list(['limit' => 20, 'after' => $cursor]);
$ms->audiences->get($id);
$ms->audiences->remove($id);

$ms->contacts->create([
    'audienceId' => $audienceId,
    'email' => 'ada@acme.dev',
    'firstName' => 'Ada',
    'properties' => ['plan' => 'pro'],
]);
$ms->contacts->get(['audienceId' => $audienceId, 'email' => 'ada@acme.dev']); // by id or email (email wins)
$ms->contacts->get($contactId);                                               // a bare string id works too
$ms->contacts->update(['id' => $contactId, 'unsubscribed' => true, 'firstName' => null]); // null clears
$ms->contacts->remove(['email' => 'ada@acme.dev']);
$ms->contacts->list(['audienceId' => $audienceId, 'limit' => 50]);

// Topic subscriptions (granular unsubscribe)
$ms->contacts->topics->update([
    'email' => 'ada@acme.dev',
    'topics' => [['id' => $topicId, 'subscription' => 'opt_out']],
]);
// $ms->contacts->updateTopics([...]) is an equivalent alias.
```

### Topics

```php
$ms->topics->create(['name' => 'Product updates', 'defaultSubscription' => 'opt_in']);
$ms->topics->get($id);
$ms->topics->list();     // bare { data } — topics are unpaginated
$ms->topics->remove($id);
```

### Broadcasts

```php
$broadcast = $ms->broadcasts->create([
    'audienceId' => $audienceId,
    'from' => 'Acme <news@acme.dev>',
    'subject' => 'Launch',
    'html' => '<p>Hi {{{FIRST_NAME|there}}}</p>',
]);
$ms->broadcasts->list();
$ms->broadcasts->get($id);
$ms->broadcasts->update($id, ['subject' => 'Launch 🚀']);          // draft only
$ms->broadcasts->send($id, ['scheduledAt' => '2026-09-01T09:00:00Z']); // omit to send now
$ms->broadcasts->cancel($id);                                       // scheduled only
$ms->broadcasts->remove($id);                                       // draft only
```

### Segments (MillionSend extension)

Dynamic segments are a saved filter over an audience's contacts — a MillionSend
superset with no Resend equivalent (served at `/segments2`).

```php
$ms->segments->create([
    'name' => 'Pro plan',
    'audienceId' => $audienceId,
    'filter' => [
        'match' => 'all',
        'conditions' => [['field' => 'property:plan', 'op' => 'equals', 'value' => 'pro']],
    ],
]);
$ms->segments->get($id);   // includes a live contact_count
$ms->segments->list();
$ms->segments->update($id, ['name' => 'Pro tier']);
$ms->segments->remove($id);
```

## Migrating from Resend

```diff
- use Resend;
- $resend = Resend::client('re_123');
+ use MillionSend\MillionSend;
+ $ms = MillionSend::client('ms_123', 'https://mail.acme.dev');
```

Method names, nesting, and payloads match `resend-php`. Notes:

- **Domains and API keys** are managed in the MillionSend dashboard, not via the
  API, so there are no `->domains`/`->apiKeys` resources here.
- Resend's `->segments` is an alias of audiences; MillionSend's `->segments` is
  the distinct dynamic-filter feature. Use `->audiences` for a straight port.

## License

MIT — see [LICENSE](LICENSE).
