<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Attempts\AttemptSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAttemptSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /** @var array<int,int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public string $submissionId,
    ) {
        $connection = config('fap.queue.attempt_connection');
        $this->onConnection((is_string($connection) && $connection !== '') ? $connection : (string) config('queue.default'));
        $this->onQueue(config('fap.queue.attempt_queue', 'attempts'));
    }

    public function handle(AttemptSubmissionService $service): void
    {
        $service->process($this->submissionId);
    }

    public function failed(Throwable $exception): void
    {
        app(AttemptSubmissionService::class)->recordTerminalJobFailure(
            $this->submissionId,
            $exception,
            (int) $this->attempts(),
            (int) $this->tries,
            is_string($this->connection) ? $this->connection : null,
            is_string($this->queue) ? $this->queue : null,
        );
    }
}
