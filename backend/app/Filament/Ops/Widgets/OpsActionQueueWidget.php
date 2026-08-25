<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Models\AdminApproval;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

final class OpsActionQueueWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static string $view = 'filament.ops.widgets.ops-action-queue-widget';

    protected int|string|array $columnSpan = 'full';

    /** @return array{rows:list<array{label:string,count:int,tone:string,url:string}>} */
    protected function getViewData(): array
    {
        $rows = [];

        if (SchemaBaseline::hasTable('failed_jobs')) {
            $rows[] = $this->row(
                __('ops.widgets.action_queue.failed_jobs'),
                (int) DB::table('failed_jobs')->count(),
                '/ops/queue-monitor',
            );
        }

        if (ContentAccess::canReview() && SchemaBaseline::hasTable('admin_approvals')) {
            $rows[] = $this->row(
                __('ops.widgets.action_queue.pending_approvals'),
                (int) DB::table('admin_approvals')->where('status', AdminApproval::STATUS_PENDING)->count(),
                '/ops/admin-approvals',
            );
        }

        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        if (OpsMetricsAccess::canViewCommerceMetrics() && $orgId > 0) {
            if (SchemaBaseline::hasTable('orders')) {
                $rows[] = $this->row(
                    __('ops.widgets.action_queue.ungranted_orders'),
                    (int) DB::table('orders')
                        ->where('org_id', $orgId)
                        ->where('payment_state', 'paid')
                        ->where('grant_state', '!=', 'granted')
                        ->count(),
                    '/ops/orders',
                );
            }

            if (SchemaBaseline::hasTable('payment_events')) {
                $rows[] = $this->row(
                    __('ops.widgets.action_queue.webhook_failures'),
                    (int) DB::table('payment_events')
                        ->where('org_id', $orgId)
                        ->where(function ($query): void {
                            $query->where('signature_ok', 0)
                                ->orWhereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                                ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
                        })
                        ->count(),
                    '/ops/webhook-monitor',
                );
            }
        }

        return ['rows' => $rows];
    }

    /** @return array{label:string,count:int,tone:string,url:string} */
    private function row(string $label, int $count, string $url): array
    {
        return [
            'label' => $label,
            'count' => $count,
            'tone' => $count > 0 ? 'warning' : 'success',
            'url' => $url,
        ];
    }
}
