<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentGscRemainingCandidateBatchPlanCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-remaining-candidate-batch-plan.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:gsc-remaining-candidate-batch-plan
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--completed-target=article:41:en : Completed target to exclude from the next write batch}
        {--artifact-dir= : Directory for sanitized batch planning evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only planner for remaining GSC cohort article draft candidates; recommends the next bounded CMS draft write canary batch.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $completedTarget = trim((string) $this->option('completed-target'));

        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }
        if ($completedTarget === '' || str_contains($completedTarget, "\0")) {
            return $this->finish($this->failureSummary('completed_target_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($packagePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($raw, true);
        if (! is_array($package)) {
            return $this->finish($this->failureSummary('package_json_invalid'));
        }

        $packageIssue = $this->validatePackage($package);
        if ($packageIssue !== null) {
            return $this->finish($this->failureSummary($packageIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $evidence = $this->evidence($packagePath, $package, $packageSha, $completedTarget);
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-remaining-candidate-batch-plan-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) ($evidence['ok'] ?? false),
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'package_sha256' => $packageSha,
            'remaining_candidate_count' => (int) ($evidence['remaining_candidate_count'] ?? 0),
            'recommended_limit' => (int) data_get($evidence, 'recommendation.limit', 0),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function validatePackage(array $package): ?string
    {
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return 'package_schema_invalid';
        }
        if ((bool) ($package['dry_run'] ?? false) !== true) {
            return 'package_not_dry_run';
        }
        if ((bool) ($package['cms_write_allowed'] ?? true) !== false || (bool) ($package['execution_permission'] ?? true) !== false) {
            return 'package_execution_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function evidence(string $packagePath, array $package, string $packageSha, string $completedTarget): array
    {
        $items = $this->packageItems($package);
        $articleCandidates = array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) ($item['target_model'] ?? $item['subject_type'] ?? '') === 'article'
                && (string) ($item['source_family'] ?? '') === 'gsc_cohort_artifact'
        ));
        $remaining = array_values(array_filter(
            $articleCandidates,
            static fn (array $item): bool => (string) ($item['subject_ref'] ?? '') !== $completedTarget
        ));
        $ranked = $this->rankedCandidates($remaining);
        $issues = [];
        $subjectRefs = array_map(static fn (array $item): string => (string) ($item['subject_ref'] ?? ''), $articleCandidates);
        if (! in_array($completedTarget, $subjectRefs, true)) {
            $issues[] = 'completed_target_not_found_in_package';
        }
        if (count($remaining) !== 6) {
            $issues[] = 'remaining_candidate_count_not_expected_6';
        }

        $recommendation = $this->recommendation($ranked, $packageSha);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $issues === [] ? 'success' : 'review_required',
            'issues' => $issues,
            'package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'package_sha256' => $packageSha,
            'completed_target_excluded' => $completedTarget,
            'source_candidate_count' => count($articleCandidates),
            'remaining_candidate_count' => count($remaining),
            'remaining_candidates' => $ranked,
            'recommendation' => $recommendation,
            'approval_gate' => [
                'requires_separate_production_cms_draft_write_approval' => true,
                'exact_future_approval_phrase' => (string) ($recommendation['future_approval_phrase'] ?? ''),
                'no_publish_covered_by_this_phrase' => true,
                'no_search_or_indexing_covered_by_this_phrase' => true,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function packageItems(array $package): array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function rankedCandidates(array $candidates): array
    {
        $ranked = array_map(fn (array $candidate): array => $this->candidatePlan($candidate), $candidates);
        usort($ranked, static function (array $left, array $right): int {
            return [
                (int) ($left['sort_keys']['priority_weight'] ?? 99),
                -1 * (int) ($left['sort_keys']['impressions'] ?? 0),
                (int) ($left['sort_keys']['risk_weight'] ?? 99),
                (string) ($left['subject_ref'] ?? ''),
            ] <=> [
                (int) ($right['sort_keys']['priority_weight'] ?? 99),
                -1 * (int) ($right['sort_keys']['impressions'] ?? 0),
                (int) ($right['sort_keys']['risk_weight'] ?? 99),
                (string) ($right['subject_ref'] ?? ''),
            ];
        });

        foreach ($ranked as $index => $candidate) {
            $ranked[$index]['rank'] = $index + 1;
        }

        return $ranked;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidatePlan(array $candidate): array
    {
        $locale = $this->locale($candidate);
        $riskFlags = $this->riskFlags($candidate, $locale);

        return [
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'safe_path' => (string) ($candidate['safe_path'] ?? ''),
            'locale' => $locale,
            'severity' => (string) ($candidate['severity'] ?? ''),
            'metrics' => [
                'impressions' => $this->metricInt($candidate, 'impressions'),
                'clicks' => $this->metricInt($candidate, 'clicks'),
                'ctr_ppm' => $this->metricInt($candidate, 'ctr_ppm'),
                'average_position' => $this->metricFloat($candidate, 'average_position'),
            ],
            'proposal_quality' => [
                'source' => (string) data_get($candidate, 'proposal_quality.source', ''),
                'locale_preserved' => (bool) data_get($candidate, 'proposal_quality.locale_preserved', false),
                'slug_generated_copy' => (bool) data_get($candidate, 'proposal_quality.slug_generated_copy', true),
                'needs_human_approval' => (bool) data_get($candidate, 'proposal_quality.needs_human_approval', true),
            ],
            'risk_flags' => $riskFlags,
            'review_cautions' => $this->reviewCautions($riskFlags, $locale),
            'ranking_rationale' => [
                'priority_first',
                'higher_gsc_impressions_next',
                'lower_locale_and_claim_surface_risk_next',
                'bounded_batch_before_remaining_candidates',
            ],
            'sort_keys' => [
                'priority_weight' => $this->priorityWeight((string) ($candidate['severity'] ?? '')),
                'impressions' => $this->metricInt($candidate, 'impressions'),
                'risk_weight' => count($riskFlags),
            ],
            'execution_permission' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function riskFlags(array $candidate, string $locale): array
    {
        $flags = [];
        if (in_array($locale, ['zh', 'zh-CN'], true)) {
            $flags[] = 'zh_tdk_faq_extra_review';
        }
        if ((bool) ($candidate['claim_gate_required'] ?? true)) {
            $flags[] = 'claim_gate_required';
        }
        if ((array) ($candidate['proposed_faq_items'] ?? []) !== [] || in_array('faq_items', (array) ($candidate['target_fields'] ?? []), true)) {
            $flags[] = 'faq_claim_surface';
        }
        if ((array) ($candidate['proposed_internal_link_actions'] ?? []) !== []) {
            $flags[] = 'internal_link_review_required';
        }
        if ((bool) data_get($candidate, 'proposal_quality.slug_generated_copy', false)) {
            $flags[] = 'slug_generated_copy_risk';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  list<string>  $riskFlags
     * @return list<string>
     */
    private function reviewCautions(array $riskFlags, string $locale): array
    {
        $cautions = [];
        if (in_array($locale, ['zh', 'zh-CN'], true)) {
            $cautions[] = 'extra_chinese_tdk_and_faq_review_required';
        }
        if (in_array('faq_claim_surface', $riskFlags, true)) {
            $cautions[] = 'faq_items_require_claim_risk_review_before_write';
        }
        if (in_array('internal_link_review_required', $riskFlags, true)) {
            $cautions[] = 'internal_link_actions_require_safe_path_review';
        }

        return $cautions;
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return array<string, mixed>
     */
    private function recommendation(array $ranked, string $packageSha): array
    {
        $topThree = array_slice($ranked, 0, 3);
        $hasHigherReviewSurface = count(array_filter(
            $topThree,
            static fn (array $candidate): bool => in_array('zh_tdk_faq_extra_review', (array) ($candidate['risk_flags'] ?? []), true)
                || in_array('faq_claim_surface', (array) ($candidate['risk_flags'] ?? []), true)
        )) > 0;
        $limit = max(0, min($hasHigherReviewSurface ? 2 : 3, count($ranked)));

        return [
            'limit' => $limit,
            'recommended_targets' => array_map(
                static fn (array $candidate): string => (string) ($candidate['subject_ref'] ?? ''),
                array_slice($ranked, 0, $limit)
            ),
            'reason' => 'Bound the next CMS draft write canary before touching all remaining GSC cohort candidates.',
            'future_approval_phrase' => 'I explicitly approve production CMS draft write canary for next GSC cohort article batch limit='.$limit.' using dry-run artifact sha256 '.$packageSha.'; no publish, no queue, no search, no indexing, no scheduler.',
            'requires_separate_approval' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function locale(array $candidate): string
    {
        $subjectRef = (string) ($candidate['subject_ref'] ?? '');
        $parts = explode(':', $subjectRef);
        if (($parts[2] ?? '') !== '') {
            return (string) $parts[2];
        }

        $path = (string) ($candidate['safe_path'] ?? '');
        if (str_starts_with($path, '/zh/')) {
            return 'zh-CN';
        }
        if (str_starts_with($path, '/en/')) {
            return 'en';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function metricInt(array $candidate, string $key): int
    {
        $value = data_get($candidate, 'metrics.'.$key, $candidate[$key] ?? 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function metricFloat(array $candidate, string $key): ?float
    {
        $value = data_get($candidate, 'metrics.'.$key, $candidate[$key] ?? null);

        return is_numeric($value) ? (float) $value : null;
    }

    private function priorityWeight(string $severity): int
    {
        return match (strtolower($severity)) {
            'p0' => 0,
            'p1' => 1,
            'p2' => 2,
            'p3' => 3,
            default => 9,
        };
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/gsc-remaining-candidate-batch-plan');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $payload): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($payload, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'schema_version' => $schemaVersion,
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $dir, string $filename, array $payload): array
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode remaining candidate batch plan artifact.');
        }
        file_put_contents($path, $encoded."\n");

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'external_model_api_call' => false,
            'live_gsc_api_call' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf('%s %s', $summary['schema_version'] ?? self::SCHEMA_VERSION, $summary['status'] ?? 'unknown'));
        }

        return (bool) ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
