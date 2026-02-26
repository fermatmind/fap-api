<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Attempts\AttemptSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $this->onConnection('database');
        $this->onQueue('attempts');
    }

    public function handle(AttemptSubmissionService $service): void
    {
        $service->process($this->submissionId);
    }
}

