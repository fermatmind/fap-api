<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\UnifiedAccessProjection;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;
use App\Services\Storage\UnifiedAccessProjectionWriter;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AttemptUnlockProjectionRepairService
{
    public function __construct(
        private readonly UnifiedAccessProjectionWriter $projectionWriter,
        private readonly EntitlementManager $entitlements,
    ) {}

    public function repairResultReadyProjectionIfNeeded(int $orgId, string $attemptId): ?UnifiedAccessProjection
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! SchemaBaseline::hasTable('unified_access_projections')) {
            return null;
        }

        $existing = UnifiedAccessProjection::query()
            ->where('attempt_id', $attemptId)
            ->first();

        if ($this->isProjectionReady($existing)) {
            return $existing;
        }

        if (! $this->resultExists($orgId, $attemptId)) {
            return $existing;
        }

        if (! $this->hasActiveUnlockGrant($orgId, $attemptId)) {
            return $existing;
        }

        $modulesAllowed = $this->entitlements->getAllowedModulesForAttempt($orgId, $attemptId);
        $pdfReady = $this->isPdfReady($existing, $attemptId);
        $patch = [
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => $pdfReady ? 'ready' : 'missing',
            'reason_code' => 'projection_repaired_from_entitlement',
            'actions_json' => [
                'report' => true,
                'pdf' => $pdfReady,
                'unlock' => true,
            ],
            'payload_json' => [
                'attempt_id' => $attemptId,
                'has_active_grant' => true,
                'result_exists' => true,
                'repair_source' => 'attempt_unlock_projection_repair',
                'access_level' => ReportAccess::REPORT_ACCESS_FULL,
                'variant' => ReportAccess::VARIANT_FULL,
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => [],
            ],
        ];
        $meta = [
            'source_system' => 'attempt_unlock_projection_repair',
            'source_ref' => 'result#'.$attemptId,
            'actor_type' => 'system',
            'actor_id' => 'attempt_unlock_projection_repair',
            'reason_code' => 'projection_repaired_from_entitlement',
        ];

        try {
            $projection = $this->projectionWriter->refreshAttemptProjection($attemptId, $patch, $meta);

            if (! $projection instanceof UnifiedAccessProjection) {
                $projection = $this->persistProjectionPatch($attemptId, $patch, $existing);
                Log::warning('ATTEMPT_UNLOCK_PROJECTION_REPAIR_WRITER_UNAVAILABLE', [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'existing_reason_code' => $existing?->reason_code,
                ]);
            }

            Log::info('ATTEMPT_UNLOCK_PROJECTION_REPAIRED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'existing_reason_code' => $existing?->reason_code,
                'pdf_ready' => $pdfReady,
            ]);

            return $projection ?? $existing;
        } catch (\Throwable $e) {
            Log::error('ATTEMPT_UNLOCK_PROJECTION_REPAIR_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'existing_reason_code' => $existing?->reason_code,
                'exception' => $e,
            ]);

            return $existing;
        }
    }

    private function isProjectionReady(?UnifiedAccessProjection $projection): bool
    {
        if (! $projection instanceof UnifiedAccessProjection) {
            return false;
        }

        return strtolower(trim((string) ($projection->access_state ?? ''))) === 'ready'
            && strtolower(trim((string) ($projection->report_state ?? ''))) === 'ready';
    }

    private function resultExists(int $orgId, string $attemptId): bool
    {
        return DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->exists();
    }

    private function hasActiveUnlockGrant(int $orgId, string $attemptId): bool
    {
        return DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_type', 'report_unlock')
            ->where('status', 'active')
            ->where(function ($query) use ($attemptId): void {
                $query->where('attempt_id', $attemptId)
                    ->orWhere('scope', 'org');
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function isPdfReady(?UnifiedAccessProjection $existing, string $attemptId): bool
    {
        if ($existing instanceof UnifiedAccessProjection) {
            $existingState = strtolower(trim((string) ($existing->pdf_state ?? '')));
            if ($existingState === 'ready') {
                return true;
            }
        }

        if (! SchemaBaseline::hasTable('report_artifact_slots')) {
            return false;
        }

        return DB::table('report_artifact_slots')
            ->where('attempt_id', $attemptId)
            ->whereIn('slot_code', ['report_pdf_free', 'report_pdf_full'])
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    private function persistProjectionPatch(
        string $attemptId,
        array $patch,
        ?UnifiedAccessProjection $existing
    ): UnifiedAccessProjection {
        $now = now();

        return UnifiedAccessProjection::query()->updateOrCreate(
            ['attempt_id' => $attemptId],
            [
                'access_state' => (string) $patch['access_state'],
                'report_state' => (string) $patch['report_state'],
                'pdf_state' => (string) $patch['pdf_state'],
                'reason_code' => (string) $patch['reason_code'],
                'projection_version' => 1,
                'actions_json' => is_array($patch['actions_json'] ?? null) ? $patch['actions_json'] : null,
                'payload_json' => is_array($patch['payload_json'] ?? null) ? $patch['payload_json'] : null,
                'produced_at' => $existing?->produced_at ?? $now,
                'refreshed_at' => $now,
            ]
        );
    }
}
