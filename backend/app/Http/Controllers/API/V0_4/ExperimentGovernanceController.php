<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_4;

use App\Http\Controllers\Controller;
use App\Services\Experiments\ExperimentGovernanceService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExperimentGovernanceController extends Controller
{
    public function __construct(
        private readonly ExperimentGovernanceService $governanceService,
        private readonly OrgContext $orgContext,
    ) {}

    /**
     * POST /api/v0.4/orgs/{org_id}/experiments/rollouts/{rollout_id}/approve
     */
    public function approve(Request $request, string $org_id, string $rollout_id): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        try {
            $result = $this->governanceService->approveRollout(
                $orgId,
                $rollout_id,
                $this->resolveUserId($request),
                $payload['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->governanceNotReady($e->getMessage());
        }

        if ($result === null) {
            return $this->rolloutNotFound();
        }

        return response()->json([
            'ok' => true,
            'action' => 'approve',
            'rollout' => $result['rollout'],
            'audit_id' => $result['audit_id'],
        ]);
    }

    /**
     * POST /api/v0.4/orgs/{org_id}/experiments/rollouts/{rollout_id}/pause
     */
    public function pause(Request $request, string $org_id, string $rollout_id): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        try {
            $result = $this->governanceService->pauseRollout(
                $orgId,
                $rollout_id,
                $this->resolveUserId($request),
                $payload['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->governanceNotReady($e->getMessage());
        }

        if ($result === null) {
            return $this->rolloutNotFound();
        }

        return response()->json([
            'ok' => true,
            'action' => 'pause',
            'rollout' => $result['rollout'],
            'audit_id' => $result['audit_id'],
        ]);
    }

    /**
     * POST /api/v0.4/orgs/{org_id}/experiments/rollouts/{rollout_id}/rollback
     */
    public function rollback(Request $request, string $org_id, string $rollout_id): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        try {
            $result = $this->governanceService->rollbackRollout(
                $orgId,
                $rollout_id,
                $this->resolveUserId($request),
                $payload['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->governanceNotReady($e->getMessage());
        }

        if ($result === null) {
            return $this->rolloutNotFound();
        }

        return response()->json([
            'ok' => true,
            'action' => 'rollback',
            'rollout' => $result['rollout'],
            'audit_id' => $result['audit_id'],
        ]);
    }

    /**
     * PUT /api/v0.4/orgs/{org_id}/experiments/rollouts/{rollout_id}/guardrails
     */
    public function upsertGuardrail(Request $request, string $org_id, string $rollout_id): JsonResponse
    {
        $payload = $request->validate([
            'metric_key' => ['required', 'string', 'max:64'],
            'operator' => ['required', 'string', 'in:gt,gte,lt,lte,>,>=,<,<='],
            'threshold' => ['required', 'numeric'],
            'window_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'min_sample_size' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'auto_rollback' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        try {
            $result = $this->governanceService->upsertGuardrail(
                $orgId,
                $rollout_id,
                $this->resolveUserId($request),
                $payload
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return $this->governanceNotReady($e->getMessage());
        }

        if ($result === null) {
            return $this->rolloutNotFound();
        }

        return response()->json([
            'ok' => true,
            'rollout' => $result['rollout'],
            'guardrail' => $result['guardrail'],
            'audit_id' => $result['audit_id'],
        ]);
    }

    /**
     * POST /api/v0.4/orgs/{org_id}/experiments/rollouts/{rollout_id}/guardrails/evaluate
     */
    public function evaluateGuardrails(Request $request, string $org_id, string $rollout_id): JsonResponse
    {
        $payload = $request->validate([
            'metrics' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        try {
            $result = $this->governanceService->evaluateGuardrails(
                $orgId,
                $rollout_id,
                $this->resolveUserId($request),
                is_array($payload['metrics']) ? $payload['metrics'] : [],
                $payload['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->governanceNotReady($e->getMessage());
        }

        if ($result === null) {
            return $this->rolloutNotFound();
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $result));
    }

    private function resolveOrgId(string $orgId): int
    {
        $orgId = trim($orgId);
        if ($orgId === '' || preg_match('/^\d+$/', $orgId) !== 1) {
            return 0;
        }

        return (int) $orgId;
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = trim((string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? ''));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function isOrgAccessible(int $orgId): bool
    {
        return $orgId > 0 && $this->orgContext->orgId() === $orgId;
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }

    private function rolloutNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ROLLOUT_NOT_FOUND',
            'message' => 'rollout not found.',
        ], 404);
    }

    private function governanceNotReady(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'EXPERIMENT_GOVERNANCE_NOT_READY',
            'message' => $message,
        ], 503);
    }
}
