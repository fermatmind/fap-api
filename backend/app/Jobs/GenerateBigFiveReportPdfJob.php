<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GenerateBigFiveReportPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 10, 20];

    public function __construct(
        public int $orgId,
        public string $attemptId,
        public string $triggerSource,
        public ?string $orderNo = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $job = new GenerateReportPdfJob(
            $this->orgId,
            $this->attemptId,
            $this->triggerSource,
            $this->orderNo
        );

        app()->call([$job, 'handle']);
    }
}
