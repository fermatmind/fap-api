<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAttemptSubmissionJob;
use Tests\TestCase;

final class ProcessAttemptSubmissionJobTest extends TestCase
{
    public function test_process_attempt_submission_job_queue_binding_from_config(): void
    {
        config()->set('queue.default', 'database');
        config()->set('fap.queue.attempt_connection', null);

        $defaultJob = new ProcessAttemptSubmissionJob('submission-default');
        $this->assertSame((string) config('queue.default'), $defaultJob->connection);

        config()->set('fap.queue.attempt_connection', 'redis');
        $redisJob = new ProcessAttemptSubmissionJob('submission-redis');
        $this->assertSame('redis', $redisJob->connection);

        config()->set('fap.queue.attempt_queue', 'attempts');
        $queueJob = new ProcessAttemptSubmissionJob('submission-queue');
        $this->assertSame('attempts', $queueJob->queue);
    }
}
