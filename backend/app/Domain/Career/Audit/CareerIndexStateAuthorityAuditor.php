<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use App\Domain\Career\IndexStateValue;
use App\Models\IndexState;
use App\Models\Occupation;

final class CareerIndexStateAuthorityAuditor
{
    /**
     * @param  class-string<Occupation>  $occupationModel
     */
    public function __construct(
        private readonly string $occupationModel = Occupation::class,
    ) {}

    public function auditPlan(CareerPublicResolutionPlan $plan): CareerIndexStateAuthorityResult
    {
        return $this->auditSlugs(array_map(
            static fn (CareerPublicResolutionPlanRow $row): ?string => $row->canonicalSlug,
            $plan->rows
        ));
    }

    public function planRemediation(CareerPublicResolutionPlan $plan): CareerIndexStateRemediationPlan
    {
        $result = $this->auditPlan($plan);
        $planRows = $this->planRowsBySlug($plan);

        $rows = [];
        foreach ($result->rows as $row) {
            $planRow = $planRows[$row->canonicalSlug] ?? null;
            $expectation = $this->indexExpectation($planRow);
            [$action, $approvalRequired] = $this->remediationAction($row, $expectation);

            $rows[] = new CareerIndexStateRemediationPlanRow(
                canonicalSlug: $row->canonicalSlug,
                expectation: $expectation,
                action: $action,
                approvalRequired: $approvalRequired,
                occupationId: $row->occupationId,
                indexStateId: $row->indexStateId,
                rawIndexState: $row->rawIndexState,
                publicIndexState: $row->publicIndexState,
                indexEligible: $row->indexEligible,
                reasons: $row->indexStatus->reasons,
                evidence: [
                    ...$row->evidence,
                    [
                        'index_expectation' => $expectation,
                        'plan_state' => $planRow?->publicResolutionState,
                        'plan_public_type' => $planRow?->canonicalPublicType,
                        'plan_rollout_state' => $planRow?->rolloutState,
                        'plan_projection_state' => $planRow?->projectionState,
                        'plan_index_state_hint' => $planRow?->indexStateHint,
                    ],
                ],
            );
        }

        return CareerIndexStateRemediationPlan::build($rows, $result->sidecars);
    }

    public function auditEntityInventory(CareerOccupationEntityInventoryResult $result): CareerIndexStateAuthorityResult
    {
        return $this->auditSlugs(array_map(
            static fn (CareerOccupationEntityInventoryRow $row): string => $row->canonicalSlug,
            $result->rows
        ));
    }

    /**
     * @param  list<string|null>  $slugs
     */
    public function auditSlugs(array $slugs): CareerIndexStateAuthorityResult
    {
        $rows = [];
        foreach ($this->occupationsBySlug($slugs) as $slug => $occupation) {
            $rows[] = $this->rowForOccupation($slug, $occupation);
        }

        return CareerIndexStateAuthorityResult::build($rows);
    }

    /**
     * @param  list<string|null>  $slugs
     * @return array<string, Occupation|null>
     */
    private function occupationsBySlug(array $slugs): array
    {
        $normalized = [];
        foreach ($slugs as $slug) {
            $value = $this->normalizeSlug($slug);
            if ($value !== null) {
                $normalized[$value] = null;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $model = $this->occupationModel;
        /** @var iterable<Occupation> $occupations */
        $occupations = $model::query()
            ->whereIn('canonical_slug', array_keys($normalized))
            ->with(['indexStates' => fn ($query) => $query
                ->orderByDesc('changed_at')
                ->orderByDesc('created_at')])
            ->get();

        foreach ($occupations as $occupation) {
            $slug = $this->normalizeSlug($occupation->getAttribute('canonical_slug'));
            if ($slug !== null && array_key_exists($slug, $normalized)) {
                $normalized[$slug] = $occupation;
            }
        }

        return $normalized;
    }

    private function rowForOccupation(string $canonicalSlug, ?Occupation $occupation): CareerIndexStateAuthorityRow
    {
        $indexState = $occupation?->indexStates->first();
        $issues = $this->issuesFor($canonicalSlug, $indexState);
        $evidence = $this->evidenceFor($canonicalSlug, $occupation, $indexState);

        return new CareerIndexStateAuthorityRow(
            canonicalSlug: $canonicalSlug,
            occupationId: $this->normalizeString($occupation?->getAttribute('id')),
            indexStateId: $this->normalizeString($indexState?->getAttribute('id')),
            rawIndexState: $this->normalizeString($indexState?->getAttribute('index_state')),
            publicIndexState: $indexState === null ? null : IndexStateValue::publicFacing(
                (string) $indexState->getAttribute('index_state'),
                (bool) $indexState->getAttribute('index_eligible')
            ),
            indexEligible: (bool) ($indexState?->getAttribute('index_eligible') ?? false),
            changedAt: $this->normalizeString($indexState?->getAttribute('changed_at')?->toISOString()),
            indexStatus: $this->indexLayerStatus($issues, $evidence),
            reasonCodes: $this->reasonCodes($indexState),
            evidence: $evidence,
            issues: $issues
        );
    }

    /**
     * @return array<string, CareerPublicResolutionPlanRow>
     */
    private function planRowsBySlug(CareerPublicResolutionPlan $plan): array
    {
        $rows = [];
        foreach ($plan->rows as $row) {
            $slug = $this->normalizeSlug($row->canonicalSlug);
            if ($slug !== null) {
                $rows[$slug] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function remediationAction(CareerIndexStateAuthorityRow $row, string $expectation): array
    {
        if ($row->issues === []) {
            return [CareerIndexStateRemediationPlanRow::ACTION_NONE, false];
        }

        if ($row->indexStateId !== null) {
            return [CareerIndexStateRemediationPlanRow::ACTION_REVIEW_EXISTING_INDEX_STATE, true];
        }

        return match ($expectation) {
            CareerIndexStateRemediationPlanRow::EXPECTATION_GOVERNED_NON_PUBLIC => [CareerIndexStateRemediationPlanRow::ACTION_DEFER_GOVERNED_NON_PUBLIC, false],
            CareerIndexStateRemediationPlanRow::EXPECTATION_NOT_YET_PROMOTED => [CareerIndexStateRemediationPlanRow::ACTION_DEFER_UNTIL_RUNTIME_PROMOTION, false],
            default => [CareerIndexStateRemediationPlanRow::ACTION_CREATE_INDEX_STATE, true],
        };
    }

    private function indexExpectation(?CareerPublicResolutionPlanRow $row): string
    {
        if ($row === null) {
            return CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED;
        }

        $indexStateHint = strtolower((string) ($row->indexStateHint ?? ''));
        if (in_array($indexStateHint, [IndexStateValue::INDEXED, IndexStateValue::INDEXABLE], true)) {
            return CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED;
        }

        $publicType = strtolower((string) ($row->canonicalPublicType ?? ''));
        if (in_array($publicType, ['public_canonical_job', 'public'], true)) {
            return CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED;
        }

        if ($this->containsAny($publicType, ['non_public', 'non-public', 'private', 'governed'])) {
            return CareerIndexStateRemediationPlanRow::EXPECTATION_GOVERNED_NON_PUBLIC;
        }

        $states = $this->planStates($row);
        foreach ($states as $state) {
            if ($this->containsAny($state, ['governance', 'blocked', 'hold', 'non_public', 'non-public', 'private'])) {
                return CareerIndexStateRemediationPlanRow::EXPECTATION_GOVERNED_NON_PUBLIC;
            }
        }

        foreach ($states as $state) {
            if (in_array($state, ['published', 'indexed', 'indexable', 'live'], true)) {
                return CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED;
            }
        }

        foreach ($states as $state) {
            if (in_array($state, ['ready_for_pilot', 'planned', 'candidate', 'published_candidate', 'approved', 'draft', 'review_needed'], true)) {
                return CareerIndexStateRemediationPlanRow::EXPECTATION_NOT_YET_PROMOTED;
            }
        }

        return CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED;
    }

    /**
     * @return list<string>
     */
    private function planStates(CareerPublicResolutionPlanRow $row): array
    {
        $states = [];
        foreach ([
            $row->publicResolutionState,
            $row->rolloutState,
            $row->projectionState,
            $row->raw['status'] ?? null,
            $row->raw['release_status'] ?? null,
            $row->raw['Release_Status'] ?? null,
            $row->raw['content_status'] ?? null,
            $row->raw['Content_Status'] ?? null,
            $row->raw['review_state'] ?? null,
            $row->raw['Review_State'] ?? null,
        ] as $value) {
            $state = $this->normalizeString($value);
            if ($state !== null) {
                $states[] = strtolower($state);
            }
        }

        return array_values(array_unique($states));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CareerIndexStateAuthorityIssue>
     */
    private function issuesFor(string $canonicalSlug, ?IndexState $indexState): array
    {
        if ($indexState === null) {
            return [
                new CareerIndexStateAuthorityIssue(
                    reason: CareerIndexStateAuthorityIssue::INDEX_STATE_MISSING,
                    message: 'Latest index_state authority row was not found for canonical slug.',
                    severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                    canonicalSlug: $canonicalSlug,
                    evidence: [['canonical_slug' => $canonicalSlug]]
                ),
            ];
        }

        $issues = [];
        $rawState = strtolower(trim((string) $indexState->getAttribute('index_state')));
        $indexEligible = (bool) $indexState->getAttribute('index_eligible');
        $indexStateId = $this->normalizeString($indexState->getAttribute('id'));

        if (! $indexEligible) {
            $issues[] = new CareerIndexStateAuthorityIssue(
                reason: CareerIndexStateAuthorityIssue::INDEX_ELIGIBLE_FALSE,
                message: 'Latest index_state row is not index eligible.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                indexStateId: $indexStateId,
                evidence: [['index_eligible' => false]]
            );
        }

        if ($rawState === IndexStateValue::NOINDEX) {
            $issues[] = new CareerIndexStateAuthorityIssue(
                reason: CareerIndexStateAuthorityIssue::EXPLICIT_NOINDEX_BLOCK,
                message: 'Latest index_state row explicitly marks the occupation noindex.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                indexStateId: $indexStateId,
                evidence: [['index_state' => $rawState]]
            );
        }

        if ($this->containsBlocker($rawState, $this->reasonCodes($indexState), 'quarantine')) {
            $issues[] = new CareerIndexStateAuthorityIssue(
                reason: CareerIndexStateAuthorityIssue::QUARANTINE_BLOCK,
                message: 'Latest index_state row carries quarantine blocker evidence.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                indexStateId: $indexStateId,
                evidence: [['index_state' => $rawState, 'reason_codes' => $this->reasonCodes($indexState)]]
            );
        }

        if ($this->containsBlocker($rawState, $this->reasonCodes($indexState), 'rollback')) {
            $issues[] = new CareerIndexStateAuthorityIssue(
                reason: CareerIndexStateAuthorityIssue::ROLLBACK_BLOCK,
                message: 'Latest index_state row carries rollback blocker evidence.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                indexStateId: $indexStateId,
                evidence: [['index_state' => $rawState, 'reason_codes' => $this->reasonCodes($indexState)]]
            );
        }

        if (! IndexStateValue::isIndexedLike($rawState, $indexEligible) && $issues === []) {
            $issues[] = new CareerIndexStateAuthorityIssue(
                reason: CareerIndexStateAuthorityIssue::INDEX_STATE_NOT_INDEXED_LIKE,
                message: 'Latest index_state row is not indexed-like.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                indexStateId: $indexStateId,
                evidence: [['index_state' => $rawState, 'index_eligible' => $indexEligible]]
            );
        }

        return $issues;
    }

    /**
     * @param  list<CareerIndexStateAuthorityIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    private function indexLayerStatus(array $issues, array $evidence): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::INDEX,
            status: $issues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            reasons: array_values(array_unique(array_map(
                static fn (CareerIndexStateAuthorityIssue $issue): string => $issue->reason,
                $issues
            ))),
            evidence: $evidence,
            source: 'index_states'
        );
    }

    /**
     * @return list<mixed>
     */
    private function evidenceFor(string $canonicalSlug, ?Occupation $occupation, ?IndexState $indexState): array
    {
        $evidence = [['canonical_slug' => $canonicalSlug]];

        if ($occupation !== null) {
            $evidence[] = ['occupation_id' => (string) $occupation->getAttribute('id')];
        }

        if ($indexState !== null) {
            $evidence[] = [
                'index_state_id' => (string) $indexState->getAttribute('id'),
                'index_state' => (string) $indexState->getAttribute('index_state'),
                'index_eligible' => (bool) $indexState->getAttribute('index_eligible'),
            ];
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(?IndexState $indexState): array
    {
        $reasonCodes = $indexState?->getAttribute('reason_codes');
        if (! is_array($reasonCodes) || ! array_is_list($reasonCodes)) {
            return [];
        }

        $normalized = [];
        foreach ($reasonCodes as $reasonCode) {
            $value = $this->normalizeString($reasonCode);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function containsBlocker(string $rawState, array $reasonCodes, string $needle): bool
    {
        if (str_contains($rawState, $needle)) {
            return true;
        }

        foreach ($reasonCodes as $reasonCode) {
            if (str_contains(strtolower($reasonCode), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
