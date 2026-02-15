<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Approvals\ApprovalExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteApprovalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [5, 10, 20];

    public function __construct(public string $approvalId)
    {
        $this->onConnection('database');
        $this->onQueue('ops');
    }

    public function handle(ApprovalExecutor $executor): void
    {
        $executor->execute($this->approvalId);
    }
}
