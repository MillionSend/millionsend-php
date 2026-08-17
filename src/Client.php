<?php

declare(strict_types=1);

namespace MillionSend;

use MillionSend\Resources\Batch;
use MillionSend\Resources\Broadcasts;
use MillionSend\Resources\Contacts;
use MillionSend\Resources\Emails;
use MillionSend\Resources\Segments;
use MillionSend\Resources\Topics;

/**
 * The MillionSend client. Build it once via {@see MillionSend::client()} and
 * reach every resource through its public accessors, e.g. `$ms->emails->send(...)`.
 */
final class Client
{
    public readonly Emails $emails;
    public readonly Batch $batch;
    public readonly Contacts $contacts;
    public readonly Topics $topics;
    public readonly Broadcasts $broadcasts;
    public readonly Segments $segments;

    public function __construct(HttpClient $http)
    {
        $this->emails = new Emails($http);
        $this->batch = new Batch($http);
        $this->contacts = new Contacts($http);
        $this->topics = new Topics($http);
        $this->broadcasts = new Broadcasts($http);
        $this->segments = new Segments($http);
    }
}
