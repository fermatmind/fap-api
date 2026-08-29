<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Routing;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class CouncilConflictResolver
{
    private const PRIORITY = [
        'private_safety_authority' => 1,
        'policy_signed_scope' => 2,
        'data_quality' => 3,
        'technical_correctness' => 4,
        'claim_evidence' => 5,
        'query_owner_intent' => 6,
        'editorial' => 7,
        'cro' => 8,
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function resolve(
        string $runId,
        string $left,
        string $right,
        bool $authorityRevisionMatch,
        bool $evidenceMutuallyExclusive,
    ): array {
        $unresolved = $left === $right && ($evidenceMutuallyExclusive || ! $authorityRevisionMatch);
        $winner = $unresolved
            ? null
            : (self::PRIORITY[$left] <= self::PRIORITY[$right] ? $left : $right);
        $record = [
            'conflict_id' => $this->hasher->hash([$runId, $left, $right, $authorityRevisionMatch, $evidenceMutuallyExclusive]),
            'run_id' => $runId,
            'left_priority' => $left,
            'right_priority' => $right,
            'authority_revision_match' => $authorityRevisionMatch,
            'evidence_mutually_exclusive' => $evidenceMutuallyExclusive,
            'status' => $unresolved ? 'unresolved_conflict' : 'resolved',
            'winner' => $winner,
            'human_decision_required' => $unresolved,
            'execution_allowed' => false,
        ];
        $record['conflict_hash'] = $this->hasher->hash($record);

        return $record;
    }
}
