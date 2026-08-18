<?php

declare(strict_types=1);

use MillionSend\Client;
use MillionSend\Exceptions\ErrorException;
use MillionSend\MillionSend;

describe('construction', function () {
    it('throws without an API key and without the env var', function () {
        $prev = getenv('MILLIONSEND_API_KEY');
        putenv('MILLIONSEND_API_KEY');

        expect(fn () => MillionSend::client())->toThrow(InvalidArgumentException::class);

        if ($prev !== false) {
            putenv('MILLIONSEND_API_KEY=' . $prev);
        }
    });

    it('falls back to MILLIONSEND_API_KEY', function () {
        $prev = getenv('MILLIONSEND_API_KEY');
        putenv('MILLIONSEND_API_KEY=ms_env');

        expect(MillionSend::client())->toBeInstanceOf(Client::class);

        putenv($prev === false ? 'MILLIONSEND_API_KEY' : 'MILLIONSEND_API_KEY=' . $prev);
    });

    it('rejects invalid request deadlines', function () {
        expect(fn () => MillionSend::client('ms_test', null, ['timeout' => 0.0]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('strips a trailing slash from the base URL', function () {
        [$ms, $spy] = fakeClient(200, ['id' => 'e1'], 'https://api.test/');
        $ms->emails->get('e1');

        expect((string) $spy->last()->getUri())->toBe('https://api.test/emails/e1');
    });
});

describe('request wiring', function () {
    it('sets Bearer auth, Accept, User-Agent and Content-Type on writes', function () {
        [$ms, $spy] = fakeClient();
        $ms->emails->send(['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'html' => '<p>h</p>']);

        $req = $spy->last();
        expect($req->getHeaderLine('Authorization'))->toBe('Bearer ms_test');
        expect($req->getHeaderLine('Accept'))->toBe('application/json');
        expect($req->getHeaderLine('Content-Type'))->toBe('application/json');
        expect($req->getHeaderLine('User-Agent'))->toMatch('/^millionsend-php\/\d/');
    });

    it('maps camelCase inputs to the snake_case wire and omits absent keys', function () {
        [$ms, $spy] = fakeClient();
        $ms->emails->send([
            'from' => 'a@x.dev',
            'to' => ['b@x.dev'],
            'subject' => 's',
            'html' => '<p>h</p>',
            'replyTo' => 'r@x.dev',
            'scheduledAt' => '2999-01-01T00:00:00Z',
        ]);

        expect(bodyOf($spy->last()))->toEqual([
            'from' => 'a@x.dev',
            'to' => ['b@x.dev'],
            'subject' => 's',
            'html' => '<p>h</p>',
            'reply_to' => 'r@x.dev',
            'scheduled_at' => '2999-01-01T00:00:00Z',
        ]);
    });

    it('sends Idempotency-Key on POST when provided', function () {
        [$ms, $spy] = fakeClient();
        $ms->emails->send(
            ['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'text' => 't'],
            ['idempotencyKey' => 'key-123'],
        );

        expect($spy->last()->getHeaderLine('Idempotency-Key'))->toBe('key-123');
    });

    it('never sends an Idempotency-Key on a GET', function () {
        [$ms, $spy] = fakeClient();
        $ms->emails->get('e1');

        expect($spy->last()->getHeaderLine('Idempotency-Key'))->toBe('');
    });

    it('returns the decoded body on 2xx', function () {
        [$ms] = fakeClient(200, ['id' => 'abc']);
        $res = $ms->emails->send(['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'text' => 't']);

        expect($res)->toEqual(['id' => 'abc']);
    });

    it('throws a normalized ErrorException on non-2xx', function () {
        [$ms] = fakeClient(422, ['statusCode' => 422, 'name' => 'validation_error', 'message' => 'bad']);

        try {
            $ms->emails->send(['from' => 'a@x.dev', 'to' => 'b@x.dev', 'subject' => 's', 'text' => 't']);
            $this->fail('expected an ErrorException');
        } catch (ErrorException $e) {
            expect($e->getStatusCode())->toBe(422);
            expect($e->getErrorName())->toBe('validation_error');
            expect($e->getErrorMessage())->toBe('bad');
        }
    });

    it('surfaces a transport failure as statusCode null', function () {
        $ms = failingClient();

        try {
            $ms->emails->get('e1');
            $this->fail('expected an ErrorException');
        } catch (ErrorException $e) {
            expect($e->getStatusCode())->toBeNull();
            expect($e->getErrorName())->toBe('application_error');
            expect($e->getMessage())->toContain('ECONNREFUSED');
        }
    });

    it('falls back to a generic error when the body is not the canonical shape', function () {
        [$ms] = fakeClient(500, 'gateway boom');

        try {
            $ms->emails->get('e1');
            $this->fail('expected an ErrorException');
        } catch (ErrorException $e) {
            expect($e->getErrorName())->toBe('application_error');
            expect($e->getMessage())->toBe('Request failed with status 500');
            expect($e->getStatusCode())->toBe(500);
        }
    });
});
