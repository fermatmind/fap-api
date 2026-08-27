<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

final class SeoDecisionLifecyclePolicy
{
    public const VERSION = 'seo.decision_lifecycle.v1';

    public const STATES = [
        'candidate',
        'selected',
        'held',
        'in_progress',
        'recovery_pending',
        'closed',
        'superseded',
    ];

    public const ALLOWED_TRANSITIONS = [
        'candidate' => ['candidate', 'selected', 'held', 'superseded'],
        'selected' => ['selected', 'in_progress', 'held', 'superseded'],
        'held' => ['held', 'candidate', 'selected', 'superseded'],
        'in_progress' => ['in_progress', 'recovery_pending', 'held', 'superseded'],
        'recovery_pending' => ['recovery_pending', 'closed', 'in_progress', 'held', 'superseded'],
        'closed' => ['candidate'],
        'superseded' => [],
    ];

    /** @param array<string, mixed> $evidence */
    public function allows(?string $fromState, string $toState, array $evidence): bool
    {
        if (! in_array($toState, self::STATES, true)) {
            return false;
        }

        if (($evidence['evidence_fresh'] ?? null) !== true && $toState !== 'held') {
            return false;
        }

        if ($fromState === null) {
            return in_array($toState, ['candidate', 'held'], true);
        }

        if (! in_array($toState, self::ALLOWED_TRANSITIONS[$fromState] ?? [], true)) {
            return false;
        }

        if ($fromState === 'closed' && $toState === 'candidate') {
            return ($evidence['recurrence_proven'] ?? null) === true;
        }

        return $toState !== 'closed' || $this->provesRecovery($evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function provesRecovery(array $evidence): bool
    {
        return ($evidence['all_backing_resolved'] ?? null) === true
            && ($evidence['direct_recovery_proven'] ?? null) === true
            && ($evidence['recovery_evidence_fresh'] ?? null) === true
            && is_string($evidence['authority_revision'] ?? null)
            && $evidence['authority_revision'] !== ''
            && is_string($evidence['runtime_revision'] ?? null)
            && $evidence['runtime_revision'] !== '';
    }
}
