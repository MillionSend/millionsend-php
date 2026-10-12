<?php

declare(strict_types=1);

describe('emails', function () {
    it('get and cancel hit the right paths', function () {
        [$ms, $spy] = fakeClient();
        $ms->emails->get('e1');
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/e1');

        $ms->emails->cancel('e1');
        expect($spy->at(1)->getMethod())->toBe('POST');
        expect($spy->at(1)->getUri()->getPath())->toBe('/emails/e1/cancel');
    });

    it('get surfaces the score field, present or null', function () {
        [$ms] = fakeClient(200, ['object' => 'email', 'id' => 'e1', 'score' => 8.5]);
        expect($ms->emails->get('e1')['score'])->toBe(8.5);

        [$ms] = fakeClient(200, ['object' => 'email', 'id' => 'e1', 'score' => null]);
        expect($ms->emails->get('e1')['score'])->toBeNull();
    });

    it('getInsights returns the full report', function () {
        $insights = [
            'object' => 'email_insights',
            'email_id' => 'e1',
            'score' => 8.5,
            'score_version' => 1,
            'band' => 'excellent',
            'marketing' => true,
            'html_size_bytes' => 12345,
            'computed_at' => '2026-08-31T12:00:00Z',
            'checks' => [
                [
                    'id' => 'list_unsubscribe',
                    'severity' => 'critical',
                    'status' => 'fail',
                    'penalty' => 1.25,
                    'detail' => ['header' => null, 'reason' => 'missing'],
                ],
                ['id' => 'plain_text_part', 'severity' => 'minor', 'status' => 'pass', 'penalty' => 0],
            ],
        ];
        [$ms, $spy] = fakeClient(200, $insights);

        expect($ms->emails->getInsights('e1'))->toEqual($insights);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/e1/insights');
    });

    it('getInsights passes unknown future check ids, statuses and bands through untouched', function () {
        [$ms] = fakeClient(200, [
            'object' => 'email_insights',
            'email_id' => 'e1',
            'score' => 5.0,
            'score_version' => 9,
            'band' => 'stellar',
            'marketing' => false,
            'html_size_bytes' => null,
            'computed_at' => '2026-08-31T12:00:00Z',
            'checks' => [['id' => 'brand_new_check', 'severity' => 'info', 'status' => 'deferred', 'penalty' => 0]],
        ]);
        $res = $ms->emails->getInsights('e1');

        expect($res['band'])->toBe('stellar');
        expect($res['checks'][0]['status'])->toBe('deferred');
    });

    it('getInsights throws not_found when insights are unavailable', function () {
        [$ms] = fakeClient(404, ['statusCode' => 404, 'name' => 'not_found', 'message' => 'Email not found']);

        try {
            $ms->emails->getInsights('missing');
            $this->fail('expected an ErrorException');
        } catch (MillionSend\Exceptions\ErrorException $e) {
            expect($e->getStatusCode())->toBe(404);
            expect($e->getErrorName())->toBe('not_found');
        }
    });
});

describe('deliverability', function () {
    it('get returns the account score', function () {
        $report = [
            'object' => 'deliverability',
            'score' => 8.7,
            'band' => 'good',
            'content_score' => 8.2,
            'outcome_score' => 9.1,
            'complaint_rate' => 0.0002,
            'hard_bounce_rate' => 0.001,
            'emails_sent' => 12345,
            'scored_recipients' => 23456,
            'window_days' => 30,
            'insufficient_outcome_data' => false,
            'guardrail_status' => 'ok',
            'score_version' => 1,
        ];
        [$ms, $spy] = fakeClient(200, $report);

        expect($ms->deliverability->get())->toEqual($report);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/deliverability');
    });

    it('get keeps null scores null when there is not enough data', function () {
        [$ms] = fakeClient(200, [
            'object' => 'deliverability',
            'score' => null,
            'band' => null,
            'content_score' => null,
            'outcome_score' => null,
            'complaint_rate' => 0,
            'hard_bounce_rate' => 0,
            'emails_sent' => 0,
            'scored_recipients' => 0,
            'window_days' => 30,
            'insufficient_outcome_data' => true,
            'guardrail_status' => 'ok',
            'score_version' => 1,
        ]);
        $res = $ms->deliverability->get();

        expect($res['score'])->toBeNull();
        expect($res['band'])->toBeNull();
        expect($res['insufficient_outcome_data'])->toBeTrue();
    });
});

describe('batch', function () {
    it('sends a bare array body with an idempotency key', function () {
        [$ms, $spy] = fakeClient(200, ['data' => [['id' => '1'], ['id' => '2']]]);
        $res = $ms->batch->send(
            [
                ['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => '1', 'text' => 'one'],
                ['from' => 'a@x.dev', 'to' => 'c@x.dev', 'subject' => '2', 'text' => 'two'],
            ],
            ['idempotencyKey' => 'batch-1'],
        );

        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/batch');
        $body = bodyOf($spy->at(0));
        expect(array_is_list($body))->toBeTrue();
        expect($body)->toHaveCount(2);
        expect($spy->at(0)->getHeaderLine('Idempotency-Key'))->toBe('batch-1');
        expect($res['data'])->toHaveCount(2);
    });
});

describe('contacts', function () {
    it('creates at the top-level /contacts', function () {
        [$ms, $spy] = fakeClient();

        $ms->contacts->create(['email' => 'c@x.dev', 'firstName' => 'Ada']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts');
        expect(bodyOf($spy->at(0)))->toEqual(['email' => 'c@x.dev', 'first_name' => 'Ada']);
    });

    it('addresses by string id and by email (email wins)', function () {
        [$ms, $spy] = fakeClient();

        $ms->contacts->get('c1');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1');

        $ms->contacts->get(['email' => 'c@x.dev']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev'));

        $ms->contacts->get(['id' => 'c1', 'email' => 'c@x.dev']);
        expect($spy->at(2)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev'));
    });

    it('update sends only provided keys (null clears)', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->update(['id' => 'c1', 'firstName' => null, 'unsubscribed' => true]);

        expect($spy->at(0)->getMethod())->toBe('PATCH');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1');
        expect(bodyOf($spy->at(0)))->toEqual(['first_name' => null, 'unsubscribed' => true]);
    });

    it('remove and list', function () {
        [$ms, $spy] = fakeClient();

        $ms->contacts->remove(['email' => 'c@x.dev']);
        expect($spy->at(0)->getMethod())->toBe('DELETE');
        expect($spy->at(0)->getUri()->getQuery())->toBe('');

        $ms->contacts->list(['after' => 'cur']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts');
        expect($spy->at(1)->getUri()->getQuery())->toBe('after=cur');
    });

    it('remove sends ?erase=true only when asked', function () {
        [$ms, $spy] = fakeClient();

        $ms->contacts->remove('c1', ['erase' => true]);
        expect($spy->at(0)->getMethod())->toBe('DELETE');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1');
        expect($spy->at(0)->getUri()->getQuery())->toBe('erase=true');

        $ms->contacts->remove('c1', ['erase' => false]);
        expect($spy->at(1)->getUri()->getQuery())->toBe('');
    });

    it('topics->get lists the effective subscriptions of a contact addressed by email', function () {
        $list = [
            'object' => 'list',
            'has_more' => false,
            'data' => [
                ['id' => 't1', 'name' => 'Insights', 'description' => null, 'subscription' => 'opt_in', 'explicit' => false, 'visibility' => 'public'],
                ['id' => 't2', 'name' => 'Releases', 'description' => 'Ship notes', 'subscription' => 'opt_out', 'explicit' => true, 'visibility' => 'private'],
            ],
        ];
        [$ms, $spy] = fakeClient(200, $list);

        expect($ms->contacts->topics->get('c@x.dev'))->toBe($list);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c%40x.dev/topics');
        expect($spy->at(0)->getUri()->getQuery())->toBe('');
        expect(bodyOf($spy->at(0)))->toBeNull();

        expect($ms->contacts->topics->list('c1'))->toBe($list);
        expect($spy->at(1)->getMethod())->toBe('GET');
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/c1/topics');
    });

    it('topics->update patches /contacts/:id/topics with the bare array', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'c1']);
        $ms->contacts->topics->update([
            'id' => 'c1',
            'topics' => [['id' => 't1', 'subscription' => 'opt_out']],
        ]);

        expect($spy->at(0)->getMethod())->toBe('PATCH');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1/topics');
        expect(bodyOf($spy->at(0)))->toEqual([['id' => 't1', 'subscription' => 'opt_out']]);
    });

    it('topics->update takes the resend-php ($idOrEmail, $topics) shape', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'c1']);
        $topics = [['id' => 't1', 'subscription' => 'opt_out'], ['id' => 't2', 'subscription' => 'opt_in']];

        $ms->contacts->topics->update('c@x.dev', $topics);
        expect($spy->at(0)->getMethod())->toBe('PATCH');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev') . '/topics');
        expect(bodyOf($spy->at(0)))->toBe($topics);

        $ms->contacts->topics->update('c1', ['topics' => $topics]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/c1/topics');
        expect(bodyOf($spy->at(1)))->toBe($topics);
    });

    it('updateTopics alias resolves the address by email', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'c1']);
        $ms->contacts->updateTopics([
            'email' => 'c@x.dev',
            'topics' => [['id' => 't1', 'subscription' => 'opt_in']],
        ]);

        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev') . '/topics');
    });
});

describe('broadcasts', function () {
    it('covers the full lifecycle', function () {
        [$ms, $spy] = fakeClient();

        $ms->broadcasts->create(['segmentId' => 's1', 'from' => 'a@x.dev', 'subject' => 'News', 'html' => '<p>hi</p>']);
        expect($spy->at(0)->getUri()->getPath())->toBe('/broadcasts');
        expect(bodyOf($spy->at(0)))->toEqual([
            'segment_id' => 's1',
            'from' => 'a@x.dev',
            'subject' => 'News',
            'html' => '<p>hi</p>',
        ]);

        $ms->broadcasts->get('b1');
        expect($spy->at(1)->getUri()->getPath())->toBe('/broadcasts/b1');

        $ms->broadcasts->list();
        expect($spy->at(2)->getUri()->getPath())->toBe('/broadcasts');

        $ms->broadcasts->update('b1', ['subject' => 'New']);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/broadcasts/b1');

        $ms->broadcasts->send('b1', ['scheduledAt' => '2999-01-01T00:00:00Z']);
        expect($spy->at(4)->getUri()->getPath())->toBe('/broadcasts/b1/send');
        expect(bodyOf($spy->at(4)))->toEqual(['scheduled_at' => '2999-01-01T00:00:00Z']);

        $ms->broadcasts->cancel('b1');
        expect($spy->at(5)->getUri()->getPath())->toBe('/broadcasts/b1/cancel');

        $ms->broadcasts->remove('b1');
        expect($spy->at(6)->getMethod())->toBe('DELETE');
    });

    it('send with no scheduledAt posts an empty object', function () {
        [$ms, $spy] = fakeClient();
        $ms->broadcasts->send('b1');

        expect((string) $spy->at(0)->getBody())->toBe('{}');
    });
});

describe('topics', function () {
    it('covers create/get/list/remove', function () {
        [$ms, $spy] = fakeClient();

        $ms->topics->create(['name' => 'Product', 'defaultSubscription' => 'opt_in']);
        expect(bodyOf($spy->at(0)))->toEqual(['name' => 'Product', 'default_subscription' => 'opt_in']);

        $ms->topics->get('t1');
        expect($spy->at(1)->getUri()->getPath())->toBe('/topics/t1');

        $ms->topics->list();
        expect($spy->at(2)->getUri()->getPath())->toBe('/topics');

        $ms->topics->remove('t1');
        expect($spy->at(3)->getMethod())->toBe('DELETE');
    });
});

describe('segments', function () {
    it('covers create/get/list/update/remove on /segments', function () {
        [$ms, $spy] = fakeClient();
        $filter = ['match' => 'all', 'conditions' => [['field' => 'email', 'op' => 'is_set']]];

        $ms->segments->create(['name' => 'Active', 'filter' => $filter]);
        expect($spy->at(0)->getUri()->getPath())->toBe('/segments');
        expect(bodyOf($spy->at(0)))->toEqual(['name' => 'Active', 'filter' => $filter]);

        $ms->segments->get('s1');
        expect($spy->at(1)->getUri()->getPath())->toBe('/segments/s1');

        $ms->segments->list(['before' => 'cur']);
        expect($spy->at(2)->getUri()->getPath())->toBe('/segments');
        expect($spy->at(2)->getUri()->getQuery())->toBe('before=cur');

        $ms->segments->update('s1', ['name' => 'Renamed', 'filter' => null]);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/segments/s1');
        expect(bodyOf($spy->at(3)))->toBe(['name' => 'Renamed', 'filter' => null]);

        $ms->segments->remove('s1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
    });
});

describe('emails (list, update, remove)', function () {
    it('lists with keyset params, reschedules and deletes', function () {
        [$ms, $spy] = fakeClient();

        $ms->emails->list(['limit' => 10, 'after' => 'cur']);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails');
        expect($spy->at(0)->getUri()->getQuery())->toBe('limit=10&after=cur');

        $ms->emails->update('e1', ['scheduledAt' => '2999-01-01T00:00:00Z']);
        expect($spy->at(1)->getMethod())->toBe('PATCH');
        expect($spy->at(1)->getUri()->getPath())->toBe('/emails/e1');
        expect(bodyOf($spy->at(1)))->toBe(['scheduled_at' => '2999-01-01T00:00:00Z']);

        $ms->emails->update('e1', ['scheduled_at' => 'in 2 hours']);
        expect(bodyOf($spy->at(2)))->toBe(['scheduled_at' => 'in 2 hours']);

        $ms->emails->remove('e1');
        expect($spy->at(3)->getMethod())->toBe('DELETE');
        expect($spy->at(3)->getUri()->getPath())->toBe('/emails/e1');
    });
});

describe('batch validation', function () {
    it('sends x-batch-validation and surfaces permissive-mode errors', function () {
        [$ms, $spy] = fakeClient(200, [
            'data' => [['id' => '1']],
            'errors' => [['index' => 1, 'message' => 'Invalid `to`']],
        ]);
        $res = $ms->batch->send(
            [
                ['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => '1', 'text' => 'one', 'replyTo' => 'r@x.dev'],
                ['from' => 'a@x.dev', 'to' => 'nope', 'subject' => '2', 'text' => 'two', 'headers' => ['X-A' => '1']],
            ],
            ['batch_validation' => 'permissive', 'idempotency_key' => 'b-1'],
        );

        $req = $spy->at(0);
        expect($req->getHeaderLine('x-batch-validation'))->toBe('permissive');
        expect($req->getHeaderLine('Idempotency-Key'))->toBe('b-1');
        expect(bodyOf($req))->toBe([
            ['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => '1', 'text' => 'one', 'reply_to' => 'r@x.dev'],
            ['from' => 'a@x.dev', 'to' => 'nope', 'subject' => '2', 'text' => 'two', 'headers' => ['X-A' => '1']],
        ]);
        expect($res['errors'])->toBe([['index' => 1, 'message' => 'Invalid `to`']]);
    });

    it('accepts the camelCase option name and omits the header by default', function () {
        [$ms, $spy] = fakeClient(200, ['data' => []]);
        $ms->batch->create([['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'text' => 't']]);
        expect($spy->at(0)->hasHeader('x-batch-validation'))->toBeFalse();

        $ms->batch->create(
            [['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'text' => 't']],
            ['batchValidation' => 'strict'],
        );
        expect($spy->at(1)->getHeaderLine('x-batch-validation'))->toBe('strict');
    });
});

describe('contacts (full body, batch, segments)', function () {
    it('create puts every field on the wire', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->create([
            'email' => 'c@x.dev',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'unsubscribed' => false,
            'properties' => ['plan' => 'pro', 'seats' => 3],
            'segments' => [['id' => 's1']],
            'topics' => [['id' => 't1', 'subscription' => 'opt_in']],
        ]);

        expect(bodyOf($spy->at(0)))->toBe([
            'email' => 'c@x.dev',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'unsubscribed' => false,
            'properties' => ['plan' => 'pro', 'seats' => 3],
            'segments' => [['id' => 's1']],
            'topics' => [['id' => 't1', 'subscription' => 'opt_in']],
        ]);
    });

    it('update takes the resend-php ($idOrEmail, $params) shape and clears with null', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->update('c@x.dev', ['lastName' => null, 'properties' => ['plan' => null]]);

        expect($spy->at(0)->getMethod())->toBe('PATCH');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev'));
        expect(bodyOf($spy->at(0)))->toBe(['last_name' => null, 'properties' => ['plan' => null]]);

        $ms->contacts->update(['id' => 'c1'], ['first_name' => 'Ada']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/c1');
        expect(bodyOf($spy->at(1)))->toBe(['first_name' => 'Ada']);
    });

    it('update with the single-array shape never leaks id/email into the body', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->update(['id' => 'c1', 'email' => 'c@x.dev', 'first_name' => 'Ada']);

        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev'));
        expect(bodyOf($spy->at(0)))->toBe(['first_name' => 'Ada']);
    });

    it('list routes to /segments/:id/contacts when a segment is given', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->list(['segmentId' => 's1', 'limit' => 5]);
        expect($spy->at(0)->getUri()->getPath())->toBe('/segments/s1/contacts');
        expect($spy->at(0)->getUri()->getQuery())->toBe('limit=5');

        $ms->contacts->list(['segment_id' => 's2']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/segments/s2/contacts');
    });

    it('list joins include into one comma-separated query parameter, on the team and segment lists alike', function () {
        [$ms, $spy] = fakeClient();
        $ms->contacts->list(['limit' => 100, 'include' => ['properties', 'topics']]);
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts');
        expect($spy->at(0)->getUri()->getQuery())->toBe('limit=100&include=' . rawurlencode('properties,topics'));

        $ms->contacts->list(['segmentId' => 's1', 'include' => ['topics']]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/segments/s1/contacts');
        expect($spy->at(1)->getUri()->getQuery())->toBe('include=topics');

        $ms->contacts->list();
        expect($spy->at(2)->getUri()->getQuery())->toBe('');
    });

    it('batch->create posts a bare array with on_conflict and the validation header', function () {
        $response = [
            'data' => [['object' => 'contact', 'index' => 0, 'id' => 'c1', 'status' => 'created']],
            'counts' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1],
            'errors' => [['index' => 1, 'message' => 'Invalid email']],
        ];
        [$ms, $spy] = fakeClient(200, $response);
        $res = $ms->contacts->batch->create(
            [
                ['email' => 'a@x.dev', 'firstName' => 'A', 'topics' => [['id' => 't1', 'subscription' => 'opt_out']]],
                ['email' => 'nope', 'last_name' => 'B'],
            ],
            ['on_conflict' => 'upsert', 'batch_validation' => 'permissive'],
        );

        $req = $spy->at(0);
        expect($req->getMethod())->toBe('POST');
        expect($req->getUri()->getPath())->toBe('/contacts/batch');
        expect($req->getUri()->getQuery())->toBe('on_conflict=upsert');
        expect($req->getHeaderLine('x-batch-validation'))->toBe('permissive');
        expect(bodyOf($req))->toBe([
            ['email' => 'a@x.dev', 'first_name' => 'A', 'topics' => [['id' => 't1', 'subscription' => 'opt_out']]],
            ['email' => 'nope', 'last_name' => 'B'],
        ]);
        expect($res)->toBe($response);

        $ms->contacts->batch->create([['email' => 'a@x.dev']], ['onConflict' => 'skip']);
        expect($spy->at(1)->getUri()->getQuery())->toBe('on_conflict=skip');
        expect($spy->at(1)->hasHeader('x-batch-validation'))->toBeFalse();

        $ms->contacts->batch->create([['email' => 'a@x.dev']]);
        expect($spy->at(2)->getUri()->getQuery())->toBe('');
    });

    it('batch->get posts ids and emails with include to /contacts/batch/get and surfaces missing entries', function () {
        $response = [
            'object' => 'list',
            'data' => [[
                'object' => 'contact', 'id' => 'c1', 'email' => 'a@x.dev', 'first_name' => 'A', 'last_name' => null,
                'created_at' => '2026-01-01T00:00:00.000Z', 'unsubscribed' => false,
                'properties' => ['plan' => ['type' => 'string', 'value' => 'pro']],
                'topics' => [['id' => 't1', 'name' => 'Insights', 'description' => null, 'subscription' => 'opt_in', 'explicit' => false, 'visibility' => 'public']],
            ]],
            'missing' => [['index' => 1, 'email' => 'nobody@x.dev']],
        ];
        [$ms, $spy] = fakeClient(200, $response);

        $res = $ms->contacts->batch->get(['c1', ['email' => 'nobody@x.dev']], ['include' => ['properties', 'topics']]);
        expect($res)->toBe($response);
        expect($res['missing'])->toBe([['index' => 1, 'email' => 'nobody@x.dev']]);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/batch/get');
        expect(bodyOf($spy->at(0)))->toBe([
            'contacts' => [['id' => 'c1'], ['email' => 'nobody@x.dev']],
            'include' => ['properties', 'topics'],
        ]);

        $ms->contacts->batch->get([['id' => 'c2']]);
        expect(bodyOf($spy->at(1)))->toBe(['contacts' => [['id' => 'c2']]]);
    });

    it('batch->remove posts ids or emails to /contacts/batch/remove', function () {
        $response = ['data' => [['object' => 'contact', 'contact' => 'c1', 'deleted' => true]]];
        [$ms, $spy] = fakeClient(200, $response);

        expect($ms->contacts->batch->remove(['ids' => ['c1', 'c2']]))->toBe($response);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/batch/remove');
        expect(bodyOf($spy->at(0)))->toBe(['ids' => ['c1', 'c2']]);

        $ms->contacts->batch->remove(['emails' => ['a@x.dev']]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/batch/remove');
        expect(bodyOf($spy->at(1)))->toBe(['emails' => ['a@x.dev']]);

        $ms->contacts->batch->remove(['emails' => ['a@x.dev'], 'erase' => true]);
        expect(bodyOf($spy->at(2)))->toBe(['emails' => ['a@x.dev'], 'erase' => true]);
    });

    it('preferencesLink posts without a body, addressed by id or email', function () {
        $response = ['object' => 'preferences_link', 'contact' => 'c1', 'url' => 'https://app.test/unsubscribe/tok'];
        [$ms, $spy] = fakeClient(200, $response);

        expect($ms->contacts->preferencesLink('c1'))->toBe($response);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1/preferences-link');
        expect((string) $spy->at(0)->getBody())->toBe('');

        $ms->contacts->preferencesLink('c@x.dev');
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev') . '/preferences-link');

        $ms->contacts->preferencesLink(['id' => 'c1', 'email' => 'c@x.dev']);
        expect($spy->at(2)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev') . '/preferences-link');
    });

    it('preferencesLink surfaces the 422 of an instance without hosted links', function () {
        [$ms] = fakeClient(422, ['statusCode' => 422, 'name' => 'validation_error', 'message' => 'APP_BASE_URL must be set']);

        try {
            $ms->contacts->preferencesLink('c1');
            $this->fail('expected an ErrorException');
        } catch (MillionSend\Exceptions\ErrorException $e) {
            expect($e->getStatusCode())->toBe(422);
            expect($e->getErrorName())->toBe('validation_error');
        }
    });

    it('segments->add and ->remove address the contact by id or email', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'c1']);

        $ms->contacts->segments->add('c1', 's1');
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contacts/c1/segments/s1');
        expect((string) $spy->at(0)->getBody())->toBe('');

        $ms->contacts->segments->remove('c@x.dev', 's1');
        expect($spy->at(1)->getMethod())->toBe('DELETE');
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts/' . rawurlencode('c@x.dev') . '/segments/s1');
    });
});

describe('broadcasts (full body)', function () {
    it('create puts every field on the wire', function () {
        [$ms, $spy] = fakeClient();
        $ms->broadcasts->create([
            'name' => 'Launch',
            'segmentId' => 's1',
            'from' => 'Acme <a@x.dev>',
            'subject' => 'News',
            'html' => '<p>hi</p>',
            'text' => 'hi',
            'replyTo' => ['r@x.dev'],
            'previewText' => 'Preview',
            'topicId' => 't1',
            'send' => true,
            'scheduledAt' => '2999-01-01T00:00:00Z',
        ]);

        expect(bodyOf($spy->at(0)))->toBe([
            'name' => 'Launch',
            'segment_id' => 's1',
            'from' => 'Acme <a@x.dev>',
            'subject' => 'News',
            'html' => '<p>hi</p>',
            'text' => 'hi',
            'reply_to' => ['r@x.dev'],
            'preview_text' => 'Preview',
            'topic_id' => 't1',
            'send' => true,
            'scheduled_at' => '2999-01-01T00:00:00Z',
        ]);
    });

    it('update clears topic and segment with explicit nulls', function () {
        [$ms, $spy] = fakeClient();
        $ms->broadcasts->update('b1', ['topicId' => null, 'segment_id' => null, 'name' => 'Renamed']);

        expect(bodyOf($spy->at(0)))->toBe(['topic_id' => null, 'segment_id' => null, 'name' => 'Renamed']);
    });
});

describe('topics (update, visibility)', function () {
    it('creates with visibility and patches name/visibility', function () {
        [$ms, $spy] = fakeClient();

        $ms->topics->create(['name' => 'Product', 'description' => 'Releases', 'default_subscription' => 'opt_in', 'visibility' => 'public']);
        expect(bodyOf($spy->at(0)))->toBe(['name' => 'Product', 'description' => 'Releases', 'default_subscription' => 'opt_in', 'visibility' => 'public']);

        $ms->topics->update('t1', ['visibility' => 'private']);
        expect($spy->at(1)->getMethod())->toBe('PATCH');
        expect($spy->at(1)->getUri()->getPath())->toBe('/topics/t1');
        expect(bodyOf($spy->at(1)))->toBe(['visibility' => 'private']);
    });
});

describe('suppressions', function () {
    it('covers add/create/get/list/remove', function () {
        [$ms, $spy] = fakeClient();

        $ms->suppressions->add(['email' => 'x@x.dev', 'origin' => 'manual']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/suppressions');
        expect(bodyOf($spy->at(0)))->toBe(['email' => 'x@x.dev', 'origin' => 'manual']);

        $ms->suppressions->create(['email' => 'y@x.dev']);
        expect(bodyOf($spy->at(1)))->toBe(['email' => 'y@x.dev']);

        $ms->suppressions->get('x@x.dev');
        expect($spy->at(2)->getMethod())->toBe('GET');
        expect($spy->at(2)->getUri()->getPath())->toBe('/suppressions/' . rawurlencode('x@x.dev'));

        $ms->suppressions->list(['limit' => 20, 'origin' => 'bounce']);
        expect($spy->at(3)->getUri()->getPath())->toBe('/suppressions');
        expect($spy->at(3)->getUri()->getQuery())->toBe('limit=20&origin=bounce');

        $ms->suppressions->remove('sup_1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
        expect($spy->at(4)->getUri()->getPath())->toBe('/suppressions/sup_1');
    });

    it('batch->add and batch->remove post to the batch endpoints', function () {
        [$ms, $spy] = fakeClient(200, ['data' => [['object' => 'suppression', 'id' => 's1']]]);

        $ms->suppressions->batch->add(['emails' => ['a@x.dev', 'b@x.dev'], 'origin' => 'unsubscribe']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/suppressions/batch/add');
        expect(bodyOf($spy->at(0)))->toBe(['emails' => ['a@x.dev', 'b@x.dev'], 'origin' => 'unsubscribe']);

        $ms->suppressions->batch->remove(['emails' => ['a@x.dev']]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/suppressions/batch/remove');
        expect(bodyOf($spy->at(1)))->toBe(['emails' => ['a@x.dev']]);

        $ms->suppressions->batch->remove(['ids' => ['s1', 's2']]);
        expect(bodyOf($spy->at(2)))->toBe(['ids' => ['s1', 's2']]);
    });
});

describe('domains', function () {
    it('covers create/list/get/verify/update/remove', function () {
        [$ms, $spy] = fakeClient();

        $ms->domains->create([
            'name' => 'acme.dev',
            'region' => 'us-east-1',
            'customReturnPath' => 'bounces',
            'openTracking' => true,
            'clickTracking' => false,
            'trackingSubdomain' => 'track',
        ]);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/domains');
        expect(bodyOf($spy->at(0)))->toBe([
            'name' => 'acme.dev',
            'region' => 'us-east-1',
            'custom_return_path' => 'bounces',
            'open_tracking' => true,
            'click_tracking' => false,
            'tracking_subdomain' => 'track',
        ]);

        $ms->domains->list(['limit' => 10]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/domains');
        expect($spy->at(1)->getUri()->getQuery())->toBe('limit=10');

        $ms->domains->get('d1');
        expect($spy->at(2)->getMethod())->toBe('GET');
        expect($spy->at(2)->getUri()->getPath())->toBe('/domains/d1');

        $ms->domains->verify('d1');
        expect($spy->at(3)->getMethod())->toBe('POST');
        expect($spy->at(3)->getUri()->getPath())->toBe('/domains/d1/verify');

        $ms->domains->update('d1', ['open_tracking' => false, 'clickTracking' => true, 'trackingSubdomain' => null]);
        expect($spy->at(4)->getMethod())->toBe('PATCH');
        expect($spy->at(4)->getUri()->getPath())->toBe('/domains/d1');
        expect(bodyOf($spy->at(4)))->toBe(['open_tracking' => false, 'click_tracking' => true, 'tracking_subdomain' => null]);

        $ms->domains->remove('d1');
        expect($spy->at(5)->getMethod())->toBe('DELETE');
        expect($spy->at(5)->getUri()->getPath())->toBe('/domains/d1');
    });
});

describe('webhooks', function () {
    it('covers create/list/get/update/remove', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'webhook', 'id' => 'w1', 'signing_secret' => 'whsec_1']);

        $res = $ms->webhooks->create([
            'endpoint' => 'https://acme.dev/hooks',
            'events' => ['email.sent', 'email.bounced'],
            'signingSecret' => 'whsec_1',
        ]);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/webhooks');
        expect(bodyOf($spy->at(0)))->toBe([
            'endpoint' => 'https://acme.dev/hooks',
            'events' => ['email.sent', 'email.bounced'],
            'signing_secret' => 'whsec_1',
        ]);
        expect($res['signing_secret'])->toBe('whsec_1');

        $ms->webhooks->list(['after' => 'cur']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/webhooks');
        expect($spy->at(1)->getUri()->getQuery())->toBe('after=cur');

        $ms->webhooks->get('w1');
        expect($spy->at(2)->getMethod())->toBe('GET');
        expect($spy->at(2)->getUri()->getPath())->toBe('/webhooks/w1');

        $ms->webhooks->update('w1', ['status' => 'disabled', 'events' => ['email.delivered']]);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/webhooks/w1');
        expect(bodyOf($spy->at(3)))->toBe(['status' => 'disabled', 'events' => ['email.delivered']]);

        $ms->webhooks->remove('w1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
        expect($spy->at(4)->getUri()->getPath())->toBe('/webhooks/w1');
    });

    it('get surfaces previous_secret_expires_at, set or null', function () {
        [$ms] = fakeClient(200, ['object' => 'webhook', 'id' => 'w1', 'signing_secret' => 'whsec_1', 'previous_secret_expires_at' => '2026-01-02T00:00:00.000Z']);
        expect($ms->webhooks->get('w1')['previous_secret_expires_at'])->toBe('2026-01-02T00:00:00.000Z');

        [$ms] = fakeClient(200, ['object' => 'webhook', 'id' => 'w1', 'signing_secret' => 'whsec_1', 'previous_secret_expires_at' => null]);
        expect($ms->webhooks->get('w1')['previous_secret_expires_at'])->toBeNull();
    });

    it('rotate posts {} by default and maps signingSecret/overlapHours to the wire', function () {
        $response = ['object' => 'webhook', 'id' => 'w1', 'signing_secret' => 'whsec_2', 'previous_secret_expires_at' => null];
        [$ms, $spy] = fakeClient(200, $response);

        expect($ms->webhooks->rotate('w1'))->toBe($response);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/webhooks/w1/rotate');
        expect((string) $spy->at(0)->getBody())->toBe('{}');

        $ms->webhooks->rotate('w1', ['signingSecret' => 'whsec_mine', 'overlapHours' => 0]);
        expect(bodyOf($spy->at(1)))->toBe(['signing_secret' => 'whsec_mine', 'overlap_hours' => 0]);

        $ms->webhooks->rotate('w1', ['overlap_hours' => 72]);
        expect(bodyOf($spy->at(2)))->toBe(['overlap_hours' => 72]);
    });
});

describe('apiKeys', function () {
    it('covers create/list/remove', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'k1', 'token' => 'ms_secret']);

        $res = $ms->apiKeys->create(['name' => 'ci', 'permission' => 'sending_access', 'domainId' => 'd1']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/api-keys');
        expect(bodyOf($spy->at(0)))->toBe(['name' => 'ci', 'permission' => 'sending_access', 'domain_id' => 'd1']);
        expect($res['token'])->toBe('ms_secret');

        $ms->apiKeys->list(['limit' => 5]);
        expect($spy->at(1)->getMethod())->toBe('GET');
        expect($spy->at(1)->getUri()->getPath())->toBe('/api-keys');
        expect($spy->at(1)->getUri()->getQuery())->toBe('limit=5');

        $ms->apiKeys->remove('k1');
        expect($spy->at(2)->getMethod())->toBe('DELETE');
        expect($spy->at(2)->getUri()->getPath())->toBe('/api-keys/k1');
    });
});

describe('templates', function () {
    it('covers create/list/get/update/remove/publish/duplicate', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'template', 'id' => 'tp1']);

        $ms->templates->create([
            'name' => 'Welcome',
            'html' => '<p>Hi {{{name}}}</p>',
            'subject' => 'Welcome',
            'text' => 'Hi',
            'alias' => 'welcome',
            'replyTo' => 'support@x.dev',
        ]);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/templates');
        expect(bodyOf($spy->at(0)))->toBe([
            'name' => 'Welcome',
            'html' => '<p>Hi {{{name}}}</p>',
            'subject' => 'Welcome',
            'text' => 'Hi',
            'alias' => 'welcome',
            'reply_to' => 'support@x.dev',
        ]);

        $ms->templates->list(['before' => 'cur']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/templates');
        expect($spy->at(1)->getUri()->getQuery())->toBe('before=cur');

        $ms->templates->get('welcome');
        expect($spy->at(2)->getMethod())->toBe('GET');
        expect($spy->at(2)->getUri()->getPath())->toBe('/templates/welcome');

        $ms->templates->update('welcome', ['alias' => null, 'subject' => null, 'text' => null, 'html' => '<p>v2</p>']);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/templates/welcome');
        expect(bodyOf($spy->at(3)))->toBe(['alias' => null, 'subject' => null, 'text' => null, 'html' => '<p>v2</p>']);

        $ms->templates->remove('tp1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
        expect($spy->at(4)->getUri()->getPath())->toBe('/templates/tp1');

        $ms->templates->publish('tp1');
        expect($spy->at(5)->getMethod())->toBe('POST');
        expect($spy->at(5)->getUri()->getPath())->toBe('/templates/tp1/publish');

        $ms->templates->duplicate('tp1');
        expect($spy->at(6)->getMethod())->toBe('POST');
        expect($spy->at(6)->getUri()->getPath())->toBe('/templates/tp1/duplicate');
    });
});

describe('contactProperties', function () {
    it('covers create/list/get/update/remove', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'contact_property', 'id' => 'p1']);

        $ms->contactProperties->create(['key' => 'plan', 'type' => 'string', 'fallbackValue' => 'free']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/contact-properties');
        expect(bodyOf($spy->at(0)))->toBe(['key' => 'plan', 'type' => 'string', 'fallback_value' => 'free']);

        $ms->contactProperties->list(['limit' => 50]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contact-properties');
        expect($spy->at(1)->getUri()->getQuery())->toBe('limit=50');

        $ms->contactProperties->get('p1');
        expect($spy->at(2)->getMethod())->toBe('GET');
        expect($spy->at(2)->getUri()->getPath())->toBe('/contact-properties/p1');

        $ms->contactProperties->update('p1', ['fallback_value' => null]);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/contact-properties/p1');
        expect(bodyOf($spy->at(3)))->toBe(['fallback_value' => null]);

        $ms->contactProperties->remove('p1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
        expect($spy->at(4)->getUri()->getPath())->toBe('/contact-properties/p1');
    });
});

describe('usage', function () {
    it('get returns the plan report', function () {
        $report = [
            'object' => 'usage',
            'cloud' => true,
            'plan' => 'pro',
            'limits' => ['emails_per_day' => 50000, 'domains' => 10],
            'today' => ['emails_sent' => 120, 'resets_at' => '2026-09-05T00:00:00Z'],
            'team' => ['id' => 'team_1', 'name' => 'Acme'],
            'app_url' => 'https://app.millionsend.com',
        ];
        [$ms, $spy] = fakeClient(200, $report);

        expect($ms->usage->get())->toBe($report);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/usage');
    });
});

const INBOX_ID = '6f1c9a1e-0000-4000-8000-0000000000a1';
const THREAD_ID = '6f1c9a1e-0000-4000-8000-0000000000b1';
const EMAIL_ID = '6f1c9a1e-0000-4000-8000-0000000000c1';
const DRAFT_ID = '6f1c9a1e-0000-4000-8000-0000000000d1';

/** @return array<string, mixed> */
function inboxFixture(): array
{
    return [
        'object' => 'inbox',
        'id' => INBOX_ID,
        'name' => 'Signup bot',
        'email_address' => 'signup-bot-7f3k@acme.agents.example.com',
        'domain_id' => '6f1c9a1e-0000-4000-8000-0000000000e1',
        'receiving_address' => null,
        'from_name' => null,
        'unread' => 1,
        'drafts' => 0,
        'last_received' => '2026-10-12T10:05:00.000Z',
        'created_at' => '2026-10-12T10:00:00.000Z',
        'kind' => 'agent',
        'shared' => true,
        'expires_at' => '2026-10-13T10:00:00.000Z',
        'quarantined' => 0,
        'pending_approval' => 0,
    ];
}

/** @return array<string, mixed> */
function draftFixture(): array
{
    return [
        'object' => 'inbox_draft',
        'id' => DRAFT_ID,
        'type' => 'reply',
        'to' => ['ada@example.com'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Re: Confirm your email',
        'html' => null,
        'text' => 'Thanks, confirmed.',
        'thread_id' => THREAD_ID,
        'reply_to_email_id' => EMAIL_ID,
        'email_id' => null,
        'created_at' => '2026-10-12T10:06:00.000Z',
        'updated_at' => '2026-10-12T10:06:00.000Z',
        'status' => 'pending_approval',
        'approval_reasons' => ['send_policy'],
        'forward' => false,
        'decided_by' => null,
        'decided_at' => null,
        'expires_at' => '2026-10-19T10:06:00.000Z',
    ];
}

/** @return array<string, mixed> */
function sentEmailFixture(): array
{
    return [
        'id' => EMAIL_ID,
        'direction' => 'outbound',
        'from' => 'signup-bot-7f3k@acme.agents.example.com',
        'to' => ['ada@example.com'],
        'cc' => [],
        'bcc' => [],
        'reply_to' => [],
        'subject' => 'Re: Confirm your email',
        'message_id' => null,
        'html' => null,
        'text' => 'Thanks, confirmed.',
        'attachments' => [],
        'read' => true,
        'received_at' => '2026-10-12T10:06:00.000Z',
        'email_id' => EMAIL_ID,
        'thread_id' => THREAD_ID,
        'inbox_id' => INBOX_ID,
        'status' => 'queued',
    ];
}

describe('emails->receiving', function () {
    it('list renames the filters and sends flags as true/false', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'list', 'has_more' => false, 'data' => [], 'held' => []]);

        $res = $ms->emails->receiving->list([
            'limit' => 10,
            'wait' => 30,
            'since' => '2026-10-12T10:00:00Z',
            'inboxId' => INBOX_ID,
            'to' => 'bot@acme.dev',
            'from' => 'example.com',
            'subjectContains' => 'code',
            'unread' => false,
            'include_quarantined' => true,
            'before' => null,
        ]);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/receiving');
        expect(queryOf($spy->at(0)))->toBe([
            'limit' => '10',
            'wait' => '30',
            'since' => '2026-10-12T10:00:00Z',
            'inbox_id' => INBOX_ID,
            'to' => 'bot@acme.dev',
            'from' => 'example.com',
            'subject_contains' => 'code',
            'unread' => 'false',
            'include_quarantined' => 'true',
        ]);
        expect($res['held'])->toBe([]);

        $ms->emails->receiving->list();
        expect($spy->at(1)->getUri()->getQuery())->toBe('');
    });

    it('get passes html_format and keeps the extension fields', function () {
        $detail = [
            'object' => 'email',
            'id' => EMAIL_ID,
            'extracted_text' => 'Your verification code is 482913',
            'extracted' => ['kinds' => ['code'], 'code' => '482913', 'link' => null, 'ambiguous' => false],
            'screening' => ['verdict' => 'clean', 'sender_trust' => 'brand_new_tier'],
            'future_field' => ['nested' => true],
        ];
        [$ms, $spy] = fakeClient(200, $detail);

        expect($ms->emails->receiving->get(EMAIL_ID, ['htmlFormat' => 'cid']))->toBe($detail);
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID);
        expect(queryOf($spy->at(0)))->toBe(['html_format' => 'cid']);

        $ms->emails->receiving->get(EMAIL_ID);
        expect($spy->at(1)->getUri()->getQuery())->toBe('');
    });

    it('reply renames the body, sends an Idempotency-Key and reads 200 as sent', function () {
        $sent = ['object' => 'email', 'id' => EMAIL_ID, 'thread_id' => THREAD_ID, 'inbox_id' => INBOX_ID];
        [$ms, $spy] = fakeClient(200, $sent);

        $res = $ms->emails->receiving->reply(EMAIL_ID, [
            'text' => 'Thanks, confirmed.',
            'replyAll' => true,
            'requiresApproval' => false,
            'from' => 'bot@acme.dev',
            'inboxId' => INBOX_ID,
        ], ['idempotencyKey' => 'reply-1']);

        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID . '/reply');
        expect(bodyOf($spy->at(0)))->toBe([
            'text' => 'Thanks, confirmed.',
            'reply_all' => true,
            'requires_approval' => false,
            'from' => 'bot@acme.dev',
            'inbox_id' => INBOX_ID,
        ]);
        expect($spy->at(0)->getHeaderLine('Idempotency-Key'))->toBe('reply-1');
        expect($res)->toBe(['kind' => 'sent', 'email' => $sent]);
    });

    it('a 202 answer is the draft held for approval', function () {
        [$ms] = fakeClient(202, draftFixture());

        $res = $ms->emails->receiving->reply(EMAIL_ID, ['text' => 'hi'], ['idempotency_key' => 'reply-2']);
        expect($res)->toBe(['kind' => 'draft', 'draft' => draftFixture()]);
    });

    it('forward sends to, mode and the note; errors throw', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'email', 'id' => EMAIL_ID, 'thread_id' => null, 'inbox_id' => null]);

        $res = $ms->emails->receiving->forward(EMAIL_ID, ['to' => ['ops@acme.dev'], 'text' => 'FYI', 'mode' => 'attachment'], ['idempotencyKey' => 'fwd-1']);
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID . '/forward');
        expect(bodyOf($spy->at(0)))->toBe(['to' => ['ops@acme.dev'], 'text' => 'FYI', 'mode' => 'attachment']);
        expect($spy->at(0)->getHeaderLine('Idempotency-Key'))->toBe('fwd-1');
        expect($res['kind'])->toBe('sent');

        [$ms] = fakeClient(403, ['statusCode' => 403, 'name' => 'email_quarantined', 'message' => 'held']);
        try {
            $ms->emails->receiving->forward(EMAIL_ID, ['to' => 'ops@acme.dev']);
            $this->fail('expected an ErrorException');
        } catch (MillionSend\Exceptions\ErrorException $e) {
            expect($e->getErrorName())->toBe('email_quarantined');
            expect($e->getStatusCode())->toBe(403);
        }
    });

    it('remove erases; attachments list and get', function () {
        [$ms, $spy] = fakeClient();

        $ms->emails->receiving->remove(EMAIL_ID);
        expect($spy->at(0)->getMethod())->toBe('DELETE');
        expect($spy->at(0)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID);

        $ms->emails->receiving->attachments->list(EMAIL_ID, ['limit' => 5]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID . '/attachments');
        expect($spy->at(1)->getUri()->getQuery())->toBe('limit=5');

        $ms->emails->receiving->attachments->get(EMAIL_ID, 'att-1');
        expect($spy->at(2)->getUri()->getPath())->toBe('/emails/receiving/' . EMAIL_ID . '/attachments/att-1');
    });
});

describe('inboxes', function () {
    it('create renames every field, safety included; no argument sends {}', function () {
        [$ms, $spy] = fakeClient(201, inboxFixture());

        $res = $ms->inboxes->create([
            'emailAddress' => 'support@acme.dev',
            'name' => 'Support',
            'fromName' => 'Acme Support',
            'kind' => 'human',
            'expiresIn' => null,
            'safety' => ['acceptFrom' => 'allowlist', 'allowlist' => ['*@acme.dev'], 'sendCap' => null, 'forward_to' => []],
        ]);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes');
        expect(bodyOf($spy->at(0)))->toBe([
            'email_address' => 'support@acme.dev',
            'name' => 'Support',
            'from_name' => 'Acme Support',
            'kind' => 'human',
            'expires_in' => null,
            'safety' => ['accept_from' => 'allowlist', 'allowlist' => ['*@acme.dev'], 'send_cap' => null, 'forward_to' => []],
        ]);
        expect($res)->toBe(inboxFixture());

        $ms->inboxes->create();
        expect((string) $spy->at(1)->getBody())->toBe('{}');
    });

    it('list, get by address, update and remove', function () {
        [$ms, $spy] = fakeClient(200, inboxFixture());

        $ms->inboxes->list(['limit' => 2, 'after' => INBOX_ID, 'kind' => 'agent']);
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes');
        expect(queryOf($spy->at(0)))->toBe(['limit' => '2', 'after' => INBOX_ID, 'kind' => 'agent']);

        $ms->inboxes->get('Support@acme.dev');
        expect($spy->at(1)->getUri()->getPath())->toBe('/inboxes/Support%40acme.dev');

        $ms->inboxes->update(INBOX_ID, ['name' => 'Bot', 'fromName' => '', 'expires_in' => '7d']);
        expect($spy->at(2)->getMethod())->toBe('PATCH');
        expect($spy->at(2)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID);
        expect(bodyOf($spy->at(2)))->toBe(['name' => 'Bot', 'from_name' => '', 'expires_in' => '7d']);

        $ms->inboxes->remove(INBOX_ID);
        expect($spy->at(3)->getMethod())->toBe('DELETE');
        expect($spy->at(3)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID);
    });

    it('passes unknown keys and enum values through', function () {
        $future = [...inboxFixture(), 'kind' => 'team', 'brand_new' => [1, 2]];
        [$ms] = fakeClient(200, $future);

        expect($ms->inboxes->get(INBOX_ID))->toBe($future);
    });

    it('safety, codes and quarantine', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'inbox_safety', 'inbox_id' => INBOX_ID]);

        $ms->inboxes->safety->get(INBOX_ID);
        expect($spy->at(0)->getMethod())->toBe('GET');
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/safety');

        $ms->inboxes->safety->update(INBOX_ID, ['sendCap' => 25, 'accept_from' => 'verified']);
        expect($spy->at(1)->getMethod())->toBe('PATCH');
        expect(bodyOf($spy->at(1)))->toBe(['send_cap' => 25, 'accept_from' => 'verified']);

        $ms->inboxes->codes->latest(INBOX_ID, ['from' => 'example.com', 'wait' => 20, 'maxAge' => 600]);
        expect($spy->at(2)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/codes/latest');
        expect(queryOf($spy->at(2)))->toBe(['from' => 'example.com', 'wait' => '20', 'max_age' => '600']);

        $ms->inboxes->quarantine->list(INBOX_ID, ['limit' => 3]);
        expect($spy->at(3)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/quarantine');
        expect($spy->at(3)->getUri()->getQuery())->toBe('limit=3');

        $ms->inboxes->quarantine->release(INBOX_ID, EMAIL_ID, ['trustSender' => true]);
        expect($spy->at(4)->getMethod())->toBe('POST');
        expect($spy->at(4)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/quarantine/' . EMAIL_ID . '/release');
        expect(bodyOf($spy->at(4)))->toBe(['trust_sender' => true]);

        $ms->inboxes->quarantine->release(INBOX_ID, EMAIL_ID);
        expect((string) $spy->at(5)->getBody())->toBe('{}');
    });

    it('labels list, create, update and remove', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'inbox_label', 'id' => 'l1']);

        $ms->inboxes->labels->list(INBOX_ID);
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/labels');

        $ms->inboxes->labels->create(INBOX_ID, ['name' => 'Billing', 'color' => '#FF8800']);
        expect($spy->at(1)->getMethod())->toBe('POST');
        expect(bodyOf($spy->at(1)))->toBe(['name' => 'Billing', 'color' => '#FF8800']);

        $ms->inboxes->labels->update(INBOX_ID, 'l1', ['name' => 'Invoices']);
        expect($spy->at(2)->getMethod())->toBe('PATCH');
        expect($spy->at(2)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/labels/l1');

        $ms->inboxes->labels->remove(INBOX_ID, 'l1');
        expect($spy->at(3)->getMethod())->toBe('DELETE');
        expect($spy->at(3)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/labels/l1');
    });

    it('threads list and search send lists comma-separated and drop empty ones', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'list', 'has_more' => false, 'data' => []]);

        $ms->inboxes->threads->list(INBOX_ID, [
            'folders' => ['inbox', 'archive'],
            'labels' => [],
            'read' => false,
            'includeQuarantined' => true,
            'limit' => 20,
        ]);
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/threads');
        expect(queryOf($spy->at(0)))->toBe([
            'folders' => 'inbox,archive',
            'read' => 'false',
            'include_quarantined' => 'true',
            'limit' => '20',
        ]);

        $ms->inboxes->threads->search(INBOX_ID, [
            'query' => '"invoice 42" -spam',
            'from' => ['ada', 'example.com'],
            'hasAttachment' => true,
            'startDate' => '2026-10-01',
            'end_date' => '2026-10-12',
        ]);
        expect($spy->at(1)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/threads/search');
        expect(queryOf($spy->at(1)))->toBe([
            'query' => '"invoice 42" -spam',
            'from' => 'ada,example.com',
            'has_attachment' => 'true',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-12',
        ]);
    });

    it('threads get, update and remove', function () {
        [$ms, $spy] = fakeClient(200, ['object' => 'inbox_thread', 'id' => THREAD_ID]);

        $ms->inboxes->threads->get(INBOX_ID, THREAD_ID);
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/threads/' . THREAD_ID);

        $ms->inboxes->threads->update(INBOX_ID, THREAD_ID, ['read' => true, 'folder' => 'archive', 'labelId' => 'l1']);
        expect($spy->at(1)->getMethod())->toBe('PATCH');
        expect(bodyOf($spy->at(1)))->toBe(['read' => true, 'folder' => 'archive', 'label_id' => 'l1']);

        $ms->inboxes->threads->remove(INBOX_ID, THREAD_ID);
        expect($spy->at(2)->getMethod())->toBe('DELETE');
    });

    it('thread emails list, get, reply and forward', function () {
        $base = '/inboxes/' . INBOX_ID . '/threads/' . THREAD_ID . '/emails';
        [$ms, $spy] = fakeClient(200, sentEmailFixture());

        $ms->inboxes->threads->emails->list(INBOX_ID, THREAD_ID, ['after' => EMAIL_ID, 'includeQuarantined' => true]);
        expect($spy->at(0)->getUri()->getPath())->toBe($base);
        expect(queryOf($spy->at(0)))->toBe(['after' => EMAIL_ID, 'include_quarantined' => 'true']);

        $ms->inboxes->threads->emails->get(INBOX_ID, THREAD_ID, EMAIL_ID);
        expect($spy->at(1)->getUri()->getPath())->toBe($base . '/' . EMAIL_ID);
        expect($spy->at(1)->getUri()->getQuery())->toBe('');

        $ms->inboxes->threads->emails->get(INBOX_ID, THREAD_ID, EMAIL_ID, ['include_quarantined' => true]);
        expect(queryOf($spy->at(2)))->toBe(['include_quarantined' => 'true']);

        $res = $ms->inboxes->threads->emails->reply(INBOX_ID, THREAD_ID, EMAIL_ID, ['text' => 'Done.', 'replyAll' => true], ['idempotencyKey' => 'r-1']);
        expect($spy->at(3)->getMethod())->toBe('POST');
        expect($spy->at(3)->getUri()->getPath())->toBe($base . '/' . EMAIL_ID . '/reply');
        expect(bodyOf($spy->at(3)))->toBe(['text' => 'Done.', 'reply_all' => true]);
        expect($spy->at(3)->getHeaderLine('Idempotency-Key'))->toBe('r-1');
        expect($res)->toBe(['kind' => 'sent', 'email' => sentEmailFixture()]);

        [$held, $spy] = fakeClient(202, draftFixture());
        $res = $held->inboxes->threads->emails->forward(INBOX_ID, THREAD_ID, EMAIL_ID, ['to' => 'stranger@example.com', 'requiresApproval' => true], ['idempotency_key' => 'f-1']);
        expect($spy->at(0)->getUri()->getPath())->toBe($base . '/' . EMAIL_ID . '/forward');
        expect(bodyOf($spy->at(0)))->toBe(['to' => 'stranger@example.com', 'requires_approval' => true]);
        expect($spy->at(0)->getHeaderLine('Idempotency-Key'))->toBe('f-1');
        expect($res)->toBe(['kind' => 'draft', 'draft' => draftFixture()]);
    });

    it('drafts list, create, get, update, remove and reject', function () {
        $base = '/inboxes/' . INBOX_ID . '/drafts';
        [$ms, $spy] = fakeClient(201, draftFixture());

        $ms->inboxes->drafts->list(INBOX_ID, ['limit' => 5]);
        expect($spy->at(0)->getUri()->getPath())->toBe($base);
        expect($spy->at(0)->getUri()->getQuery())->toBe('limit=5');

        $res = $ms->inboxes->drafts->create(INBOX_ID, ['to' => 'ada@example.com', 'text' => 'Hi', 'threadId' => THREAD_ID, 'replyToEmailId' => EMAIL_ID]);
        expect($spy->at(1)->getMethod())->toBe('POST');
        expect(bodyOf($spy->at(1)))->toBe(['to' => 'ada@example.com', 'text' => 'Hi', 'thread_id' => THREAD_ID, 'reply_to_email_id' => EMAIL_ID]);
        expect($res['status'])->toBe('pending_approval');

        $ms->inboxes->drafts->get(INBOX_ID, DRAFT_ID);
        expect($spy->at(2)->getUri()->getPath())->toBe($base . '/' . DRAFT_ID);

        $ms->inboxes->drafts->update(INBOX_ID, DRAFT_ID, ['cc' => null, 'subject' => null]);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect(bodyOf($spy->at(3)))->toBe(['cc' => null, 'subject' => null]);

        $ms->inboxes->drafts->remove(INBOX_ID, DRAFT_ID);
        expect($spy->at(4)->getMethod())->toBe('DELETE');

        $ms->inboxes->drafts->reject(INBOX_ID, DRAFT_ID);
        expect($spy->at(5)->getMethod())->toBe('POST');
        expect($spy->at(5)->getUri()->getPath())->toBe($base . '/' . DRAFT_ID . '/reject');
        expect((string) $spy->at(5)->getBody())->toBe('');
    });

    it('drafts->send posts no body with an Idempotency-Key; 200 sent, 202 held', function () {
        $sent = ['object' => 'inbox_draft', 'id' => DRAFT_ID, 'thread_id' => THREAD_ID, 'email_id' => EMAIL_ID];
        [$ms, $spy] = fakeClient(200, $sent);

        $res = $ms->inboxes->drafts->send(INBOX_ID, DRAFT_ID, ['idempotencyKey' => 'send-1']);
        expect($spy->at(0)->getMethod())->toBe('POST');
        expect($spy->at(0)->getUri()->getPath())->toBe('/inboxes/' . INBOX_ID . '/drafts/' . DRAFT_ID . '/send');
        expect((string) $spy->at(0)->getBody())->toBe('');
        expect($spy->at(0)->hasHeader('Content-Type'))->toBeFalse();
        expect($spy->at(0)->getHeaderLine('Idempotency-Key'))->toBe('send-1');
        expect($res)->toBe(['kind' => 'sent', 'email' => $sent]);

        [$held] = fakeClient(202, draftFixture());
        expect($held->inboxes->drafts->send(INBOX_ID, DRAFT_ID))->toBe(['kind' => 'draft', 'draft' => draftFixture()]);

        [$refused] = fakeClient(403, ['statusCode' => 403, 'name' => 'approval_required', 'message' => 'waits for a person']);
        try {
            $refused->inboxes->drafts->send(INBOX_ID, DRAFT_ID);
            $this->fail('expected an ErrorException');
        } catch (MillionSend\Exceptions\ErrorException $e) {
            expect($e->getErrorName())->toBe('approval_required');
        }
    });
});

describe('inbox fields on existing resources', function () {
    it('webhooks, api keys and domains carry the new fields', function () {
        [$ms, $spy] = fakeClient();

        $ms->webhooks->create(['endpoint' => 'https://acme.dev/hooks', 'events' => ['inbox.email.received'], 'inboxIds' => [INBOX_ID]]);
        expect(bodyOf($spy->at(0)))->toBe(['endpoint' => 'https://acme.dev/hooks', 'events' => ['inbox.email.received'], 'inbox_ids' => [INBOX_ID]]);

        $ms->webhooks->update('w1', ['inboxIds' => null]);
        expect(bodyOf($spy->at(1)))->toBe(['inbox_ids' => null]);

        $ms->apiKeys->create(['name' => 'agent', 'permission' => 'full_access', 'inboxId' => INBOX_ID]);
        expect(bodyOf($spy->at(2)))->toBe(['name' => 'agent', 'permission' => 'full_access', 'inbox_id' => INBOX_ID]);

        $ms->domains->update('d1', ['receivingCatchAll' => false]);
        expect(bodyOf($spy->at(3)))->toBe(['receiving_catch_all' => false]);
    });
});
