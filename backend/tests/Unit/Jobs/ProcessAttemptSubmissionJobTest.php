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

    public function test_process_attempt_submission_job_backoff_adds_bounded_jitter(): void
    {
        $job = new ProcessAttemptSubmissionJob('submission-jitter');

        $backoff = ProcessAttemptSubmissionJob::withBackoffJitterResolverForTest(
            static fn (int $baseSeconds, int $attemptIndex): int => [0, 2, 5][$attemptIndex] ?? 0,
            static fn (): array => $job->backoff(),
        );

        $this->assertSame([5, 17, 35], $backoff);
    }

    public function test_process_attempt_submission_job_backoff_jitter_is_clamped_to_safe_bounds(): void
    {
        $job = new ProcessAttemptSubmissionJob('submission-clamped-jitter');

        $backoff = ProcessAttemptSubmissionJob::withBackoffJitterResolverForTest(
            static fn (int $baseSeconds, int $attemptIndex): int => [-10, 999, 1][$attemptIndex] ?? 0,
            static fn (): array => $job->backoff(),
        );

        $this->assertSame([5, 20, 31], $backoff);
        $this->assertSame([5, 15, 30], array_map(
            static fn (int $seconds, int $index): int => $seconds - [0, 5, 1][$index],
            $backoff,
            array_keys($backoff),
        ));
    }

    public function test_process_attempt_submission_job_backoff_can_vary_within_bounds(): void
    {
        $job = new ProcessAttemptSubmissionJob('submission-variable-jitter');

        $minimumBackoff = ProcessAttemptSubmissionJob::withBackoffJitterResolverForTest(
            static fn (): int => 0,
            static fn (): array => $job->backoff(),
        );
        $maximumBackoff = ProcessAttemptSubmissionJob::withBackoffJitterResolverForTest(
            static fn (): int => 5,
            static fn (): array => $job->backoff(),
        );

        $this->assertSame([5, 15, 30], $minimumBackoff);
        $this->assertSame([10, 20, 35], $maximumBackoff);
        $this->assertNotSame($minimumBackoff, $maximumBackoff);
    }
}
