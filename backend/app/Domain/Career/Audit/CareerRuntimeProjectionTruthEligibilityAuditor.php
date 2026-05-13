<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerRuntimeProjectionTruthEligibilityAuditor
{
    private const PUBLISHED = 'published';

    private const PUBLIC_CANONICAL_JOB = 'public_canonical_job';

    private const EXPECTED_PUBLISHED = 'expected_published';

    private const GOVERNED_NON_PUBLIC = 'governed_non_public';

    private const NOT_YET_PROMOTED = 'not_yet_promoted';

    /**
     * @param  list<mixed>  $planRows
     * @param  list<string>  $locales
     * @param  array<string, mixed>|list<array<string, mixed>>  $projection
     * @param  array<string, mixed>|list<array<string, mixed>>  $truth
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $ledger
     */
    public function audit(
        array $planRows,
        array $locales,
        array $projection,
        array $truth,
        ?array $ledger = null,
    ): CareerRuntimeProjectionTruthEligibilityResult {
        $expectedSlugs = $this->expectedSlugs($planRows);
        $expectedLocales = $this->expectedLocales($locales);
        $projectionRows = $this->rowsBySlugLocale($this->artifactRows($projection));
        $truthRows = $this->rowsBySlugLocale($this->artifactRows($truth));
        $ledgerSlugs = $ledger === null ? null : $this->ledgerSlugs($ledger);
        $planTypes = $this->publicTypesBySlug($planRows);
        $planExpectations = $this->runtimeExpectationsBySlug($planRows);

        $rows = [];
        foreach ($expectedSlugs as $slug) {
            foreach ($expectedLocales as $locale) {
                $rows[] = $this->auditExpectedRow(
                    slug: $slug,
                    locale: $locale,
                    projectionRow: $projectionRows[$this->rowKey($slug, $locale)] ?? null,
                    truthRow: $truthRows[$this->rowKey($slug, $locale)] ?? null,
                    ledgerMemberExists: $ledgerSlugs === null ? null : in_array($slug, $ledgerSlugs, true),
                    planPublicType: $planTypes[$slug] ?? null,
                    planRuntimeExpectation: $planExpectations[$slug] ?? self::EXPECTED_PUBLISHED,
                );
            }
        }

        return CareerRuntimeProjectionTruthEligibilityResult::build($rows);
    }

    public function auditPlan(
        CareerPublicResolutionPlan $plan,
        array $locales,
        array $projection,
        array $truth,
        ?array $ledger = null,
    ): CareerRuntimeProjectionTruthEligibilityResult {
        return $this->audit($plan->rows, $locales, $projection, $truth, $ledger);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array<string, mixed>|list<array<string, mixed>>  $projection
     * @param  array<string, mixed>|list<array<string, mixed>>  $truth
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $ledger
     */
    public function auditSlugs(
        array $slugs,
        array $locales,
        array $projection,
        array $truth,
        ?array $ledger = null,
    ): CareerRuntimeProjectionTruthEligibilityResult {
        return $this->audit($slugs, $locales, $projection, $truth, $ledger);
    }

    /**
     * @return list<string>
     */
    private function expectedSlugs(array $planRows): array
    {
        $slugs = [];
        foreach ($planRows as $row) {
            $slug = $this->slugForPlanRow($row);
            if ($slug !== null && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function expectedLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $value = $this->normalizeString($locale);
            if ($value !== null) {
                $value = strtolower($value);
                if (! in_array($value, $normalized, true)) {
                    $normalized[] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function publicTypesBySlug(array $planRows): array
    {
        $types = [];
        foreach ($planRows as $row) {
            $slug = $this->slugForPlanRow($row);
            $type = $this->publicTypeForRow($row);
            if ($slug !== null && $type !== null) {
                $types[$slug] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function runtimeExpectationsBySlug(array $planRows): array
    {
        $expectations = [];
        foreach ($planRows as $row) {
            $slug = $this->slugForPlanRow($row);
            if ($slug !== null) {
                $expectations[$slug] = $this->runtimeExpectationForPlanRow($row);
            }
        }

        return $expectations;
    }

    private function runtimeExpectationForPlanRow(mixed $row): string
    {
        $publicType = $this->publicTypeForRow($row);
        if ($publicType === self::PUBLIC_CANONICAL_JOB) {
            return self::EXPECTED_PUBLISHED;
        }

        if ($publicType !== null) {
            return $this->isGovernedNonPublicType($publicType)
                ? self::GOVERNED_NON_PUBLIC
                : self::EXPECTED_PUBLISHED;
        }

        $states = $this->planStates($row);
        foreach ($states as $state) {
            if (in_array($state, ['published', 'live'], true)) {
                return self::EXPECTED_PUBLISHED;
            }
        }

        foreach ($states as $state) {
            if (in_array($state, ['ready_for_pilot', 'planned', 'candidate', 'published_candidate', 'approved', 'draft', 'review_needed'], true)) {
                return self::NOT_YET_PROMOTED;
            }
        }

        return self::EXPECTED_PUBLISHED;
    }

    /**
     * @param  array<string, mixed>|null  $projectionRow
     * @param  array<string, mixed>|null  $truthRow
     */
    private function auditExpectedRow(
        string $slug,
        string $locale,
        ?array $projectionRow,
        ?array $truthRow,
        ?bool $ledgerMemberExists,
        ?string $planPublicType,
        ?string $planRuntimeExpectation,
    ): CareerRuntimeProjectionTruthEligibilityRow {
        $projectionState = $this->normalizeString($projectionRow['projection_state'] ?? null);
        $runtimePublishState = $this->normalizeString($projectionRow['runtime_publish_state'] ?? null);
        $truthState = $this->normalizeString(
            $truthRow['truth_state']
                ?? $truthRow['state']
                ?? $truthRow['status']
                ?? $truthRow['projection_state']
                ?? null
        );
        $canonicalPublicType = $this->firstString([
            $projectionRow['canonical_public_type'] ?? null,
            $projectionRow['public_resolution_type'] ?? null,
            $projectionRow['public_type'] ?? null,
            $truthRow['canonical_public_type'] ?? null,
            $truthRow['public_resolution_type'] ?? null,
            $truthRow['public_type'] ?? null,
            $planPublicType,
        ]);
        $runtimeExpectation = $this->runtimeExpectation(
            projectionState: $projectionState,
            runtimePublishState: $runtimePublishState,
            truthState: $truthState,
            canonicalPublicType: $canonicalPublicType,
            planRuntimeExpectation: $planRuntimeExpectation,
        );

        $issues = $this->issuesFor(
            slug: $slug,
            locale: $locale,
            runtimeExpectation: $runtimeExpectation,
            projectionRow: $projectionRow,
            truthRow: $truthRow,
            projectionState: $projectionState,
            runtimePublishState: $runtimePublishState,
            truthState: $truthState,
            canonicalPublicType: $canonicalPublicType,
            ledgerMemberExists: $ledgerMemberExists,
        );
        $evidence = $this->evidenceFor(
            slug: $slug,
            locale: $locale,
            projectionState: $projectionState,
            runtimePublishState: $runtimePublishState,
            truthState: $truthState,
            canonicalPublicType: $canonicalPublicType,
            runtimeExpectation: $runtimeExpectation,
            ledgerMemberExists: $ledgerMemberExists,
        );

        return new CareerRuntimeProjectionTruthEligibilityRow(
            canonicalSlug: $slug,
            locale: $locale,
            ledgerMemberExists: $ledgerMemberExists,
            projectionExists: $projectionRow !== null,
            truthExists: $truthRow !== null,
            projectionState: $projectionState,
            runtimePublishState: $runtimePublishState,
            truthState: $truthState,
            canonicalPublicType: $canonicalPublicType,
            runtimeStatus: $this->runtimeLayerStatus($issues, $evidence),
            evidence: $evidence,
            issues: $issues,
        );
    }

    /**
     * @param  array<string, mixed>|null  $projectionRow
     * @param  array<string, mixed>|null  $truthRow
     * @return list<CareerRuntimeProjectionTruthEligibilityIssue>
     */
    private function issuesFor(
        string $slug,
        string $locale,
        string $runtimeExpectation,
        ?array $projectionRow,
        ?array $truthRow,
        ?string $projectionState,
        ?string $runtimePublishState,
        ?string $truthState,
        ?string $canonicalPublicType,
        ?bool $ledgerMemberExists,
    ): array {
        $issues = [];
        $rowEvidence = [['slug' => $slug, 'locale' => $locale]];
        $strictPublishedExpected = $runtimeExpectation === self::EXPECTED_PUBLISHED;

        if ($strictPublishedExpected && $ledgerMemberExists === false) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::LEDGER_MEMBER_MISSING,
                message: 'Ledger input was provided but did not include the expected canonical slug.',
                severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: $rowEvidence,
            );
        }

        if ($strictPublishedExpected && $projectionRow === null && $truthRow === null) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::LOCALE_ROW_MISSING,
                message: 'No runtime projection or truth row was found for the expected slug and locale.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: $rowEvidence,
            );
        }

        if ($strictPublishedExpected && $projectionRow === null) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING,
                message: 'Runtime publish projection row was not found for the expected slug and locale.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: $rowEvidence,
            );
        } elseif ($strictPublishedExpected && $runtimePublishState !== null && $runtimePublishState !== self::PUBLISHED) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::RUNTIME_PUBLISH_STATE_NOT_PUBLISHED,
                message: 'Runtime publish projection row is not published.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: [['runtime_publish_state' => $runtimePublishState]],
            );
        } elseif ($strictPublishedExpected && $runtimePublishState === null && $projectionState !== null && $projectionState !== self::PUBLISHED) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_STATE_NOT_PUBLISHED,
                message: 'Projection row state is not published.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: [['projection_state' => $projectionState]],
            );
        }

        if ($strictPublishedExpected && $truthRow === null) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_ROW_MISSING,
                message: 'Canonical runtime truth row was not found for the expected slug and locale.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: $rowEvidence,
            );
        } elseif ($strictPublishedExpected && $truthState !== null && $truthState !== self::PUBLISHED) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_STATE_NOT_PUBLISHED,
                message: 'Canonical runtime truth row state is not published.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: [['truth_state' => $truthState]],
            );
        }

        if ($strictPublishedExpected && $canonicalPublicType !== null && $canonicalPublicType !== self::PUBLIC_CANONICAL_JOB) {
            $issues[] = new CareerRuntimeProjectionTruthEligibilityIssue(
                reason: CareerRuntimeProjectionTruthEligibilityIssue::CANONICAL_PUBLIC_TYPE_INVALID,
                message: 'Runtime projection/truth row exposes a non-canonical public type.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $slug,
                locale: $locale,
                evidence: [['canonical_public_type' => $canonicalPublicType]],
            );
        }

        return $issues;
    }

    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    private function runtimeLayerStatus(array $issues, array $evidence): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::RUNTIME,
            status: $issues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            reasons: array_values(array_unique(array_map(
                static fn (CareerRuntimeProjectionTruthEligibilityIssue $issue): string => $issue->reason,
                $issues
            ))),
            evidence: $evidence,
            source: 'runtime_projection_truth',
        );
    }

    /**
     * @return list<mixed>
     */
    private function evidenceFor(
        string $slug,
        string $locale,
        ?string $projectionState,
        ?string $runtimePublishState,
        ?string $truthState,
        ?string $canonicalPublicType,
        string $runtimeExpectation,
        ?bool $ledgerMemberExists,
    ): array {
        $evidence = [['slug' => $slug, 'locale' => $locale, 'runtime_expectation' => $runtimeExpectation]];

        if ($ledgerMemberExists !== null) {
            $evidence[] = ['ledger_member_exists' => $ledgerMemberExists];
        }
        if ($projectionState !== null) {
            $evidence[] = ['projection_state' => $projectionState];
        }
        if ($runtimePublishState !== null) {
            $evidence[] = ['runtime_publish_state' => $runtimePublishState];
        }
        if ($truthState !== null) {
            $evidence[] = ['truth_state' => $truthState];
        }
        if ($canonicalPublicType !== null) {
            $evidence[] = ['canonical_public_type' => $canonicalPublicType];
        }

        return $evidence;
    }

    private function runtimeExpectation(
        ?string $projectionState,
        ?string $runtimePublishState,
        ?string $truthState,
        ?string $canonicalPublicType,
        ?string $planRuntimeExpectation,
    ): string {
        if ($runtimePublishState === self::PUBLISHED || $projectionState === self::PUBLISHED || $truthState === self::PUBLISHED) {
            return self::EXPECTED_PUBLISHED;
        }

        if ($canonicalPublicType === self::PUBLIC_CANONICAL_JOB) {
            return self::EXPECTED_PUBLISHED;
        }

        if ($canonicalPublicType !== null && $this->isGovernedNonPublicType($canonicalPublicType)) {
            return self::GOVERNED_NON_PUBLIC;
        }

        return $planRuntimeExpectation ?? self::EXPECTED_PUBLISHED;
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $artifact
     * @return list<array<string, mixed>>
     */
    private function artifactRows(array $artifact): array
    {
        $candidates = [
            $artifact,
            $artifact['items'] ?? null,
            $artifact['rows'] ?? null,
            $artifact['projection']['items'] ?? null,
            $artifact['truth']['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row)));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsBySlugLocale(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $slug = $this->slugForArrayRow($row);
            $locale = $this->normalizeString($row['locale'] ?? null);
            if ($slug === null || $locale === null) {
                continue;
            }

            $indexed[$this->rowKey($slug, strtolower($locale))] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $ledger
     * @return list<string>
     */
    private function ledgerSlugs(array $ledger): array
    {
        $rows = $this->ledgerRows($ledger);
        $slugs = [];
        foreach ($rows as $row) {
            $slug = $this->slugForArrayRow($row);
            if ($slug !== null && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $ledger
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(array $ledger): array
    {
        $candidates = [
            $ledger,
            $ledger['public_resolution']['rows'] ?? null,
            $ledger['members'] ?? null,
            $ledger['items'] ?? null,
            $ledger['rows'] ?? null,
            $ledger['ledger']['public_resolution']['rows'] ?? null,
            $ledger['ledger']['members'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row)));
            }
        }

        return [];
    }

    private function slugForPlanRow(mixed $row): ?string
    {
        if ($row instanceof CareerPublicResolutionPlanRow) {
            return $this->normalizeSlug($row->canonicalSlug);
        }

        if (is_string($row)) {
            return $this->normalizeSlug($row);
        }

        if (is_array($row)) {
            return $this->slugForArrayRow($row);
        }

        if (is_object($row) && property_exists($row, 'canonicalSlug')) {
            return $this->normalizeSlug($row->canonicalSlug);
        }

        return null;
    }

    private function publicTypeForRow(mixed $row): ?string
    {
        if ($row instanceof CareerPublicResolutionPlanRow) {
            return $this->normalizeString($row->canonicalPublicType);
        }

        if (is_array($row)) {
            return $this->firstString([
                $row['canonical_public_type'] ?? null,
                $row['public_resolution_type'] ?? null,
                $row['public_type'] ?? null,
            ]);
        }

        if (is_object($row) && property_exists($row, 'canonicalPublicType')) {
            return $this->normalizeString($row->canonicalPublicType);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function planStates(mixed $row): array
    {
        $values = [];

        if ($row instanceof CareerPublicResolutionPlanRow) {
            $values = [
                $row->publicResolutionState,
                $row->rolloutState,
                $row->projectionState,
            ];
        } elseif (is_array($row)) {
            $values = [
                $row['public_resolution_state'] ?? null,
                $row['status'] ?? null,
                $row['current_status'] ?? null,
                $row['rollout_state'] ?? null,
                $row['projection_state'] ?? null,
            ];
        } elseif (is_object($row)) {
            $values = [
                property_exists($row, 'publicResolutionState') ? $row->publicResolutionState : null,
                property_exists($row, 'rolloutState') ? $row->rolloutState : null,
                property_exists($row, 'projectionState') ? $row->projectionState : null,
            ];
        }

        $states = [];
        foreach ($values as $value) {
            $state = $this->normalizeString($value);
            if ($state !== null) {
                $states[] = strtolower($state);
            }
        }

        return array_values(array_unique($states));
    }

    private function isGovernedNonPublicType(string $publicType): bool
    {
        return in_array($publicType, [
            'blocked',
            'blocked_until_governance_approval',
            'explorer_only',
            'family_handoff',
            'governed_non_public',
            'not_public',
            'review_needed',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function slugForArrayRow(array $row): ?string
    {
        return $this->normalizeSlug($row['canonical_slug'] ?? $row['source_slug'] ?? $row['slug'] ?? null);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeString($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function rowKey(string $slug, string $locale): string
    {
        return $slug.'|'.$locale;
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
