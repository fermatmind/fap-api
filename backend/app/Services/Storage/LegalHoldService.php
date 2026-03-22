<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\LegalHold;
use App\Support\SchemaBaseline;

final class LegalHoldService
{
    public function activeHoldForAttempt(string $attemptId): ?LegalHold
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! $this->isEnabled() || ! SchemaBaseline::hasTable('legal_holds')) {
            return null;
        }

        $now = now();

        return LegalHold::query()
            ->whereIn('scope_type', ['attempt', 'report_artifact_attempt'])
            ->where('scope_id', $attemptId)
            ->where(function ($query) use ($now): void {
                $query->whereNull('active_from')
                    ->orWhere('active_from', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('released_at')
                    ->orWhere('released_at', '>', $now);
            })
            ->orderByDesc('id')
            ->first();
    }

    public function blockedReasonCodeForAttempt(string $attemptId): ?string
    {
        $hold = $this->activeHoldForAttempt($attemptId);

        return $hold instanceof LegalHold
            ? trim((string) ($hold->reason_code ?: 'LEGAL_HOLD_ACTIVE'))
            : null;
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.retention_policy_engine_enabled', false);
    }
}
