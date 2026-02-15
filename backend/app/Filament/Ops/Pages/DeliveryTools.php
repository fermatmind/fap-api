<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Models\AdminApproval;
use App\Support\OrgContext;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryTools extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Delivery Tools';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'delivery-tools';

    protected static string $view = 'filament.ops.pages.delivery-tools';

    public string $orderNo = '';

    public string $reason = '';

    public string $tool = 'regenerate_report';

    public string $statusMessage = '';

    public function requestAction(): void
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $orderNo = trim($this->orderNo);
        $reason = trim($this->reason);

        if ($orderNo === '' || $reason === '') {
            $this->statusMessage = 'order_no and reason are required.';

            return;
        }

        $order = DB::table('orders')
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            $this->statusMessage = 'order not found.';

            return;
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? (int) $user->getAuthIdentifier()
            : null;

        $approval = AdminApproval::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'type' => AdminApproval::TYPE_MANUAL_GRANT,
            'status' => AdminApproval::STATUS_PENDING,
            'requested_by_admin_user_id' => $adminId,
            'reason' => $reason,
            'payload_json' => [
                'tool' => $this->tool,
                'order_no' => $orderNo,
                'attempt_id' => (string) ($order->target_attempt_id ?? ''),
            ],
            'correlation_id' => (string) Str::uuid(),
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => $adminId,
            'action' => 'approval_requested',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => $adminId,
                'org_id' => $orgId,
                'order_no' => $orderNo,
                'reason' => $reason,
                'correlation_id' => (string) $approval->correlation_id,
                'type' => AdminApproval::TYPE_MANUAL_GRANT,
                'tool' => $this->tool,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'user_agent' => (string) (request()?->userAgent() ?? ''),
            'request_id' => (string) (request()?->attributes->get('request_id') ?? ''),
            'created_at' => now(),
        ]);

        $this->statusMessage = 'Request submitted: '.$approval->id;
    }
}
