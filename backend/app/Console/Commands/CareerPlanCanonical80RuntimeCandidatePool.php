<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\Career2786ReadinessPolicyClassifier;
use App\Domain\Career\Audit\Career2786ReadinessPolicyRow;
use App\Domain\Career\Audit\Career80RolloutCandidateGate;
use App\Domain\Career\Audit\CareerCanonical80CandidateSelectionRow;
use App\Domain\Career\Audit\CareerCanonical80CandidateSelector;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CareerPlanCanonical80RuntimeCandidatePool extends Command
{
    private const SCHEMA_VERSION = 'career_80_runtime_candidate_pool_plan.v1';

    private const CANDIDATE_AWARE_OVERLAY_SOURCE = 'candidate_prep_apply_overlay';

    private const CANDIDATE_AWARE_INDEX_STATE = 'promotion_candidate';

    /**
     * These stale audit blockers specifically indicate a verified pre-promotion candidate overlay is required before
     * rollout planning can treat promotion_candidate / published_candidate evidence as valid.
     *
     * @var list<string>
     */
    private const CANDIDATE_AWARE_STATE_POLICY_BLOCKERS = [
        'index_state_not_indexed_like',
        'runtime_publish_state_not_published',
        'truth_state_not_published',
    ];

    /**
     * Runtime candidate pool planning validates projection/truth/ledger state directly below. These audit reasons are
     * expected stale/full-publication blockers for verified pre-promotion candidates and must not remove them before
     * candidate-aware artifact evidence can be evaluated.
     *
     * @var list<string>
     */
    private const RUNTIME_POOL_SELECTOR_HARD_BLOCKERS = [
        'occupation_missing',
        'entity_field_missing',
        'index_state_missing',
        'projection_row_missing',
        'truth_row_missing',
        'locale_row_missing',
        'ledger_member_missing',
        'canonical_public_type_invalid',
        'surface_context_missing',
    ];

    /**
     * @var list<string>
     */
    private const CANDIDATE_AWARE_ALLOWED_POLICY_BLOCKERS = [
        'index_state_not_indexed_like',
        'runtime_publish_state_not_published',
        'truth_state_not_published',
        'sitemap_expected_not_ready',
        'llms_expected_not_ready',
        'llms_full_expected_not_ready',
        'sitemap_missing',
        'llms_missing',
        'llms_full_missing',
        'structured_data_missing',
        'citation_metadata_missing',
        'surface_artifact_missing',
        'surface_unverified',
        'zh_baseline_missing',
        'en_title_derivation_required',
        'required_display_field_missing',
    ];

    protected $signature = 'career:plan-canonical-80-runtime-candidate-pool
        {--audit= : Required post-apply Career canonical eligibility audit JSON artifact}
        {--projection= : Required runtime projection JSON artifact}
        {--truth= : Required runtime truth JSON artifact}
        {--ledger= : Required full release ledger JSON artifact}
        {--target=80 : Target runtime candidate pool size}
        {--delta-slugs= : Optional explicit progressive delta slug artifact for 300/800/2786 pool planning}
        {--readiness-plan= : Optional progressive readiness plan artifact containing selected_slugs}
        {--target-total= : Optional progressive target public total metadata}
        {--cohort= : Optional progressive cohort identifier}
        {--locales=en,zh : Comma-separated locales required for each candidate}
        {--json : Emit JSON output}
        {--output= : Optional output path for candidate pool plan JSON}
        {--strict : Fail on malformed or ambiguous artifacts}';

    protected $description = 'Plan the read-only Career 80 pre-promotion runtime candidate pool from audit, projection, truth, and ledger artifacts.';

    public function handle(): int
    {
        try {
            $auditPath = $this->requiredOption('audit');
            $projectionPath = $this->requiredOption('projection');
            $truthPath = $this->requiredOption('truth');
            $ledgerPath = $this->requiredOption('ledger');
            $target = $this->positiveIntOption('target', 80);
            $targetTotal = $this->optionalPositiveIntOption('target-total');
            $cohort = $this->optionalStringOption('cohort');
            $locales = $this->localesOption();
            $explicitDeltaSelection = $this->explicitDeltaSelection($target, $targetTotal, $cohort);

            [$audit, $report] = $this->readAudit($auditPath);
            $projection = $this->readArtifact($projectionPath, 'projection');
            $truth = $this->readArtifact($truthPath, 'truth');
            $ledger = $this->readArtifact($ledgerPath, 'ledger');

            $payload = $this->buildPlan(
                auditPath: $auditPath,
                projectionPath: $projectionPath,
                truthPath: $truthPath,
                ledgerPath: $ledgerPath,
                audit: $audit,
                report: $report,
                projection: $projection,
                truth: $truth,
                ledger: $ledger,
                target: $target,
                explicitDeltaSelection: $explicitDeltaSelection,
                locales: $locales,
            );

            return $this->finish($payload, $payload['pool_pass'] === true ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish($this->blockedPayload($this->reasonKey($exception->getMessage()), $exception->getMessage()), self::FAILURE);
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $value;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    private function optionalPositiveIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    private function optionalStringOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function localesOption(): array
    {
        $raw = trim((string) ($this->option('locales') ?? 'en,zh'));
        $locales = array_values(array_unique(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', $raw)
        ))));

        if ($locales === []) {
            throw new RuntimeException('locales_missing');
        }

        sort($locales);

        return $locales;
    }

    /**
     * @return array{strategy: string, path: string, sha256: string|null, slug_count: int, slugs: list<string>, target_total: int|null, cohort: string|null}|null
     */
    private function explicitDeltaSelection(int $target, ?int $targetTotal, ?string $cohort): ?array
    {
        $readinessPlanPath = $this->optionalStringOption('readiness-plan');
        $deltaSlugsPath = $this->optionalStringOption('delta-slugs');

        if ($readinessPlanPath === null && $deltaSlugsPath === null) {
            return null;
        }

        if ($readinessPlanPath !== null) {
            $readinessPlan = $this->readArtifact($readinessPlanPath, 'readiness_plan');
            $slugs = $this->extractSlugListFromArtifact($readinessPlan);

            if ($slugs === []) {
                throw new RuntimeException('readiness_plan_selected_slugs_missing');
            }

            if ($targetTotal !== null && (int) ($readinessPlan['target_public_total'] ?? 0) !== $targetTotal) {
                throw new RuntimeException('readiness_plan_target_total_mismatch');
            }

            if (isset($readinessPlan['blockers']) && is_array($readinessPlan['blockers']) && $readinessPlan['blockers'] !== []) {
                throw new RuntimeException('readiness_plan_blocked');
            }

            $slugs = $this->normalizeSlugList($slugs, 'readiness_plan_selected_slugs');
            $this->assertExplicitDeltaCount($slugs, $target);

            return [
                'strategy' => 'progressive_readiness_plan_selected_slugs',
                'path' => $readinessPlanPath,
                'sha256' => hash_file('sha256', $readinessPlanPath) ?: null,
                'slug_count' => count($slugs),
                'slugs' => $slugs,
                'target_total' => $targetTotal,
                'cohort' => $cohort,
            ];
        }

        $slugs = $this->normalizeSlugList($this->readSlugArtifact($deltaSlugsPath), 'delta_slugs');
        $this->assertExplicitDeltaCount($slugs, $target);

        return [
            'strategy' => 'progressive_delta_slug_artifact',
            'path' => $deltaSlugsPath,
            'sha256' => hash_file('sha256', $deltaSlugsPath) ?: null,
            'slug_count' => count($slugs),
            'slugs' => $slugs,
            'target_total' => $targetTotal,
            'cohort' => $cohort,
        ];
    }

    /**
     * @return list<string>
     */
    private function readSlugArtifact(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('delta_slugs_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('delta_slugs_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                return $decoded;
            }

            return $this->extractSlugListFromArtifact($decoded);
        }

        return preg_split('/[\s,]+/', trim($contents)) ?: [];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function extractSlugListFromArtifact(array $artifact): array
    {
        foreach ([
            ['selected_slugs'],
            ['delta_slugs'],
            ['slugs'],
            ['selection', 'slugs'],
            ['runtime_candidate_gate', 'selected_slugs'],
        ] as $path) {
            $value = data_get($artifact, implode('.', $path));
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeSlugList(array $values, string $context): array
    {
        $slugs = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException($context.'_invalid_slug');
            }

            $slug = trim($value);
            if (isset($seen[$slug])) {
                throw new RuntimeException($context.'_duplicate_slug');
            }

            $seen[$slug] = true;
            $slugs[] = $slug;
        }

        if ($slugs === []) {
            throw new RuntimeException($context.'_missing');
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function assertExplicitDeltaCount(array $slugs, int $target): void
    {
        if (count($slugs) !== $target) {
            throw new RuntimeException('explicit_delta_slug_count_mismatch');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: CareerCanonicalEligibilityReport}
     */
    private function readAudit(string $path): array
    {
        $decoded = $this->readArtifact($path, 'audit');

        try {
            $report = CareerCanonicalEligibilityReport::fromArray($decoded);
        } catch (InvalidArgumentException $exception) {
            if ((bool) $this->option('strict')) {
                throw new RuntimeException('audit_artifact_shape_invalid');
            }

            throw new RuntimeException($exception->getMessage());
        }

        return [$decoded, $report];
    }

    /**
     * @return array<string, mixed>
     */
    private function readArtifact(string $path, string $kind): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($kind.'_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException($kind.'_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($kind.'_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($kind.'_artifact_shape_invalid');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $ledger
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function buildPlan(
        string $auditPath,
        string $projectionPath,
        string $truthPath,
        string $ledgerPath,
        array $audit,
        CareerCanonicalEligibilityReport $report,
        array $projection,
        array $truth,
        array $ledger,
        int $target,
        ?array $explicitDeltaSelection,
        array $locales,
    ): array {
        $policy = (new Career2786ReadinessPolicyClassifier)->classify($report, $target);
        $policyRowsBySlug = [];
        foreach ($policy->rows as $row) {
            $policyRowsBySlug[$row->canonicalSlug] = $row;
        }

        $projectionBySlug = $this->rowsBySlugLocale($this->artifactRows($projection, ['items', 'rows']));
        $truthBySlug = $this->rowsBySlugLocale($this->artifactRows($truth, ['items', 'rows']));
        $ledgerBySlug = $this->ledgerBySlug($this->artifactRows($ledger, ['members', 'items', 'rows']));
        $auditRowsBySlug = $this->auditRowsBySlug($report);
        $explicitProgressiveDelta = $explicitDeltaSelection !== null;
        $selectionRows = $explicitProgressiveDelta
            ? $this->explicitProgressiveSelectionRows($explicitDeltaSelection['slugs'])
            : (new CareerCanonical80CandidateSelector)->select(
                report: $report,
                targetCount: $target,
                hardBlockers: self::RUNTIME_POOL_SELECTOR_HARD_BLOCKERS,
            )->rows;

        $eligible = [];
        $excluded = [];
        $exclusionsByReason = [];
        $baseCandidateCount = 0;
        $auditGate = new Career80RolloutCandidateGate;

        foreach ($selectionRows as $row) {
            $policyRow = $policyRowsBySlug[$row->canonicalSlug] ?? null;
            if (! $policyRow instanceof Career2786ReadinessPolicyRow) {
                continue;
            }

            $candidateAwareEvidence = $this->candidateAwarePlanningEvidence(
                slug: $row->canonicalSlug,
                locales: $locales,
                projectionRows: $projectionBySlug[$row->canonicalSlug] ?? [],
                truthRows: $truthBySlug[$row->canonicalSlug] ?? [],
                ledgerMember: $ledgerBySlug[$row->canonicalSlug] ?? null,
                auditRows: $auditRowsBySlug[$row->canonicalSlug] ?? [],
                policyRow: $policyRow,
                explicitProgressiveDelta: $explicitProgressiveDelta,
            );

            if (! $this->isBaseCandidate($row, $policyRow, $candidateAwareEvidence, $explicitProgressiveDelta)) {
                continue;
            }

            $baseCandidateCount++;
            $gate = $this->evaluateRuntimeCandidate(
                slug: $row->canonicalSlug,
                locales: $locales,
                projectionRows: $projectionBySlug[$row->canonicalSlug] ?? [],
                truthRows: $truthBySlug[$row->canonicalSlug] ?? [],
                ledgerMember: $ledgerBySlug[$row->canonicalSlug] ?? null,
                auditGate: $auditGate->evaluate($auditRowsBySlug[$row->canonicalSlug] ?? []),
                candidateAwareEvidence: $candidateAwareEvidence,
                explicitProgressiveDelta: $explicitProgressiveDelta,
            );

            if ($gate['eligible'] === true) {
                $eligible[] = $this->eligibleRow($row, $policyRow, $gate);

                continue;
            }

            foreach ($gate['reasons'] as $reason) {
                $exclusionsByReason[$reason] = ($exclusionsByReason[$reason] ?? 0) + 1;
            }
            ksort($exclusionsByReason);
            $excluded[] = $this->excludedRow($row, $policyRow, $gate);
        }

        $selected = array_slice($eligible, 0, $target);
        $blockers = $this->blockers($report, $target, count($eligible), count($selected));
        $poolPass = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $poolPass ? 'pass' : 'blocked',
            'pool_pass' => $poolPass,
            'read_only' => true,
            'writes_database' => false,
            'target' => $target,
            'locales' => $locales,
            'base_candidate_count' => $baseCandidateCount,
            'eligible_count' => count($eligible),
            'selected_count' => count($selected),
            'excluded_count' => count($excluded),
            'exclusions_by_reason' => $exclusionsByReason,
            'source_artifacts' => [
                'audit' => $this->sourceArtifact($auditPath, [
                    'status' => $report->status,
                    'expected_occupations' => $report->expectedOccupations,
                    'audited_occupations' => $report->auditedOccupations,
                ]),
                'projection' => $this->sourceArtifact($projectionPath, [
                    'row_count' => count($this->artifactRows($projection, ['items', 'rows'])),
                    'published_candidate_count' => $this->stateCount($projection, 'runtime_publish_state', Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE),
                ]),
                'truth' => $this->sourceArtifact($truthPath, [
                    'row_count' => count($this->artifactRows($truth, ['items', 'rows'])),
                    'published_candidate_count' => $this->stateCount($truth, 'projection_state', Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE),
                ]),
                'ledger' => $this->sourceArtifact($ledgerPath, [
                    'member_count' => count($this->artifactRows($ledger, ['members', 'items', 'rows'])),
                ]),
                'explicit_delta_selection' => $explicitDeltaSelection,
            ],
            'runtime_candidate_gate' => [
                'required' => true,
                'expected_runtime_state' => Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE,
                'eligible_count' => count($eligible),
                'excluded_count' => count($excluded),
                'exclusions_by_reason' => $exclusionsByReason,
                'selected_slugs' => array_map(
                    static fn (array $row): string => (string) $row['slug'],
                    $selected
                ),
                'eligible_rows' => $eligible,
                'excluded_rows' => $excluded,
            ],
            'selection' => [
                'strategy' => $explicitProgressiveDelta
                    ? 'progressive_explicit_delta_runtime_candidate_pool'
                    : 'runtime_published_candidate_pool_ranked',
                'slugs' => array_map(
                    static fn (array $row): string => (string) $row['slug'],
                    $selected
                ),
                'rows' => $selected,
            ],
            'recovery_plan' => $this->recoveryPlan($target, count($eligible), $exclusionsByReason),
            'blockers' => $blockers,
            'rollout' => [
                'manifest_generation_allowed' => $poolPass,
                'dry_run_allowed' => $poolPass,
                'apply_allowed' => false,
                'reason' => 'runtime candidate pool planning only; rollout apply requires separate approval',
            ],
            'next_required_action' => $poolPass
                ? ($explicitProgressiveDelta ? 'PROGRESSIVE_ROLLOUT_MANIFEST' : '80_READINESS_RERUN_WITH_RUNTIME_POOL')
                : 'FIX_RUNTIME_CANDIDATE_POOL',
        ];
    }

    /**
     * @param  array{required: bool, eligible: bool, reasons: list<string>, evidence: array<string, mixed>}  $candidateAwareEvidence
     */
    private function isBaseCandidate(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow, array $candidateAwareEvidence, bool $explicitProgressiveDelta): bool
    {
        if (! in_array($row->candidateStatus, [
            CareerCanonical80CandidateSelectionRow::STATUS_READY,
            CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE,
        ], true)) {
            return false;
        }

        if ($explicitProgressiveDelta) {
            return $row->hardBlockers === [] && $candidateAwareEvidence['required'];
        }

        if ($candidateAwareEvidence['required'] && $row->hardBlockers === [] && $this->policyOnlyHasCandidateAwarePlanningBlockers($policyRow)) {
            return true;
        }

        return $row->hardBlockers === []
            && $policyRow->hardBlockerReasons === []
            && $policyRow->remediationRequiredReasons === []
            && ($policyRow->nearEligible || $policyRow->eligibleCandidate);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $projectionRows
     * @param  array<string, list<array<string, mixed>>>  $truthRows
     * @param  array<string, mixed>|null  $ledgerMember
     * @param  array{eligible: bool, reasons: list<string>, evidence: array<string, mixed>}  $auditGate
     * @param  array{required: bool, eligible: bool, reasons: list<string>, evidence: array<string, mixed>}  $candidateAwareEvidence
     * @param  list<string>  $locales
     * @return array{eligible: bool, reasons: list<string>, evidence: array<string, mixed>}
     */
    private function evaluateRuntimeCandidate(
        string $slug,
        array $locales,
        array $projectionRows,
        array $truthRows,
        ?array $ledgerMember,
        array $auditGate,
        array $candidateAwareEvidence,
        bool $explicitProgressiveDelta,
    ): array {
        $reasons = $candidateAwareEvidence['reasons'];
        $evidence = [
            'slug' => $slug,
            'required_locales' => $locales,
            'ledger_member_exists' => $ledgerMember !== null,
            'ledger_release_cohort' => $ledgerMember['release_cohort'] ?? null,
            'ledger_public_index_state' => $ledgerMember['public_index_state'] ?? null,
            'candidate_aware_overlay' => $candidateAwareEvidence['evidence'],
            'projection_states' => [],
            'truth_states' => [],
            'projection_locales_present' => [],
            'truth_locales_present' => [],
        ];

        if ($ledgerMember === null) {
            $reasons[] = 'ledger_member_missing';
        } elseif (in_array($ledgerMember['release_cohort'] ?? null, ['review_needed', 'family_handoff'], true)) {
            $reasons[] = 'ledger_not_candidate_ready';
        }

        foreach ($locales as $locale) {
            $projectionLocaleRows = $projectionRows[$locale] ?? [];
            $truthLocaleRows = $truthRows[$locale] ?? [];
            if ($projectionLocaleRows === []) {
                $reasons[] = 'projection_row_missing';
            } else {
                $evidence['projection_locales_present'][] = $locale;
                foreach ($projectionLocaleRows as $projectionRow) {
                    $this->evaluateProjectionRow($projectionRow, $reasons, $evidence);
                }
            }

            if ($truthLocaleRows === []) {
                $reasons[] = 'truth_row_missing';
            } else {
                $evidence['truth_locales_present'][] = $locale;
                foreach ($truthLocaleRows as $truthRow) {
                    $this->evaluateTruthRow($truthRow, $reasons, $evidence);
                }
            }
        }

        if (! ($explicitProgressiveDelta && $candidateAwareEvidence['eligible'])) {
            foreach ($auditGate['reasons'] as $reason) {
                $reasons[] = $reason;
            }
        }

        $reasons = $this->normalizeStrings($reasons);
        $evidence['projection_states'] = $this->normalizeStrings($evidence['projection_states']);
        $evidence['truth_states'] = $this->normalizeStrings($evidence['truth_states']);
        $evidence['projection_locales_present'] = $this->normalizeStrings($evidence['projection_locales_present']);
        $evidence['truth_locales_present'] = $this->normalizeStrings($evidence['truth_locales_present']);

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $evidence
     */
    private function evaluateProjectionRow(array $row, array &$reasons, array &$evidence): void
    {
        $state = $this->stringValue($row, 'runtime_publish_state');
        if ($state === null) {
            $state = $this->stringValue($row, 'projection_state');
        }
        if ($state !== null) {
            $evidence['projection_states'][] = $state;
        }

        if ($state === 'published') {
            $reasons[] = 'already_published';
        } elseif ($state === 'blocked') {
            $reasons[] = 'runtime_state_blocked';
        } elseif ($state !== Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE) {
            $reasons[] = 'projection_state_mismatch';
        }

        if (($this->stringValue($row, 'public_resolution_type') ?? '') !== 'public_canonical_job') {
            $reasons[] = 'projection_state_mismatch';
        }

        if (($row['detail_route_enabled'] ?? false) === true) {
            $reasons[] = 'unexpected_route_exposure';
        }
        if (($row['dataset_visible'] ?? false) === true || ($row['search_visible'] ?? false) === true) {
            $reasons[] = 'unexpected_api_exposure';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $evidence
     */
    private function evaluateTruthRow(array $row, array &$reasons, array &$evidence): void
    {
        $state = $this->stringValue($row, 'projection_state');
        if ($state === null) {
            $state = $this->stringValue($row, 'runtime_publish_state');
        }
        if ($state !== null) {
            $evidence['truth_states'][] = $state;
        }

        if ($state === 'published') {
            $reasons[] = 'already_published';
        } elseif ($state === 'blocked') {
            $reasons[] = 'runtime_state_blocked';
        } elseif ($state !== Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE) {
            $reasons[] = 'truth_state_mismatch';
        }

        if (($this->stringValue($row, 'public_resolution_type') ?? '') !== 'public_canonical_job') {
            $reasons[] = 'truth_state_mismatch';
        }

        if (($row['candidate_pre_route_expected'] ?? null) !== true) {
            $reasons[] = 'pre_route_not_expected';
        }
        if (($row['route_exists'] ?? false) === true || ($row['final_200'] ?? false) === true) {
            $reasons[] = 'unexpected_route_exposure';
        }
        if (($row['dataset_visible'] ?? false) === true || ($row['search_visible'] ?? false) === true) {
            $reasons[] = 'unexpected_api_exposure';
        }

        $exposures = $row['candidate_unexpected_exposures'] ?? [];
        if (is_array($exposures)) {
            if (in_array('api', $exposures, true)) {
                $reasons[] = 'unexpected_api_exposure';
            }
            if (in_array('route', $exposures, true)) {
                $reasons[] = 'unexpected_route_exposure';
            }
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $projectionRows
     * @param  array<string, list<array<string, mixed>>>  $truthRows
     * @param  array<string, mixed>|null  $ledgerMember
     * @param  list<\App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return array{required: bool, eligible: bool, reasons: list<string>, evidence: array<string, mixed>}
     */
    private function candidateAwarePlanningEvidence(
        string $slug,
        array $locales,
        array $projectionRows,
        array $truthRows,
        ?array $ledgerMember,
        array $auditRows,
        Career2786ReadinessPolicyRow $policyRow,
        bool $explicitProgressiveDelta,
    ): array {
        $reasons = [];
        $projectionOverlayRows = 0;
        $truthOverlayRows = 0;
        $sourceHashes = [];
        $required = $explicitProgressiveDelta || $this->candidateAwarePlanningRequired($policyRow);

        $ledgerOverlay = $ledgerMember !== null
            && ($this->stringValue($ledgerMember, 'overlay_source') ?? '') === self::CANDIDATE_AWARE_OVERLAY_SOURCE;
        $writeVerified = $ledgerOverlay
            && (bool) data_get($ledgerMember, 'evidence_refs.candidate_prep_apply.write_verified', false) === true;
        $ledgerIndexState = $ledgerMember === null ? null : $this->stringValue($ledgerMember, 'current_index_state');
        $ledgerRuntimeState = $ledgerMember === null ? null : $this->stringValue($ledgerMember, 'runtime_publish_state');

        if (! $ledgerOverlay) {
            $reasons[] = 'candidate_aware_overlay_missing';
        }
        if (! $writeVerified) {
            $reasons[] = 'candidate_prep_apply_not_verified';
        }
        if ($ledgerIndexState !== self::CANDIDATE_AWARE_INDEX_STATE) {
            $reasons[] = 'candidate_index_state_mismatch';
        }
        if ($ledgerRuntimeState !== Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE) {
            $reasons[] = 'candidate_runtime_state_mismatch';
        }

        foreach ($locales as $locale) {
            foreach ($projectionRows[$locale] ?? [] as $projectionRow) {
                if (($this->stringValue($projectionRow, 'overlay_source') ?? '') === self::CANDIDATE_AWARE_OVERLAY_SOURCE) {
                    $projectionOverlayRows++;
                    $hash = $this->stringValue($projectionRow, 'source_artifact_sha256');
                    if ($hash !== null) {
                        $sourceHashes[] = $hash;
                    }
                }
            }
            foreach ($truthRows[$locale] ?? [] as $truthRow) {
                if (($this->stringValue($truthRow, 'overlay_source') ?? '') === self::CANDIDATE_AWARE_OVERLAY_SOURCE) {
                    $truthOverlayRows++;
                    $hash = $this->stringValue($truthRow, 'source_artifact_sha256');
                    if ($hash !== null) {
                        $sourceHashes[] = $hash;
                    }
                }
            }
        }

        if ($projectionOverlayRows < count($locales)) {
            $reasons[] = 'candidate_aware_projection_overlay_missing';
        }
        if ($truthOverlayRows < count($locales)) {
            $reasons[] = 'candidate_aware_truth_overlay_missing';
        }
        if (! $explicitProgressiveDelta && ! $this->auditShowsCandidateAwareIndexState($auditRows)) {
            $reasons[] = 'candidate_aware_audit_index_evidence_missing';
        }
        if (! $explicitProgressiveDelta && ! $this->policyOnlyHasCandidateAwarePlanningBlockers($policyRow)) {
            $reasons[] = 'candidate_policy_has_non_candidate_aware_blocker';
        }

        $reasons = $required ? $this->normalizeStrings($reasons) : [];

        return [
            'required' => $required,
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'evidence' => [
                'required' => $required,
                'overlay_source' => self::CANDIDATE_AWARE_OVERLAY_SOURCE,
                'ledger_overlay' => $ledgerOverlay,
                'candidate_prep_apply_write_verified' => $writeVerified,
                'ledger_index_state' => $ledgerIndexState,
                'ledger_runtime_state' => $ledgerRuntimeState,
                'projection_overlay_rows' => $projectionOverlayRows,
                'truth_overlay_rows' => $truthOverlayRows,
                'audit_index_state' => $this->auditIndexStates($auditRows),
                'explicit_progressive_delta' => $explicitProgressiveDelta,
                'audit_index_evidence_required' => ! $explicitProgressiveDelta,
                'policy_blocker_veto_required' => ! $explicitProgressiveDelta,
                'source_artifact_sha256_values' => $this->normalizeStrings($sourceHashes),
            ],
        ];
    }

    private function candidateAwarePlanningRequired(Career2786ReadinessPolicyRow $policyRow): bool
    {
        return array_intersect($policyRow->reasons, self::CANDIDATE_AWARE_STATE_POLICY_BLOCKERS) !== []
            || array_intersect($policyRow->hardBlockerReasons, self::CANDIDATE_AWARE_STATE_POLICY_BLOCKERS) !== [];
    }

    private function policyOnlyHasCandidateAwarePlanningBlockers(Career2786ReadinessPolicyRow $policyRow): bool
    {
        $blockingReasons = $this->normalizeStrings(array_values(array_diff([
            ...$policyRow->hardBlockerReasons,
            ...$policyRow->remediationRequiredReasons,
            ...$policyRow->reasons,
        ], self::CANDIDATE_AWARE_ALLOWED_POLICY_BLOCKERS)));

        return $blockingReasons === [];
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow>  $auditRows
     */
    private function auditShowsCandidateAwareIndexState(array $auditRows): bool
    {
        return in_array(self::CANDIDATE_AWARE_INDEX_STATE, $this->auditIndexStates($auditRows), true);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<CareerCanonical80CandidateSelectionRow>
     */
    private function explicitProgressiveSelectionRows(array $slugs): array
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = new CareerCanonical80CandidateSelectionRow(
                canonicalSlug: $slug,
                rank: $index + 1,
                score: max(1, count($slugs) - $index),
                candidateStatus: CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE,
                selected: false,
                hardBlocked: false,
                passedLocaleCount: 0,
                blockedLocaleCount: 0,
                locales: [],
                reasons: [],
                hardBlockers: [],
                layerStatuses: [],
                evidence: [[
                    'source' => 'progressive_explicit_delta_slug_artifact',
                    'selection_order' => $index + 1,
                ]],
            );
        }

        return $rows;
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function auditIndexStates(array $auditRows): array
    {
        $states = [];
        foreach ($auditRows as $row) {
            foreach ($row->indexStatus->evidence as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $state = $this->stringValue($item, 'latest_index_state');
                if ($state !== null) {
                    $states[] = $state;
                }
            }
        }

        return $this->normalizeStrings($states);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockers(CareerCanonicalEligibilityReport $report, int $target, int $eligibleCount, int $selectedCount): array
    {
        $blockers = [];
        if ($report->expectedOccupations !== 2786) {
            $blockers[] = $this->blocker('expected_occupations_mismatch', 'Audit artifact must cover the 2786 Career canonical program.', [
                'expected_occupations' => $report->expectedOccupations,
            ]);
        }
        if ($report->auditedOccupations !== 2786) {
            $blockers[] = $this->blocker('audited_occupations_mismatch', 'Audit artifact must include 2786 audited occupations.', [
                'audited_occupations' => $report->auditedOccupations,
            ]);
        }
        if ($eligibleCount < $target || $selectedCount < $target) {
            $blockers[] = $this->blocker('insufficient_runtime_candidate_pool', 'Fewer than the target published_candidate runtime candidates can be evaluated.', [
                'target' => $target,
                'eligible_count' => $eligibleCount,
                'selected_count' => $selectedCount,
            ]);
        }

        return $blockers;
    }

    /**
     * @param  array<string, int>  $exclusionsByReason
     * @return array<string, mixed>
     */
    private function recoveryPlan(int $target, int $eligibleCount, array $exclusionsByReason): array
    {
        return [
            'required' => $eligibleCount < $target,
            'needed_additional_count' => max(0, $target - $eligibleCount),
            'reason' => $eligibleCount >= $target
                ? 'runtime candidate pool has enough published_candidate rows'
                : 'candidate-state remediation or a larger valid candidate pool is required before readiness can pass',
            'buckets' => [
                'exclude_already_published' => $exclusionsByReason['already_published'] ?? 0,
                'ledger_admission_needed' => $exclusionsByReason['ledger_member_missing'] ?? 0,
                'candidate_state_repair_needed' => ($exclusionsByReason['projection_row_missing'] ?? 0)
                    + ($exclusionsByReason['truth_row_missing'] ?? 0)
                    + ($exclusionsByReason['projection_state_mismatch'] ?? 0)
                    + ($exclusionsByReason['truth_state_mismatch'] ?? 0)
                    + ($exclusionsByReason['runtime_state_blocked'] ?? 0)
                    + ($exclusionsByReason['ledger_not_candidate_ready'] ?? 0),
            ],
            'approval_gated_apply_ready' => false,
            'approval_gate_reason' => 'This planner is read-only and only produces evidence for a later reviewed explicit-slug remediation artifact.',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sourceArtifact(string $path, array $extra = []): array
    {
        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path) ?: null,
            ...$extra,
        ];
    }

    /**
     * @return array<string, list<\App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow>>
     */
    private function auditRowsBySlug(CareerCanonicalEligibilityReport $report): array
    {
        $rowsBySlug = [];
        foreach ($report->rows as $row) {
            $rowsBySlug[$row->slug] ??= [];
            $rowsBySlug[$row->slug][] = $row;
        }

        return $rowsBySlug;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function artifactRows(array $artifact, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($artifact[$key]) && is_array($artifact[$key]) && array_is_list($artifact[$key])) {
                return array_values(array_filter(
                    $artifact[$key],
                    static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)
                ));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function rowsBySlugLocale(array $rows): array
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = $this->stringValue($row, 'slug');
            $locale = $this->stringValue($row, 'locale');
            if ($slug === null || $locale === null) {
                continue;
            }

            $bySlug[$slug] ??= [];
            $bySlug[$slug][$locale] ??= [];
            $bySlug[$slug][$locale][] = $row;
        }

        return $bySlug;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, array<string, mixed>>
     */
    private function ledgerBySlug(array $members): array
    {
        $bySlug = [];
        foreach ($members as $member) {
            $slug = $this->stringValue($member, 'canonical_slug') ?? $this->stringValue($member, 'slug');
            if ($slug !== null) {
                $bySlug[$slug] = $member;
            }
        }

        return $bySlug;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function stateCount(array $artifact, string $key, string $state): int
    {
        $count = 0;
        foreach ($this->artifactRows($artifact, ['items', 'rows']) as $row) {
            if (($this->stringValue($row, $key) ?? '') === $state) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array{eligible: bool, reasons: list<string>, evidence: array<string, mixed>}  $gate
     * @return array<string, mixed>
     */
    private function eligibleRow(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow, array $gate): array
    {
        return [
            'slug' => $row->canonicalSlug,
            'locales' => $policyRow->locales,
            'score' => $row->score,
            'rank' => $row->rank,
            'rollout_candidate_eligible' => true,
            'runtime_state_evidence' => $gate['evidence'],
            'policy_classification' => $policyRow->classification,
            'deferred_until_candidate' => $policyRow->deferredUntilCandidateReasons,
            'expected_not_ready' => $policyRow->expectedNotReadyReasons,
        ];
    }

    /**
     * @param  array{eligible: bool, reasons: list<string>, evidence: array<string, mixed>}  $gate
     * @return array<string, mixed>
     */
    private function excludedRow(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow, array $gate): array
    {
        return [
            'slug' => $row->canonicalSlug,
            'locales' => $policyRow->locales,
            'score' => $row->score,
            'rank' => $row->rank,
            'candidate_status' => $row->candidateStatus,
            'policy_classification' => $policyRow->classification,
            'exclusion_reasons' => $gate['reasons'],
            'runtime_state_evidence' => $gate['evidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function blocker(string $reason, string $message, array $evidence = []): array
    {
        return [
            'reason' => $reason,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedPayload(string $reason, string $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'pool_pass' => false,
            'read_only' => true,
            'writes_database' => false,
            'target' => 80,
            'locales' => ['en', 'zh'],
            'base_candidate_count' => 0,
            'eligible_count' => 0,
            'selected_count' => 0,
            'excluded_count' => 0,
            'exclusions_by_reason' => [],
            'source_artifacts' => null,
            'runtime_candidate_gate' => [
                'required' => true,
                'expected_runtime_state' => Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE,
                'eligible_count' => 0,
                'excluded_count' => 0,
                'exclusions_by_reason' => [],
                'selected_slugs' => [],
                'eligible_rows' => [],
                'excluded_rows' => [],
            ],
            'selection' => [
                'strategy' => 'runtime_published_candidate_pool_ranked',
                'slugs' => [],
                'rows' => [],
            ],
            'recovery_plan' => [
                'required' => true,
                'needed_additional_count' => 80,
                'reason' => 'command blocked before candidate pool planning completed',
                'buckets' => [],
                'approval_gated_apply_ready' => false,
            ],
            'blockers' => [$this->blocker($reason, $message)],
            'rollout' => [
                'manifest_generation_allowed' => false,
                'dry_run_allowed' => false,
                'apply_allowed' => false,
                'reason' => 'runtime candidate pool planning only; rollout apply requires separate approval',
            ],
            'next_required_action' => 'FIX_RUNTIME_CANDIDATE_POOL',
        ];
    }

    private function reasonKey(string $message): string
    {
        $value = strtolower(trim($message));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: 'command_failed';

        return trim($value, '_') ?: 'command_failed';
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed_to_encode_json_payload');

            return self::FAILURE;
        }

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            File::put($outputPath, $encoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            $this->line('pool_pass='.(($payload['pool_pass'] ?? false) ? 'true' : 'false'));
        }

        return $exitCode;
    }
}
