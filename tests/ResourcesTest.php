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

        $ms->contacts->list(['after' => 'cur']);
        expect($spy->at(1)->getUri()->getPath())->toBe('/contacts');
        expect($spy->at(1)->getUri()->getQuery())->toBe('after=cur');
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

        $ms->segments->update('s1', ['name' => 'Renamed']);
        expect($spy->at(3)->getMethod())->toBe('PATCH');
        expect($spy->at(3)->getUri()->getPath())->toBe('/segments/s1');

        $ms->segments->remove('s1');
        expect($spy->at(4)->getMethod())->toBe('DELETE');
    });
});
