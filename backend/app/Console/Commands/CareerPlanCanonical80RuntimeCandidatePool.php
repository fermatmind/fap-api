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

    protected $signature = 'career:plan-canonical-80-runtime-candidate-pool
        {--audit= : Required post-apply Career canonical eligibility audit JSON artifact}
        {--projection= : Required runtime projection JSON artifact}
        {--truth= : Required runtime truth JSON artifact}
        {--ledger= : Required full release ledger JSON artifact}
        {--target=80 : Target runtime candidate pool size}
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
            $locales = $this->localesOption();

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
        $selection = (new CareerCanonical80CandidateSelector)->select($report, $target);

        $eligible = [];
        $excluded = [];
        $exclusionsByReason = [];
        $baseCandidateCount = 0;
        $auditGate = new Career80RolloutCandidateGate;

        foreach ($selection->rows as $row) {
            $policyRow = $policyRowsBySlug[$row->canonicalSlug] ?? null;
            if (! $policyRow instanceof Career2786ReadinessPolicyRow) {
                continue;
            }

            if (! $this->isBaseCandidate($row, $policyRow)) {
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
                'strategy' => 'runtime_published_candidate_pool_ranked',
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
            'next_required_action' => $poolPass ? '80_READINESS_RERUN_WITH_RUNTIME_POOL' : 'FIX_RUNTIME_CANDIDATE_POOL',
        ];
    }

    private function isBaseCandidate(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow): bool
    {
        if (! in_array($row->candidateStatus, [
            CareerCanonical80CandidateSelectionRow::STATUS_READY,
            CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE,
        ], true)) {
            return false;
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
    ): array {
        $reasons = [];
        $evidence = [
            'slug' => $slug,
            'required_locales' => $locales,
            'ledger_member_exists' => $ledgerMember !== null,
            'ledger_release_cohort' => $ledgerMember['release_cohort'] ?? null,
            'ledger_public_index_state' => $ledgerMember['public_index_state'] ?? null,
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

        foreach ($auditGate['reasons'] as $reason) {
            $reasons[] = $reason;
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
