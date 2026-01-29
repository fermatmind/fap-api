<?php

namespace App\Jobs;

use App\Services\Report\ReportSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateReportSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $ctx;

    public function __construct(array $ctx)
    {
        $this->ctx = $ctx;
    }

    public function handle(ReportSnapshotStore $store): void
    {
        $store->createSnapshotForAttempt($this->ctx);
    }
}
