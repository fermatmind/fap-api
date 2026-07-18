<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Actions\Commerce\ActionResult;
use App\Actions\Commerce\ManualGrantBenefitAction;
use App\Actions\Commerce\RefundOrderAction;
use App\Actions\Commerce\ReprocessPaymentEventAction;
use App\Actions\Commerce\RevokeBenefitAction;
use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;

/**
 * @review-surface admin_approval
 * @review-surface refund_approval
 * @review-surface manual_benefit_grant_approval
 * @review-surface benefit_revoke_approval
 * @review-surface payment_event_reprocess_approval
 * @review-surface rollback_release_approval
 * @review-surface data_lifecycle_approval
 */
final class ApprovalExecutor
{
    public function __construct(
        private readonly ManualGrantBenefitAction $manualGrantAction,
        private readonly RevokeBenefitAction $revokeBenefitAction,
        private readonly RefundOrderAction $refundOrderAction,
        private readonly ReprocessPaymentEventAction $reprocessPaymentEventAction,
        private readonly HighRiskApprovalService $highRiskApprovalService,
    ) {}

    public function execute(string $approvalId): ActionResult
    {
        $init = DB::transaction(function () use ($approvalId) {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first();
            if (! $approval) {
                return ActionResult::failure('APPROVAL_NOT_FOUND', 'approval not found.');
            }

            $status = strtoupper((string) $approval->status);
            if ($status === AdminApproval::STATUS_EXECUTED) {
                return ActionResult::success([
                    'approval_id' => $approvalId,
                    'idempotent' => true,
                    'status' => $status,
                ]);
            }

            if ($status === AdminApproval::STATUS_EXECUTING) {
                return ActionResult::success([
                    'approval_id' => $approvalId,
                    'idempotent' => true,
                    'status' => $status,
                ]);
            }

            if ($status !== AdminApproval::STATUS_APPROVED) {
                return ActionResult::failure('APPROVAL_STATUS_INVALID', 'approval must be APPROVED before execution.');
            }

            try {
                $this->highRiskApprovalService->assertExecutable($approval);
            } catch (HighRiskApprovalValidationException) {
                return ActionResult::failure(
                    'APPROVAL_GOVERNANCE_INVALID',
                    'approval governance validation failed.',
                );
            }
            if (! $this->highRiskApprovalService->executionSupported($approval)) {
                return ActionResult::failure(
                    'APPROVAL_EXECUTION_ADAPTER_MISSING',
                    'approval execution adapter is not registered.',
                );
            }
            try {
                $executionActor = $this->highRiskApprovalService->executionActor($approval);
            } catch (HighRiskApprovalValidationException) {
                return ActionResult::failure(
                    'APPROVAL_EXECUTION_AUTHORIZATION_INVALID',
                    'approval execution authorization validation failed.',
                );
            }

            $approval->status = AdminApproval::STATUS_EXECUTING;
            $approval->retry_count = (int) $approval->retry_count + 1;
            $approval->save();

            return ActionResult::success([
                'approval' => $approval,
                'execution_actor' => $executionActor,
            ]);
        });

        if (! $init->ok) {
            return $init;
        }

        $payload = $init->data;
        if (($payload['idempotent'] ?? false) === true) {
            return $init;
        }

        /** @var AdminApproval $approval */
        $approval = $payload['approval'];
        /** @var AdminUser $executionActor */
        $executionActor = $payload['execution_actor'];
        $executionActorId = (int) $executionActor->id;
        $correlationId = trim((string) $approval->correlation_id);
        $reason = trim((string) $approval->reason);
        $orgId = max(0, (int) $approval->org_id);

        try {
            $actionResult = $this->dispatchByTypeWithOrgContext($approval, $orgId, $executionActor);

            if ($actionResult->ok) {
                DB::transaction(function () use ($approval, $actionResult, $correlationId, $reason, $executionActorId): void {
                    $locked = AdminApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
                    if (! $locked) {
                        return;
                    }

                    $locked->status = AdminApproval::STATUS_EXECUTED;
                    $locked->executed_at = now();
                    $locked->error_code = null;
                    $locked->error_message = null;
                    $locked->save();

                    $this->writeAudit(
                        orgId: (int) $locked->org_id,
                        actorAdminId: $executionActorId,
                        action: 'approval_executed_success',
                        targetId: (string) $locked->id,
                        reason: $reason,
                        correlationId: $correlationId,
                        extra: [
                            'type' => $locked->type,
                            'result' => $actionResult->data,
                        ],
                    );
                });

                return ActionResult::success([
                    'approval_id' => (string) $approval->id,
                    'status' => AdminApproval::STATUS_EXECUTED,
                    'result' => $actionResult->data,
                ]);
            }

            $code = (string) ($actionResult->code ?? 'APPROVAL_EXECUTE_FAILED');
            $message = $this->sanitizeErrorMessage((string) ($actionResult->message ?? 'approval execution failed.'));

            DB::transaction(function () use ($approval, $code, $message, $correlationId, $reason, $executionActorId): void {
                $locked = AdminApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }

                $locked->status = AdminApproval::STATUS_FAILED;
                $locked->error_code = $code;
                $locked->error_message = $message;
                $locked->save();

                $this->writeAudit(
                    orgId: (int) $locked->org_id,
                    actorAdminId: $executionActorId,
                    action: 'approval_executed_failed',
                    targetId: (string) $locked->id,
                    reason: $reason,
                    correlationId: $correlationId,
                    extra: [
                        'type' => $locked->type,
                        'error_code' => $code,
                        'error_message' => $message,
                    ],
                );
            });

            return ActionResult::failure($code, $message, [
                'approval_id' => (string) $approval->id,
            ]);
        } catch (\Throwable $e) {
            $message = $this->sanitizeErrorMessage($e->getMessage());

            DB::transaction(function () use ($approval, $message, $correlationId, $reason, $executionActorId): void {
                $locked = AdminApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }

                $locked->status = AdminApproval::STATUS_FAILED;
                $locked->error_code = 'EXCEPTION';
                $locked->error_message = $message;
                $locked->save();

                $this->writeAudit(
                    orgId: (int) $locked->org_id,
                    actorAdminId: $executionActorId,
                    action: 'approval_executed_failed',
                    targetId: (string) $locked->id,
                    reason: $reason,
                    correlationId: $correlationId,
                    extra: [
                        'type' => $locked->type,
                        'error_code' => 'EXCEPTION',
                        'error_message' => $message,
                    ],
                );
            });

            return ActionResult::failure('EXCEPTION', $message, [
                'approval_id' => (string) $approval->id,
            ]);
        }
    }

    private function dispatchByType(AdminApproval $approval, AdminUser $actor): ActionResult
    {
        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];
        $type = strtoupper((string) $approval->type);

        return match ($type) {
            AdminApproval::TYPE_MANUAL_GRANT => $this->manualGrantAction->execute(
                $actor,
                (int) $approval->org_id,
                (string) ($payload['order_no'] ?? ''),
                (string) $approval->reason,
                (string) $approval->correlation_id,
                isset($payload['benefit_code']) ? (string) $payload['benefit_code'] : null,
                isset($payload['attempt_id']) ? (string) $payload['attempt_id'] : null,
            ),
            AdminApproval::TYPE_REVOKE_BENEFIT => $this->revokeBenefitAction->execute(
                $actor,
                (int) $approval->org_id,
                (string) ($payload['order_no'] ?? ''),
                (string) $approval->reason,
                (string) $approval->correlation_id,
            ),
            AdminApproval::TYPE_REFUND => $this->refundOrderAction->execute(
                $actor,
                (int) $approval->org_id,
                (string) ($payload['order_no'] ?? ''),
                (string) $approval->reason,
                (string) $approval->correlation_id,
            ),
            AdminApproval::TYPE_REPROCESS_EVENT => $this->reprocessPaymentEventAction->execute(
                $actor,
                (int) $approval->org_id,
                (string) ($payload['payment_event_id'] ?? ''),
                (string) $approval->reason,
                (string) $approval->correlation_id,
            ),
            AdminApproval::TYPE_ROLLBACK_RELEASE => $this->executeRollbackRelease($actor, $approval, $payload),
            default => ActionResult::failure('TYPE_NOT_SUPPORTED', 'approval type not supported.'),
        };
    }

    private function dispatchByTypeWithOrgContext(AdminApproval $approval, int $orgId, AdminUser $actor): ActionResult
    {
        $container = app();
        $hadContextBinding = $container->bound(OrgContext::class);

        /** @var OrgContext $context */
        $context = $hadContextBinding
            ? $container->make(OrgContext::class)
            : new OrgContext;

        $previousOrgId = (int) $context->orgId();
        $previousUserId = $context->userId();
        $previousRole = $context->role();
        $previousAnonId = $context->anonId();

        $context->set($orgId, $previousUserId, $previousRole, $previousAnonId);
        $container->instance(OrgContext::class, $context);

        try {
            return $this->dispatchByType($approval, $actor);
        } finally {
            $context->set($previousOrgId, $previousUserId, $previousRole, $previousAnonId);
            if (! $hadContextBinding) {
                $container->forgetInstance(OrgContext::class);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function executeRollbackRelease(AdminUser $actor, AdminApproval $approval, array $payload): ActionResult
    {
        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $safeReason = (string) $this->redactSensitive((string) $approval->reason);
        $httpMeta = $this->auditHttpMeta();

        DB::transaction(function () use ($approval, $payload, $actor, $orderNo, $safeReason, $httpMeta): void {
            DB::table('content_pack_releases')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'action' => 'rollback',
                'region' => (string) ($payload['region'] ?? 'GLOBAL'),
                'locale' => (string) ($payload['locale'] ?? 'en'),
                'dir_alias' => (string) ($payload['dir_alias'] ?? ''),
                'from_version_id' => isset($payload['from_version_id']) ? (string) $payload['from_version_id'] : null,
                'to_version_id' => isset($payload['to_version_id']) ? (string) $payload['to_version_id'] : null,
                'from_pack_id' => isset($payload['from_pack_id']) ? (string) $payload['from_pack_id'] : null,
                'to_pack_id' => isset($payload['to_pack_id']) ? (string) $payload['to_pack_id'] : null,
                'status' => 'success',
                'message' => $safeReason,
                'created_by' => (string) $actor->id,
                'probe_ok' => null,
                'probe_json' => null,
                'probe_run_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'org_id' => (int) $approval->org_id,
                'actor_admin_id' => (int) $actor->id,
                'action' => 'content_release_rollback',
                'target_type' => 'AdminApproval',
                'target_id' => (string) $approval->id,
                'meta_json' => json_encode([
                    'actor' => (int) $actor->id,
                    'org_id' => (int) $approval->org_id,
                    'order_no' => $orderNo,
                    'reason' => $safeReason,
                    'correlation_id' => (string) $approval->correlation_id,
                    'from_version_id' => $payload['from_version_id'] ?? null,
                    'to_version_id' => $payload['to_version_id'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'ip' => $httpMeta['ip'],
                'user_agent' => $httpMeta['user_agent'],
                'request_id' => $httpMeta['request_id'],
                'reason' => $safeReason,
                'result' => 'success',
                'created_at' => now(),
            ]);
        });

        return ActionResult::success([
            'approval_id' => (string) $approval->id,
            'type' => AdminApproval::TYPE_ROLLBACK_RELEASE,
        ]);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'approval execution failed.';
        }

        $message = (string) $this->redactSensitive($message);
        $message = preg_replace('/\s+/', ' ', $message) ?: $message;

        return mb_substr($message, 0, 255);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function writeAudit(
        int $orgId,
        ?int $actorAdminId,
        string $action,
        string $targetId,
        string $reason,
        string $correlationId,
        array $extra = [],
    ): void {
        $httpMeta = $this->auditHttpMeta();
        $safeReason = (string) $this->redactSensitive($reason);
        $safeExtra = $this->redactSensitive($extra);

        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => $actorAdminId,
            'action' => $action,
            'target_type' => 'AdminApproval',
            'target_id' => $targetId,
            'meta_json' => json_encode(array_merge([
                'actor' => $actorAdminId,
                'org_id' => $orgId,
                'reason' => $safeReason,
                'correlation_id' => $correlationId,
            ], is_array($safeExtra) ? $safeExtra : []), JSON_UNESCAPED_UNICODE),
            'ip' => $httpMeta['ip'],
            'user_agent' => $httpMeta['user_agent'],
            'request_id' => $httpMeta['request_id'],
            'reason' => $safeReason,
            'result' => str_contains($action, 'failed') ? 'failed' : 'success',
            'created_at' => now(),
        ]);
    }

    private function redactSensitive(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/token|totp|secret|password|authorization|cookie|api[_-]?key/i', $key) === 1) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $itemKey => $item) {
                $redacted[$itemKey] = $this->redactSensitive($item, is_string($itemKey) ? $itemKey : null);
            }

            return $redacted;
        }
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?: $value;

        return preg_replace(
            '/\b([a-z0-9_-]*(?:token|totp|secret|password|authorization|cookie|api[_-]?key))\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value,
        ) ?: $value;
    }

    /**
     * @return array{ip:string|null,user_agent:string,request_id:string}
     */
    private function auditHttpMeta(): array
    {
        $ip = null;
        $userAgent = '';
        $requestId = '';

        if (app()->bound('request')) {
            $request = app()->make('request');
            if ($request instanceof \Illuminate\Http\Request) {
                $ip = $request->ip();
                $userAgent = (string) ($request->userAgent() ?? '');
                $requestId = (string) ($request->attributes->get('request_id') ?? '');
            }
        }

        return [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
        ];
    }
}
