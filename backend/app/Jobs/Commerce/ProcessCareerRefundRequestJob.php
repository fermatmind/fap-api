<?php

declare(strict_types=1);

namespace App\Jobs\Commerce;

use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Models\CareerRefundRequest;
use App\Services\Approvals\HighRiskApprovalService;
use App\Services\Commerce\CareerRefundWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** @review-surface refund_approval */
class ProcessCareerRefundRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public array $backoff = [10, 30, 60];

    public function __construct(public string $approvalId)
    {
        $this->onConnection('database')->onQueue('ops');
    }

    public function handle(CareerRefundWorkflow $workflow): void
    {
        $approval = AdminApproval::withoutGlobalScopes()->where('org_id', 0)->find($this->approvalId);
        if ($approval?->status === AdminApproval::STATUS_EXECUTING) {
            $this->release(5);

            return;
        }
        if (! $approval || $approval->type !== AdminApproval::TYPE_REFUND || $approval->status !== AdminApproval::STATUS_EXECUTED) {
            return;
        }
        // The native executor claimed fresh execution authorization before enqueueing
        // this channel job, as for RefundOrderJob. Slow PSP calls cannot expire the
        // remaining orders in the operator's already-authorized batch.
        app(HighRiskApprovalService::class)->assertExecutable($approval);
        if (! CareerRefundWorkflow::canOperate(AdminUser::find($approval->approved_by_admin_user_id))) {
            CareerRefundRequest::where('org_id', 0)->where('approval_id', $approval->id)
                ->update(['status' => 'attention', 'error_code' => 'OPERATOR_PERMISSION_CHANGED']);

            return;
        }
        $result = $workflow->executeApproved($approval);
        if (! $result->ok) {
            CareerRefundRequest::where('org_id', 0)->where('approval_id', $approval->id)
                ->where('status', '!=', 'succeeded')->update(['status' => 'attention', 'error_code' => $result->code]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CareerRefundRequest::where('org_id', 0)->where('approval_id', $this->approvalId)
            ->whereNotIn('status', ['succeeded', 'rejected', 'withdrawn'])
            ->update(['status' => 'attention', 'error_code' => 'REFUND_JOB_FAILED']);
    }
}
