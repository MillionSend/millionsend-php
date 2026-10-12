# millionsend-php

Official PHP SDK for [MillionSend](https://github.com/MillionSend) — a self-hostable, Resend-compatible email API on AWS SES.

The API is wire-compatible with Resend, and this SDK deliberately mirrors the
shape of [`resend-php`](https://github.com/resend/resend-php), so migrating is
mostly a find-and-replace: swap the factory (and, for a self-hosted instance,
point the base URL at it).

## Install

```bash
composer require millionsend/millionsend-php
```

Requires PHP 8.1+.

## Quickstart

```php
use MillionSend\MillionSend;
use MillionSend\Exceptions\ErrorException;

$ms = MillionSend::client('ms_123');                            // MillionSend Cloud
// $ms = MillionSend::client('ms_123', 'https://mail.acme.dev'); // self-hosted

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
    baseUrl: 'https://mail.acme.dev', // falls back to env MILLIONSEND_BASE_URL, then https://api.millionsend.com
    options: [
        'client' => $guzzle,          // inject a GuzzleHttp\ClientInterface (proxies, tests)
        'userAgent' => 'acme-app/2.1', // suffix appended after the SDK's own token
        'timeout' => 30.0,            // total request timeout, seconds
        'connectTimeout' => 10.0,     // connection timeout, seconds
        'allowInsecureHttp' => false, // accept a non-loopback http:// baseUrl
    ],
);
```

With just an API key the client talks to MillionSend Cloud (`https://api.millionsend.com`).
A self-hosted instance sets its origin via `baseUrl` or `MILLIONSEND_BASE_URL`. Plain `http://` is only
accepted for loopback hosts (`localhost`, `127.0.0.1`, `::1`); any other `http://` URL
throws `InvalidArgumentException` at construction, since the API key is sent as a bearer
header. Pass `'allowInsecureHttp' => true` to talk to a non-TLS instance elsewhere (e.g.
inside a private network).

## Payloads

Payloads are plain arrays and go on the wire as-is — every key you pass is sent, in
snake_case exactly as `resend-php` documents it (`reply_to`, `scheduled_at`,
`first_name`, …). The camelCase spellings this SDK has always accepted keep working
and are renamed on the way out (`replyTo` → `reply_to`, `scheduledAt` → `scheduled_at`,
`topicId` → `topic_id`, `firstName` → `first_name`, `segmentId` → `segment_id`,
`previewText` → `preview_text`, and so on per resource below). A key that is present
with a `null` value is sent as JSON `null`, which is how you clear a nullable field;
a key you leave out stays off the wire.

Successful calls return the decoded JSON body as an associative array.

## Errors

Every non-2xx response throws `MillionSend\Exceptions\ErrorException`. Its
`getErrorName()` is a stable snake_case code you can branch on
(`validation_error`, `not_found`, `restricted_api_key`, `sending_paused`, `all_recipients_suppressed`, …).
Client-side and transport failures (a request that never reached the API) throw
the same exception with `getStatusCode()` returning `null`.

`emails->send()` and `batch->send()` throw a 422 `all_recipients_suppressed` when every
`to` recipient is on the suppression list or has opted out of the send's `topic_id`.

Inboxes add `inbox_send_policy` (403, a send the inbox's rules hold),
`inbox_send_cap_reached` (429, the inbox's daily cap), `approval_required` (403),
`email_quarantined` (403), `email_blocked` (410, a download of an email that carries
malware) and `too_many_waits` (429, too many concurrent long-polls).

```php
try {
    $email = $ms->emails->get($id);
} catch (ErrorException $e) {
    if ($e->getErrorName() === 'not_found') { /* … */ }
    // $e->getStatusCode(); // int, or null for transport failures
}
```

## Resources

### Emails

```php
$ms->emails->send($payload, ['idempotency_key' => $key]);  // POST /emails
$ms->emails->get($id);                                     // GET /emails/:id (includes a nullable 0-10 `score`)
$ms->emails->list(['limit' => 50, 'after' => $cursor]);    // GET /emails
$ms->emails->update($id, ['scheduled_at' => $iso8601]);    // PATCH /emails/:id (reschedule)
$ms->emails->cancel($id);                                  // POST /emails/:id/cancel (scheduled only)
$ms->emails->remove($id);                                  // DELETE /emails/:id (MillionSend extension)
$ms->emails->getInsights($id);                             // GET /emails/:id/insights (404 until computed; MillionSend extension)
```

Every send field is supported: `from`, `to`, `subject`, `html`, `text`, `cc`, `bcc`,
`reply_to`, `scheduled_at`, `tags`, `topic_id`, `attachments`, `headers` and
`template`. `to`/`cc`/`bcc`/`reply_to` accept a string or an array. Options:
`idempotency_key` (or `idempotencyKey`) sets the `Idempotency-Key` header.

```php
$ms->emails->send([
    'from' => 'Acme <onboarding@acme.dev>',
    'to' => ['ada@acme.dev', 'grace@acme.dev'],
    'subject' => 'Launch',
    'html' => '<p>Hi</p>',
    'reply_to' => 'support@acme.dev',
    'scheduled_at' => 'in 1 hour',
    'tags' => [['name' => 'category', 'value' => 'launch']],
    'topic_id' => $topicId,
    'attachments' => [[
        'filename' => 'invoice.pdf',
        'content' => base64_encode($pdf),   // or 'path' => 'https://…'
        'content_type' => 'application/pdf',
    ]],
    'headers' => ['X-Entity-Ref-ID' => '123'],
], ['idempotency_key' => 'order-42']);
```

### Batch

```php
// POST /emails/batch — up to 100 emails, one call
$ms->batch->send([$payloadA, $payloadB], [
    'idempotency_key' => $key,
    'batch_validation' => 'permissive', // or 'strict' (server default)
]);
```

`batch_validation` (or `batchValidation`) sets the `x-batch-validation` header. In
`strict` mode one invalid email fails the whole batch; in `permissive` mode the valid
ones are sent and the rest come back in the response's `errors[]` as
`[{index, message}]`.

### Receiving

Mail received at your domains and inboxes. Everything in a received email was
written by its sender: show its `html` only in a sandbox, and give an LLM the
`extracted_text` (hidden text, quotes and the signature removed) rather than the `html`.

```php
$page = $ms->emails->receiving->list([
    'inbox_id' => $inboxId,          // only mail delivered to this inbox
    'from' => 'example.com',         // an address, or a domain covering its subdomains
    'subject_contains' => 'code',
    'wait' => 30,                    // long-poll: answer as soon as a match arrives, up to 50 s
]);
$page['data'];                       // newest first
$page['held'];                       // with wait: [{id, inbox_id, reason, created_at}] held for screening

$email = $ms->emails->receiving->get($id, ['html_format' => 'cid']);
$email['extracted_text'];            // the new part as plain text, hidden text removed (still untrusted)
$email['extracted']['code'];         // a verification code, on inboxes with extract_codes
$email['screening']['sender_trust']; // owner | allowlisted | known_thread | external | unauthenticated | failed

$ms->emails->receiving->attachments->list($id);             // each with a signed download_url (1 hour)
$ms->emails->receiving->attachments->get($id, $attachmentId);
$ms->emails->receiving->remove($id);                        // erase now (MillionSend extension)

// Reply and forward server-side (MillionSend extension). The inbox's rules may hold the
// message as a draft that waits for a person: the API answers 202 and `kind` is 'draft'.
$outcome = $ms->emails->receiving->reply($id, ['text' => 'Thanks!'], ['idempotency_key' => $key]);
if ($outcome['kind'] === 'draft') {
    $outcome['draft']['approval_reasons'];  // e.g. ['send_policy']
} else {
    $outcome['email']['id'];
}
$ms->emails->receiving->forward($id, ['to' => 'ops@acme.dev', 'text' => 'FYI', 'mode' => 'attachment']);
```

Held mail never reaches an inbox-scoped key or an agent: reading it throws
`email_quarantined`. A full-access team key sees it with `'include_quarantined' => true`.

### Inboxes

An inbox is one address that receives mail, read by an agent (`'kind' => 'agent'`,
the default) or by people (`'kind' => 'human'`). Threads, labels, drafts and their
paths mirror Resend's Inboxes beta; `safety`, `codes` and `quarantine` are
MillionSend extensions.

```php
$inbox = $ms->inboxes->create([
    'name' => 'Signup bot',          // no email_address: a random address on the shared domain
    'expires_in' => '24h',           // '<n>h' or '<n>d'; null = never (custom domains only)
    'safety' => ['accept_from' => 'anyone'],
]);
$ms->inboxes->create(['email_address' => 'support@acme.dev', 'name' => 'Support', 'kind' => 'human']);
$ms->inboxes->list(['kind' => 'agent']);
$ms->inboxes->get('support@acme.dev');                       // by id or address
$ms->inboxes->update($inbox['id'], ['from_name' => 'Acme', 'expires_in' => '7d']);
$ms->inboxes->remove($inbox['id']);                          // also revokes its inbox-scoped keys

// Codes and sign-in links, without parsing HTML (wait long-polls up to 50 s)
$code = $ms->inboxes->codes->latest($inbox['id'], ['from' => 'github.com', 'wait' => 30]);
$code['status'];                     // 'found' | 'none' | 'ambiguous' | 'timeout'; $code['code'], $code['url']

// Safety rules: who can write in, what the inbox may send
$ms->inboxes->safety->get($inbox['id']);
$ms->inboxes->safety->update($inbox['id'], ['accept_from' => 'allowlist', 'allowlist' => ['*@acme.dev']]);

// Quarantine: held mail, kept until a person releases it
$ms->inboxes->quarantine->list($inbox['id']);
$ms->inboxes->quarantine->release($inbox['id'], $emailId, ['trust_sender' => true]);

// Threads and their messages
$ms->inboxes->threads->list($inbox['id'], ['folders' => ['inbox', 'archive'], 'read' => false]);
$ms->inboxes->threads->search($inbox['id'], ['query' => 'invoice', 'from' => ['example.com']]);
$ms->inboxes->threads->get($inbox['id'], $threadId);
$ms->inboxes->threads->update($inbox['id'], $threadId, ['read' => true, 'folder' => 'archive', 'label_id' => $labelId]);
$ms->inboxes->threads->remove($inbox['id'], $threadId);     // to trash, purged after 30 days
$ms->inboxes->threads->emails->list($inbox['id'], $threadId); // oldest first
$ms->inboxes->threads->emails->get($inbox['id'], $threadId, $emailId);
$ms->inboxes->threads->emails->reply($inbox['id'], $threadId, $emailId, ['text' => 'Done.'], ['idempotency_key' => $key]);
$ms->inboxes->threads->emails->forward($inbox['id'], $threadId, $emailId, ['to' => 'ops@acme.dev']);

// Labels (up to 100 per inbox)
$ms->inboxes->labels->create($inbox['id'], ['name' => 'Billing', 'color' => '#FF8800']);
$ms->inboxes->labels->list($inbox['id']);
$ms->inboxes->labels->update($inbox['id'], $labelId, ['name' => 'Invoices']);
$ms->inboxes->labels->remove($inbox['id'], $labelId);

// Drafts, and the ones that wait for approval
$ms->inboxes->drafts->create($inbox['id'], ['to' => 'ada@example.com', 'subject' => 'Hi', 'text' => '…']);
$ms->inboxes->drafts->list($inbox['id']);
$ms->inboxes->drafts->get($inbox['id'], $draftId);
$ms->inboxes->drafts->update($inbox['id'], $draftId, ['cc' => null]);  // null clears
$ms->inboxes->drafts->send($inbox['id'], $draftId, ['idempotency_key' => $key]); // ['kind' => 'sent'|'draft', …]
$ms->inboxes->drafts->reject($inbox['id'], $draftId);
$ms->inboxes->drafts->remove($inbox['id'], $draftId);
```

Replies, forwards and `drafts->send()` return `['kind' => 'sent', 'email' => …]` or,
when the inbox's rules hold the message for a person (HTTP 202),
`['kind' => 'draft', 'draft' => …]`. A key restricted to the inbox cannot send a draft
that waits for approval (`approval_required`); a person in the dashboard or a
full-access team key approves it. camelCase spellings (`inboxId`, `replyAll`,
`requiresApproval`, `expiresIn`, `acceptFrom`, `trustSender`, `labelId`, `threadId`,
`replyToEmailId`, …) are renamed like everywhere else. Hosted agent settings
(`/inboxes/:id/agent`) are not exposed: MillionSend runs no agent of its own.

### Contacts

Contacts are team-global: one record per email address, shared by every
broadcast and segment. Address them by id or by email.

```php
$ms->contacts->create([
    'email' => 'ada@acme.dev',
    'first_name' => 'Ada',
    'last_name' => 'Lovelace',
    'unsubscribed' => false,
    'properties' => ['plan' => 'pro'],
    'segments' => [['id' => $segmentId]],
    'topics' => [['id' => $topicId, 'subscription' => 'opt_in']],
]);
$ms->contacts->get('ada@acme.dev');                         // by id or email
$ms->contacts->update('ada@acme.dev', ['first_name' => null, 'unsubscribed' => true]); // null clears
$ms->contacts->update(['id' => $id, 'last_name' => 'L']);   // single-array shape also works
$ms->contacts->remove($id);                                 // the contact's emails stay in the send log
$ms->contacts->remove($id, ['erase' => true]);              // ?erase=true — also scrubs the address from email history, events and API logs (GDPR/LGPD)
$ms->contacts->list(['limit' => 50]);
$ms->contacts->list(['segment_id' => $segmentId]);          // GET /segments/:id/contacts
// Bulk read (MillionSend extension): attach properties and topic subscriptions to every item,
// so an audience reads in one request per 100 contacts instead of one per contact
$ms->contacts->list(['limit' => 100, 'include' => ['properties', 'topics']]);   // ?include=properties,topics

// Topic subscriptions (granular unsubscribe)
$ms->contacts->topics->get($idOrEmail);   // GET /contacts/:id/topics (->list() is an alias)
// => ['object' => 'list', 'has_more' => false, 'data' => [
//      ['id' => …, 'name' => 'Insights', 'description' => null, 'subscription' => 'opt_in', 'explicit' => false, 'visibility' => 'public'], …]]
//    `subscription` is the effective choice; `explicit` is false when it is the topic default;
//    `visibility` (public|private) says whether the hosted preference page lists the topic.
$ms->contacts->topics->update($idOrEmail, [['id' => $topicId, 'subscription' => 'opt_out']]);
$ms->contacts->topics->update([                                    // single-array shape also works
    'email' => 'ada@acme.dev',
    'topics' => [['id' => $topicId, 'subscription' => 'opt_out']],
]);

// Hosted preference page (MillionSend extension) — the page the emails' unsubscribe links open
$link = $ms->contacts->preferencesLink($idOrEmail);          // POST /contacts/:id/preferences-link
$link['url'];   // no expiry: whoever holds it can change that contact's preferences, so show it only to the contact
//              // 422 when the instance cannot build hosted links (self-hosted without APP_BASE_URL)

// Segment membership
$ms->contacts->segments->add($idOrEmail, $segmentId);       // POST /contacts/:id/segments/:segmentId
$ms->contacts->segments->remove($idOrEmail, $segmentId);    // DELETE …

// Bulk create (MillionSend extension) — up to 1000 per call
$result = $ms->contacts->batch->create($contacts, [
    'on_conflict' => 'upsert',          // error (default) | skip | upsert
    'batch_validation' => 'permissive', // strict (default) | permissive
]);
// $result['data'][] = ['index' => 0, 'id' => '…', 'status' => 'created'|'updated'|'skipped']
// $result['counts'] = ['created' => n, 'updated' => n, 'skipped' => n, 'failed' => n]
// $result['errors'][] = ['index' => 3, 'message' => '…']   (permissive mode)

// Bulk lookup (MillionSend extension) — up to 1000 contacts by id or email in one request,
// in request order; unknown entries are listed, not errors — one request against the rate limit
$result = $ms->contacts->batch->get([$contactId, ['email' => 'ada@acme.dev']], ['include' => ['topics']]);
// $result['data'][]    = ['object' => 'contact', 'id' => …, 'email' => …, …, 'topics' => [...]]  the contacts found
// $result['missing'][] = ['index' => 1, 'email' => '…']   request entries that matched nobody

// Bulk delete (MillionSend extension) — exactly one of ids or emails, up to 1000; their emails stay in the send log
$ms->contacts->batch->remove(['ids' => [...]]);      // or ['emails' => [...]] (case-insensitive)
$ms->contacts->batch->remove(['emails' => [...], 'erase' => true]);   // also scrubs the addresses from email history, events and API logs (GDPR/LGPD)
// => ['data' => [['object' => 'contact', 'contact' => '…', 'deleted' => true], …]]  only the rows actually deleted
```

### Contact properties

```php
$ms->contactProperties->create(['key' => 'plan', 'type' => 'string', 'fallback_value' => 'free']);
$ms->contactProperties->list();
$ms->contactProperties->get($id);
$ms->contactProperties->update($id, ['fallback_value' => null]);   // only the fallback is mutable
$ms->contactProperties->remove($id);
```

### Topics

```php
$ms->topics->create(['name' => 'Product updates', 'description' => 'Releases', 'default_subscription' => 'opt_in', 'visibility' => 'public']);
$ms->topics->get($id);
$ms->topics->list();     // bare { data } — topics are unpaginated
$ms->topics->update($id, ['name' => 'Product news', 'visibility' => 'private']);
$ms->topics->remove($id);
```

### Broadcasts

Targeting is an optional `segment_id` and/or `topic_id` — omit both to send to
every contact on the team.

```php
$broadcast = $ms->broadcasts->create([
    'name' => 'Launch',
    'from' => 'Acme <news@acme.dev>',
    'subject' => 'Launch',
    'html' => '<p>Hi {{{FIRST_NAME|there}}}</p>',
    'text' => 'Hi',
    'reply_to' => 'support@acme.dev',
    'preview_text' => 'Something new',
    'segment_id' => $segmentId,   // optional
    'topic_id' => $topicId,       // optional
    'send' => true,               // create and send in one call
    'scheduled_at' => '2026-09-01T09:00:00Z',
]);
$ms->broadcasts->list();
$ms->broadcasts->get($id);
$ms->broadcasts->update($id, ['subject' => 'Launch 🚀', 'topic_id' => null]); // draft only; null clears
$ms->broadcasts->send($id, ['scheduled_at' => '2026-09-01T09:00:00Z']);      // omit to send now
$ms->broadcasts->cancel($id);                                                 // scheduled only
$ms->broadcasts->remove($id);                                                 // draft only
```

### Segments

Same methods as `resend-php`'s `->segments`, but membership is dynamic: a segment
is a saved `filter` over the team's contacts (the `filter` field is the MillionSend
extension). Omit it — or set it to `null` on update — for a manual segment whose
members come from `contacts->segments->add()`.

```php
$ms->segments->create([
    'name' => 'Pro plan',
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

### Suppressions

```php
$ms->suppressions->add(['email' => 'bounced@example.com', 'origin' => 'manual']); // ->create() is an alias
$ms->suppressions->get($idOrEmail);
$ms->suppressions->list(['origin' => 'bounce', 'limit' => 50]);   // origin: bounce|complaint|manual|unsubscribe
$ms->suppressions->remove($idOrEmail);

$ms->suppressions->batch->add(['emails' => [...], 'origin' => 'unsubscribe']); // up to 1000
$ms->suppressions->batch->remove(['emails' => [...]]);   // or ['ids' => [...]]
```

### Domains

```php
$domain = $ms->domains->create([
    'name' => 'acme.dev',
    'region' => 'us-east-1',          // optional
    'custom_return_path' => 'send',   // optional
    'open_tracking' => true,
    'click_tracking' => true,
    'tracking_subdomain' => 'track',
]);
$domain['records'];                    // DNS records to publish
$ms->domains->list();
$ms->domains->get($id);
$ms->domains->verify($id);             // re-check DNS
$ms->domains->update($id, ['open_tracking' => false, 'tracking_subdomain' => null]);
$ms->domains->update($id, ['receiving_catch_all' => true]); // accept mail for addresses without an inbox
$ms->domains->remove($id);
```

### Webhooks

```php
$hook = $ms->webhooks->create([
    'endpoint' => 'https://acme.dev/hooks/millionsend',
    'events' => ['email.sent', 'email.delivered', 'email.bounced'],
    'signing_secret' => $secret,       // optional — one is generated when omitted
]);
$hook['signing_secret'];
$ms->webhooks->list();
$ms->webhooks->get($id);               // the only read that includes signing_secret (and previous_secret_expires_at)
$ms->webhooks->update($id, ['status' => 'disabled', 'events' => ['email.bounced']]);
$ms->webhooks->remove($id);

// Rotate the signing secret (MillionSend extension)
$rotated = $ms->webhooks->rotate($id);                                    // mints a new secret, 24h overlap
$rotated = $ms->webhooks->rotate($id, ['signing_secret' => $mine, 'overlap_hours' => 0]); // bring your own; 0..72
$rotated['signing_secret'];
$rotated['previous_secret_expires_at'];  // ISO timestamp while the old secret still co-signs deliveries, else null
```

During the overlap every delivery carries both signatures (new first, then previous,
space-separated in `webhook-signature`), so a receiver holding either verifies.

Subscribable `events` include `email.*` (with `email.received`) plus the contact and
suppression events: `contact.created`, `contact.updated`, `contact.deleted`,
`contact.unsubscribed`, `contact.resubscribed`, `contact.topic_opt_in`,
`contact.topic_opt_out`, `suppression.added`, `suppression.removed`; and the `inbox.*`
events: `inbox.created`, `inbox.updated`, `inbox.deleted`, `inbox.expired`,
`inbox.thread.created`, `inbox.thread.folder.updated`, `inbox.thread.labels.updated`,
`inbox.email.received`, `inbox.email.sent`, `inbox.email.quarantined`,
`inbox.draft.created`, `inbox.draft.updated`, `inbox.draft.sent`, `inbox.draft.deleted`.
Inbox events reach only endpoints that list them by name. `inbox_ids` limits an
endpoint's inbox events and `email.received` to those inboxes (`null` = every inbox).

### API keys

```php
$key = $ms->apiKeys->create([
    'name' => 'ci',
    'permission' => 'sending_access',  // full_access (default) | sending_access
    'domain_id' => $domainId,          // optional: restrict a sending key to one domain
]);
$key['token'];                         // shown once, never returned again
$ms->apiKeys->create(['name' => 'signup-agent', 'permission' => 'full_access', 'inbox_id' => $inboxId]);
                                       // reads that inbox only and sends only from its address
$ms->apiKeys->list();
$ms->apiKeys->remove($id);
```

### Templates

Templates are addressable by id or alias.

```php
$ms->templates->create([
    'name' => 'Welcome',
    'html' => '<p>Hi {{{name}}}</p>',
    'subject' => 'Welcome aboard',
    'text' => 'Hi',
    'alias' => 'welcome',
]);
$ms->templates->list();
$ms->templates->get('welcome');
$ms->templates->update('welcome', ['subject' => null, 'html' => '<p>v2</p>']); // null clears alias/subject/text
$ms->templates->remove($idOrAlias);
$ms->templates->publish($idOrAlias);   // no-op on MillionSend (templates are always live); kept for compatibility
$ms->templates->duplicate($idOrAlias);
```

### Deliverability (MillionSend extension)

The account-level deliverability score over the trailing window. Scores are
0-10 with one decimal; `score`/`band` are `null` until there is enough data.

```php
$report = $ms->deliverability->get();  // GET /deliverability
echo "{$report['score']} ({$report['band']})\n";
```

### Usage (MillionSend extension)

```php
$usage = $ms->usage->get();            // GET /usage
$usage['plan'];                        // free | pro | scale | null (self-hosted)
$usage['limits']['emails_per_day'];
$usage['today']['emails_sent'];
```

## Migrating from Resend

```diff
- use Resend;
- $resend = Resend::client('re_123');
+ use MillionSend\MillionSend;
+ $ms = MillionSend::client('ms_123');                            // MillionSend Cloud
+ $ms = MillionSend::client('ms_123', 'https://mail.acme.dev');  // self-hosted
```

Method names, nesting, options (`idempotency_key`, `batch_validation`) and payloads
match `resend-php`; snake_case payloads pass through to the wire untouched. Notes:

- **No audiences.** Contacts are team-global, so there is no `->audiences`
  resource and no `audience_id` params — drop the audience id and the calls map
  straight over. (The API keeps `/audiences/...` routes as a compatibility shim;
  they are not part of this SDK.) `->segments` keeps Resend's method names;
  membership is a dynamic `filter` rather than a static list.
- **Not offered here** (no MillionSend endpoint): `->contacts->imports`,
  `->contacts->segments->list()`, email
  `share()`/`metrics()`, `->emails->attachments`, `->webhooks->events`
  and the local `verify()` helper, `->domains->claims`, `->broadcasts->recipients()`
  /`->clickedLinks`, `->apiKeys->update()`, and the `->events`/`->logs`/`->automations`
  services. Domain `tls`/`capabilities` pass through and are answered with 422.
- **MillionSend extensions** (no Resend counterpart): segment `filter`,
  `->contacts->batch`, `->contacts->preferencesLink()`, `->webhooks->rotate()`,
  `->emails->getInsights()`, `->emails->remove()`,
  `->deliverability`, `->usage`, `->emails->receiving->reply()` / `->forward()` /
  `->remove()` (resend-php's `->receiving` has `get`, `list` and attachments only), and
  `->inboxes` (Resend's Inboxes are not in resend-php yet).

## Calling endpoints the SDK does not wrap yet

The transport is public as `$ms->http`. It adds auth and the User-Agent, sends the
`Idempotency-Key` header on POST, and maps errors to `ErrorException` exactly like the
wrapped methods do:

```php
$ms->http->request('POST', '/some/new/endpoint', ['key' => 'value']);          // JSON body
$ms->http->request('GET', '/some/new/endpoint', null, ['limit' => 10]);        // query string
$ms->http->request('POST', '/emails', $payload, [], $idempotencyKey);           // Idempotency-Key
// request(string $method, string $path, array|object|null $body = null, array $query = [],
//         ?string $idempotencyKey = null, array $headers = []): array
```

## License

MIT — see [LICENSE](LICENSE).
