<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\Career2786ReadinessPolicyClassifier;
use App\Domain\Career\Audit\Career2786ReadinessPolicyRow;
use App\Domain\Career\Audit\CareerCanonical80CandidateSelectionRow;
use App\Domain\Career\Audit\CareerCanonical80CandidateSelector;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CareerPlanCanonical80CohortReadiness extends Command
{
    private const SCHEMA_VERSION = 'career_80_cohort_readiness.v1';

    protected $signature = 'career:plan-canonical-80-cohort-readiness
        {--audit= : Required Career canonical eligibility audit JSON artifact}
        {--target=80 : Target cohort size}
        {--json : Emit JSON output}
        {--output= : Optional output path for readiness JSON}
        {--include-sidecars : Include audit sidecars in output}
        {--strict : Fail on malformed or ambiguous audit rows}';

    protected $description = 'Plan the read-only Career 80 canonical cohort readiness from a full eligibility audit artifact.';

    public function handle(): int
    {
        try {
            $auditPath = $this->requiredOption('audit');
            $target = $this->positiveIntOption('target', 80);
            [$audit, $report] = $this->readAudit($auditPath);

            $payload = $this->buildPlan($auditPath, $audit, $report, $target);

            return $this->finish($payload, $payload['readiness_pass'] === true ? self::SUCCESS : self::FAILURE);
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
     * @return array{0: array<string, mixed>, 1: CareerCanonicalEligibilityReport}
     */
    private function readAudit(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('audit_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('audit_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('audit_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('audit_artifact_shape_invalid');
        }

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
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function buildPlan(string $auditPath, array $audit, CareerCanonicalEligibilityReport $report, int $target): array
    {
        $policy = (new Career2786ReadinessPolicyClassifier)->classify($report, $target);
        $policyRowsBySlug = [];
        foreach ($policy->rows as $row) {
            $policyRowsBySlug[$row->canonicalSlug] = $row;
        }

        $selection = (new CareerCanonical80CandidateSelector)->select($report, $target);
        $selectable = [];
        foreach ($selection->rows as $row) {
            $policyRow = $policyRowsBySlug[$row->canonicalSlug] ?? null;
            if (! $policyRow instanceof Career2786ReadinessPolicyRow) {
                continue;
            }

            if (! $this->isSelectable($row, $policyRow)) {
                continue;
            }

            $selectable[] = [$row, $policyRow];
        }

        $selected = array_slice($selectable, 0, $target);
        $blockers = $this->blockers($report, $target, count($selectable), count($selected));
        $readinessPass = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $readinessPass ? 'pass' : 'blocked',
            'readiness_pass' => $readinessPass,
            'target' => $target,
            'candidate_count' => count($selectable),
            'selected_count' => count($selected),
            'read_only' => true,
            'writes_database' => false,
            'source_audit' => [
                'path' => $auditPath,
                'status' => $report->status,
                'scope' => $report->scope,
                'expected_occupations' => $report->expectedOccupations,
                'audited_occupations' => $report->auditedOccupations,
                'eligible_count' => $report->eligibleCount,
                'blocked_count' => $report->blockedCount,
            ],
            'policy_summary' => $policy->summary(),
            'selection' => [
                'strategy' => 'policy_near_eligible_ranked',
                'slugs' => array_map(
                    static fn (array $pair): string => $pair[0]->canonicalSlug,
                    $selected
                ),
                'rows' => array_map(
                    fn (array $pair): array => $this->selectionRow($pair[0], $pair[1]),
                    $selected
                ),
            ],
            'blockers' => $blockers,
            'sidecars' => (bool) $this->option('include-sidecars') && isset($audit['sidecars']) && is_array($audit['sidecars'])
                ? $audit['sidecars']
                : [],
            'rollout' => [
                'manifest_generation_allowed' => $readinessPass,
                'apply_allowed' => false,
                'reason' => 'readiness only; apply requires separate approval',
            ],
            'next_required_action' => $readinessPass ? '80_MANIFEST_TRAIN_READ_ONLY' : 'FIX_BLOCKERS',
        ];
    }

    private function isSelectable(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow): bool
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
     * @return list<array<string, mixed>>
     */
    private function blockers(CareerCanonicalEligibilityReport $report, int $target, int $candidateCount, int $selectedCount): array
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
        if ($candidateCount < $target || $selectedCount < $target) {
            $blockers[] = $this->blocker('insufficient_near_eligible_candidates', 'Fewer than the target near-eligible slugs can be evaluated.', [
                'target' => $target,
                'candidate_count' => $candidateCount,
                'selected_count' => $selectedCount,
            ]);
        }

        return $blockers;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionRow(CareerCanonical80CandidateSelectionRow $row, Career2786ReadinessPolicyRow $policyRow): array
    {
        return [
            'slug' => $row->canonicalSlug,
            'locales' => $policyRow->locales,
            'score' => $row->score,
            'rank' => $row->rank,
            'reasons' => $policyRow->reasons,
            'deferred_until_candidate' => $policyRow->deferredUntilCandidateReasons,
            'expected_not_ready' => $policyRow->expectedNotReadyReasons,
            'remediation_required' => $policyRow->remediationRequiredReasons,
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
            'readiness_pass' => false,
            'target' => 80,
            'candidate_count' => 0,
            'selected_count' => 0,
            'read_only' => true,
            'writes_database' => false,
            'source_audit' => null,
            'policy_summary' => [],
            'selection' => [
                'strategy' => 'policy_near_eligible_ranked',
                'slugs' => [],
                'rows' => [],
            ],
            'blockers' => [$this->blocker($reason, $message)],
            'sidecars' => [],
            'rollout' => [
                'manifest_generation_allowed' => false,
                'apply_allowed' => false,
                'reason' => 'readiness only; apply requires separate approval',
            ],
            'next_required_action' => 'FIX_BLOCKERS',
        ];
    }

    private function reasonKey(string $message): string
    {
        $value = strtolower(trim($message));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: 'command_failed';

        return trim($value, '_') ?: 'command_failed';
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
            $this->line('readiness_pass='.(($payload['readiness_pass'] ?? false) ? 'true' : 'false'));
        }

        return $exitCode;
    }
}
