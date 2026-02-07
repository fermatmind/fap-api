<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueDlqService;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;

class AdminQueueController extends Controller
{
    public function metrics(Request $request, QueueDlqService $queueDlqService)
    {
        $this->assertPermission(PermissionNames::ADMIN_OPS_READ);

        return response()->json([
            'ok' => true,
            'data' => $queueDlqService->metrics(),
        ]);
    }

    public function replay(
        Request $request,
        string $failed_job_id,
        QueueDlqService $queueDlqService,
        AuditLogger $auditLogger
    ) {
        $this->assertPermission(PermissionNames::ADMIN_OPS_WRITE);

        if (!preg_match('/^\d+$/', $failed_job_id)) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'failed job not found.',
            ], 404);
        }

        $force = $request->boolean('force', false);
        $requestedBy = $this->resolveRequestedBy($request);
        $failedJobIdInt = (int) $failed_job_id;

        $result = $queueDlqService->replayFailedJob($failedJobIdInt, $requestedBy, $force);

        $auditLogger->log(
            $request,
            'queue_dlq_replay',
            'failed_job',
            $failed_job_id,
            [
                'force' => $force,
                'status_intended' => $result['status'] ?? '',
                'result' => $result,
            ]
        );

        $status = (string) ($result['status'] ?? '');
        if ($status === 'not_found') {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'failed job not found.',
            ], 404);
        }

        if ($status === 'invalid_payload') {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'failed job payload invalid.',
                'data' => $result,
            ], 422);
        }

        if ($status === 'push_failed') {
            return response()->json([
                'ok' => false,
                'error' => 'REPLAY_FAILED',
                'message' => 'failed job replay failed.',
                'data' => $result,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'data' => $result,
        ]);
    }

    private function assertPermission(string $permission): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if ($user !== null) {
            app(RbacService::class)->assertCan($user, $permission);
        }
    }

    private function resolveRequestedBy(Request $request): string
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if ($user !== null && property_exists($user, 'id')) {
            return 'admin:' . (string) $user->id;
        }

        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return 'admin_token:' . $requestId;
        }

        return 'admin_token';
    }
}
