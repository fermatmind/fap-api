<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\AttemptRetentionBinding;
use App\Models\RetentionPolicy;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class RetentionPolicyResolver
{
    public function ensureAttemptBinding(string $attemptId, ?string $boundBy = null): ?AttemptRetentionBinding
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! $this->isEnabled()) {
            return null;
        }

        if (! SchemaBaseline::hasTable('attempt_retention_bindings') || ! SchemaBaseline::hasTable('retention_policies')) {
            return null;
        }

        return DB::transaction(function () use ($attemptId, $boundBy): ?AttemptRetentionBinding {
            $existing = AttemptRetentionBinding::query()
                ->where('attempt_id', $attemptId)
                ->first();
            if ($existing instanceof AttemptRetentionBinding) {
                return $existing;
            }

            $policy = $this->resolvePolicyForAttempt($attemptId);
            if (! $policy instanceof RetentionPolicy) {
                return null;
            }

            $binding = AttemptRetentionBinding::query()->create([
                'attempt_id' => $attemptId,
                'retention_policy_id' => (int) $policy->id,
                'bound_by' => $this->normalizeNullableText($boundBy),
                'bound_at' => now(),
            ]);

            return $binding->fresh() ?? $binding;
        });
    }

    public function resolvePolicyForAttempt(string $attemptId): ?RetentionPolicy
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! $this->isEnabled()) {
            return null;
        }

        if (SchemaBaseline::hasTable('attempt_retention_bindings') && SchemaBaseline::hasTable('retention_policies')) {
            $binding = AttemptRetentionBinding::query()
                ->where('attempt_id', $attemptId)
                ->first();
            if ($binding instanceof AttemptRetentionBinding) {
                $policy = RetentionPolicy::query()
                    ->where('id', (int) $binding->retention_policy_id)
                    ->where('active', true)
                    ->first();
                if ($policy instanceof RetentionPolicy) {
                    return $policy;
                }
            }
        }

        return RetentionPolicy::query()
            ->where('active', true)
            ->whereIn('subject_scope', ['attempt', 'all'])
            ->orderBy('id')
            ->first();
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.retention_policy_engine_enabled', false);
    }

    private function normalizeNullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
