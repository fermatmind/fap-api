<?php

declare(strict_types=1);

namespace App\Jobs\Commerce;

use App\Models\CareerRefundRequest;
use App\Services\Commerce\CareerRefundWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileCareerRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public string $requestId)
    {
        $this->onConnection('database')->onQueue('ops');
    }

    public function handle(CareerRefundWorkflow $workflow): void
    {
        $row = CareerRefundRequest::where('org_id', 0)->whereKey($this->requestId)->first();
        if ($row && in_array($row->status, ['approved', 'processing', 'attention', 'pending'], true)) {
            $workflow->reconcile($row);
        }
    }
}
