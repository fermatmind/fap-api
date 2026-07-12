<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PersonalityEnneagramSearchQueueInspect extends Command
{
    private const ARTIFACT_PATTERN = '/^[0-9a-f]{64}$/';

    private const ENQUEUE_SCHEMA_VERSION = 'enneagram-search-queue-enqueue.v1';

    private const EXPECTED_TARGET_COUNT = 116;

    private const PAGE_TYPE = 'personality_public_content_asset';

    private const SCHEMA_VERSION = 'enneagram-search-queue-inspect.v1';

    /** @var array<string, int> */
    private const EXPECTED_ENTITY_COUNTS = [
        PersonalityPublicContentAsset::ENTITY_HUB => 2,
        PersonalityPublicContentAsset::ENTITY_CENTER => 6,
        PersonalityPublicContentAsset::ENTITY_CORE_TYPE => 18,
        PersonalityPublicContentAsset::ENTITY_WING => 36,
        PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE => 54,
    ];

    protected $signature = 'personality:enneagram-search-queue-inspect
        {--dry-run : Required. Refuses every mode other than read-only inspection.}
        {--write : Artifact-bound exact-116 Queue enqueue; never submits.}
        {--artifact-sha256= : SHA256 of the separately approved URL Truth artifact.}
        {--confirm-artifact-sha256= : Required exact artifact confirmation for write mode.}
        {--operator-approved= : Required artifact-bound operator token for write mode.}
        {--json : Emit a safe machine-readable report.}';

    protected $description = 'Read-only inspect of the exact 116 published bilingual Enneagram URL Truth and IndexNow Queue plan; never writes or submits.';

    /** @var list<string> */
    protected $aliases = ['personality:enneagram-search-queue-enqueue'];

    public function handle(SearchChannelQueuePlanner $planner, SearchChannelQueueWriteService $writer): int
    {
        if (trim((string) $this->option('artifact-sha256')) !== '' || (bool) $this->option('write')) {
            return $this->handleEnqueue($planner, $writer);
        }

        if (! (bool) $this->option('dry-run')) {
            return $this->finish($this->payload('NO_GO_SAFETY_VIOLATION', [], ['dry_run_required']));
        }

        $assets = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->orderBy('locale')
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();

        $contractIssues = $this->assetSetIssues($assets->all());
        $rows = [];
        $reasonCounts = [];
        $eligible = 0;
        $blocked = 0;
        $planned = 0;
        $activeDuplicates = 0;
        $staleSubmitted = 0;

        foreach ($assets as $asset) {
            $path = SearchChannelQueueEligibilityEvaluator::normalizePublicPath(
                (string) data_get($asset->canonical_json, 'path', '')
            );
            $assetIssues = $this->assetIssues($asset, $path);
            $plan = $path === null
                ? $this->emptyPlan()
                : $planner->plan('indexnow', null, 1, $this->canonicalUrl($path));

            $candidateCount = (int) ($plan['candidate_count'] ?? 0);
            $eligibleCount = (int) ($plan['eligible_count'] ?? 0);
            $blockedCount = (int) ($plan['blocked_count'] ?? 0);
            $plannedCount = (int) ($plan['planned_queue_count'] ?? 0);
            $duplicate = (bool) ($plan['duplicate_detected'] ?? false);
            $staleCount = (int) ($plan['stale_submitted_queue_item_count'] ?? 0);
            $rowReasons = is_array($plan['reason_code_breakdown'] ?? null) ? $plan['reason_code_breakdown'] : [];

            foreach ($assetIssues as $issue) {
                $rowReasons[$issue] = ($rowReasons[$issue] ?? 0) + 1;
            }
            foreach ($rowReasons as $reason => $count) {
                $reasonCounts[(string) $reason] = ($reasonCounts[(string) $reason] ?? 0) + (int) $count;
            }

            $eligible += $eligibleCount;
            $blocked += $blockedCount + ($assetIssues === [] ? 0 : 1);
            $planned += $plannedCount;
            $activeDuplicates += $duplicate ? 1 : 0;
            $staleSubmitted += $staleCount;

            $rows[] = [
                'canonical_url_hash' => hash('sha256', $this->canonicalUrl($path ?? '/invalid')),
                'canonical_path' => $path,
                'locale' => (string) $asset->locale,
                'entity_type' => (string) $asset->entity_type,
                'asset_gate_ok' => $assetIssues === [],
                'candidate_count' => $candidateCount,
                'eligible_count' => $eligibleCount,
                'blocked_count' => $blockedCount,
                'planned_queue_count' => $plannedCount,
                'active_duplicate' => $duplicate,
                'stale_submitted_count' => $staleCount,
                'reason_code_counts' => $rowReasons,
            ];
        }

        ksort($reasonCounts);
        $decision = $contractIssues === [] && $blocked === 0 && $planned === 116 && $activeDuplicates === 0
            ? 'GO_FOR_SEPARATE_SEARCH_QUEUE_AUTHORIZATION'
            : 'NO_GO_SEARCH_RELEASE';

        return $this->finish($this->payload($decision, $rows, $contractIssues, [
            'candidate_count' => array_sum(array_column($rows, 'candidate_count')),
            'eligible_count' => $eligible,
            'blocked_count' => $blocked,
            'planned_queue_count' => $planned,
            'active_duplicate_count' => $activeDuplicates,
            'stale_submitted_count' => $staleSubmitted,
            'reason_code_counts' => $reasonCounts,
        ]));
    }

    private function handleEnqueue(SearchChannelQueuePlanner $planner, SearchChannelQueueWriteService $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        $artifactSha = strtolower(trim((string) $this->option('artifact-sha256')));
        $issues = $this->enqueueModeIssues($dryRun, $write, $artifactSha);
        if ($issues !== []) {
            return $this->finishEnqueue($this->enqueuePayload('blocked', $dryRun, $write, $artifactSha, [], $issues));
        }

        $plan = $planner->plan('indexnow', self::PAGE_TYPE, self::EXPECTED_TARGET_COUNT);
        $issues = $this->enqueuePlanIssues($plan);
        $idempotentReplay = $this->isExactIdempotentReplay($plan);
        if ($issues !== [] && ! $idempotentReplay) {
            return $this->finishEnqueue($this->enqueuePayload('blocked', $dryRun, $write, $artifactSha, $plan, $issues));
        }

        if ($dryRun) {
            $summary = $idempotentReplay ? [
                'written_item_count' => 0,
                'created_batch_count' => 0,
                'active_queue_item_count' => self::EXPECTED_TARGET_COUNT,
            ] : [];

            return $this->finishEnqueue($this->enqueuePayload(
                $idempotentReplay ? 'already_enqueued' : 'dry_run_ready',
                true,
                false,
                $artifactSha,
                $plan,
                [],
                $summary,
            ));
        }

        if ($idempotentReplay) {
            return $this->finishEnqueue($this->enqueuePayload('already_enqueued', false, true, $artifactSha, $plan, [], [
                'written_item_count' => 0,
                'created_batch_count' => 0,
                'active_queue_item_count' => self::EXPECTED_TARGET_COUNT,
            ]));
        }

        try {
            $writeSummary = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
                ->transaction(function () use ($writer, $plan): array {
                    $result = $writer->write($plan['planned_items']);
                    $summary = [
                        'written_item_count' => (int) ($result['written_items'] ?? 0),
                        'created_batch_count' => count($result['batch_ids'] ?? []),
                        'active_queue_item_count' => count(array_unique($result['queue_item_ids'] ?? [])),
                    ];
                    if ($summary !== [
                        'written_item_count' => self::EXPECTED_TARGET_COUNT,
                        'created_batch_count' => 1,
                        'active_queue_item_count' => self::EXPECTED_TARGET_COUNT,
                    ]) {
                        throw new \RuntimeException('queue_write_count_mismatch');
                    }

                    return $summary;
                });
        } catch (\Throwable) {
            return $this->finishEnqueue($this->enqueuePayload('write_readback_failed', false, true, $artifactSha, $plan, [
                'queue_write_count_mismatch',
            ]));
        }

        return $this->finishEnqueue($this->enqueuePayload('enqueued', false, true, $artifactSha, $plan, [], $writeSummary));
    }

    /** @return list<string> */
    private function enqueueModeIssues(bool $dryRun, bool $write, string $artifactSha): array
    {
        $issues = [];
        if ($dryRun === $write) {
            $issues[] = 'exactly_one_mode_required';
        }
        if (! preg_match(self::ARTIFACT_PATTERN, $artifactSha)) {
            $issues[] = 'artifact_sha256_invalid';
        }
        if ($write) {
            if (! hash_equals($artifactSha, strtolower(trim((string) $this->option('confirm-artifact-sha256'))))) {
                $issues[] = 'artifact_sha256_confirmation_mismatch';
            }
            if (! hash_equals('ENNEAGRAM-SEARCH-QUEUE-ENQUEUE-01:'.$artifactSha, trim((string) $this->option('operator-approved')))) {
                $issues[] = 'operator_approval_mismatch';
            }
        }

        return $issues;
    }

    /** @param array<string, mixed> $plan @return list<string> */
    private function enqueuePlanIssues(array $plan): array
    {
        $issues = [];
        $checks = [
            'url_truth_source_unavailable' => ($plan['source_unavailable_reason'] ?? null) !== null,
            'candidate_count_mismatch' => (int) ($plan['candidate_count'] ?? 0) !== self::EXPECTED_TARGET_COUNT,
            'eligible_count_mismatch' => (int) ($plan['eligible_count'] ?? 0) !== self::EXPECTED_TARGET_COUNT,
            'stale_submitted_queue_item_present' => (int) ($plan['stale_submitted_queue_item_count'] ?? 0) !== 0,
            'page_type_count_mismatch' => ($plan['page_type_breakdown'] ?? []) !== [self::PAGE_TYPE => self::EXPECTED_TARGET_COUNT],
            'channel_selection_mismatch' => ($plan['selected_channels'] ?? []) !== ['indexnow'],
            'blocked_candidate_present' => (int) ($plan['blocked_count'] ?? 0) !== 0,
            'planned_queue_count_mismatch' => (int) ($plan['planned_queue_count'] ?? 0) !== self::EXPECTED_TARGET_COUNT,
            'active_duplicate_present' => (bool) ($plan['duplicate_detected'] ?? false),
        ];
        foreach ($checks as $issue => $failed) {
            if ($failed) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /** @param array<string, mixed> $plan */
    private function isExactIdempotentReplay(array $plan): bool
    {
        return (int) ($plan['candidate_count'] ?? 0) === self::EXPECTED_TARGET_COUNT
            && (int) ($plan['eligible_count'] ?? 0) === self::EXPECTED_TARGET_COUNT
            && (int) ($plan['blocked_count'] ?? 0) === self::EXPECTED_TARGET_COUNT
            && (int) ($plan['planned_queue_count'] ?? 0) === 0
            && (int) ($plan['stale_submitted_queue_item_count'] ?? 0) === 0
            && (bool) ($plan['duplicate_detected'] ?? false)
            && ($plan['reason_code_breakdown'] ?? []) === ['existing_active_queue_item' => self::EXPECTED_TARGET_COUNT]
            && ($plan['page_type_breakdown'] ?? []) === [self::PAGE_TYPE => self::EXPECTED_TARGET_COUNT];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $issues
     * @param  array<string, int>  $writeSummary
     * @return array<string, mixed>
     */
    private function enqueuePayload(string $status, bool $dryRun, bool $write, string $artifactSha, array $plan, array $issues = [], array $writeSummary = []): array
    {
        return [
            'schema_version' => self::ENQUEUE_SCHEMA_VERSION,
            'status' => $status,
            'ok' => in_array($status, ['dry_run_ready', 'enqueued', 'already_enqueued'], true),
            'dry_run' => $dryRun,
            'write_requested' => $write,
            'artifact_sha256' => preg_match(self::ARTIFACT_PATTERN, $artifactSha) ? $artifactSha : null,
            'channel' => 'indexnow',
            'page_entity_type' => self::PAGE_TYPE,
            'target_count' => self::EXPECTED_TARGET_COUNT,
            'summary' => [
                'candidate_count' => (int) ($plan['candidate_count'] ?? 0),
                'eligible_count' => (int) ($plan['eligible_count'] ?? 0),
                'blocked_count' => (int) ($plan['blocked_count'] ?? 0),
                'planned_queue_count' => (int) ($plan['planned_queue_count'] ?? 0),
                'stale_submitted_count' => (int) ($plan['stale_submitted_queue_item_count'] ?? 0),
                'reason_code_counts' => $plan['reason_code_breakdown'] ?? [],
                ...$writeSummary,
            ],
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => [
                'queue_approval' => false,
                'search_submit' => false,
                'external_search_api_call' => false,
                'cms_or_eligibility_write' => false,
                'sitemap_or_llms_cache_mutation' => false,
                'deploy' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function finishEnqueue(array $payload): int
    {
        $this->line((bool) $this->option('json')
            ? (string) json_encode($payload, JSON_UNESCAPED_SLASHES)
            : 'status='.$payload['status']);

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<PersonalityPublicContentAsset> $assets */
    private function assetSetIssues(array $assets): array
    {
        $issues = [];
        if (count($assets) !== 116) {
            $issues[] = 'enneagram_asset_count_mismatch';
        }

        $locales = collect($assets)->countBy('locale')->sortKeys()->all();
        if ($locales !== ['en' => 58, 'zh-CN' => 58]) {
            $issues[] = 'enneagram_locale_count_mismatch';
        }

        $entityCounts = collect($assets)->countBy('entity_type')->sortKeys()->all();
        $expectedEntityCounts = self::EXPECTED_ENTITY_COUNTS;
        ksort($expectedEntityCounts);
        if ($entityCounts !== $expectedEntityCounts) {
            $issues[] = 'enneagram_entity_count_mismatch';
        }

        $localeEntityCounts = collect($assets)
            ->groupBy(static fn (PersonalityPublicContentAsset $asset): string => $asset->locale.'|'.$asset->entity_type)
            ->map(static fn ($group): int => $group->count())
            ->sortKeys()
            ->all();
        $expectedLocaleEntityCounts = [];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (self::EXPECTED_ENTITY_COUNTS as $entityType => $count) {
                $expectedLocaleEntityCounts[$locale.'|'.$entityType] = intdiv($count, 2);
            }
        }
        ksort($expectedLocaleEntityCounts);
        if ($localeEntityCounts !== $expectedLocaleEntityCounts) {
            $issues[] = 'enneagram_locale_entity_count_mismatch';
        }

        $uniqueCanonicalPaths = collect($assets)
            ->map(static fn (PersonalityPublicContentAsset $asset): ?string => SearchChannelQueueEligibilityEvaluator::normalizePublicPath(
                (string) data_get($asset->canonical_json, 'path', '')
            ))
            ->filter()
            ->unique()
            ->count();
        if ($uniqueCanonicalPaths !== 116) {
            $issues[] = 'enneagram_canonical_path_set_mismatch';
        }

        return $issues;
    }

    /** @return list<string> */
    private function assetIssues(PersonalityPublicContentAsset $asset, ?string $path): array
    {
        $issues = [];
        if ($path === null) {
            $issues[] = 'canonical_path_invalid_or_private';
        }
        if (! $asset->is_public || $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
            $issues[] = 'asset_not_published_public';
        }
        if ($asset->robots !== PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW || ! $asset->index_eligible || ! $asset->sitemap_eligible) {
            $issues[] = 'asset_not_index_sitemap_eligible';
        }
        if ($asset->llms_eligible) {
            $issues[] = 'asset_llms_release_not_held';
        }
        if (! preg_match('/^[0-9a-f]{64}$/', (string) $asset->source_hash)) {
            $issues[] = 'asset_source_hash_invalid';
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function emptyPlan(): array
    {
        return [
            'candidate_count' => 0,
            'eligible_count' => 0,
            'blocked_count' => 0,
            'planned_queue_count' => 0,
            'duplicate_detected' => false,
            'stale_submitted_queue_item_count' => 0,
            'reason_code_breakdown' => ['canonical_path_invalid_or_private' => 1],
        ];
    }

    private function canonicalUrl(string $path): string
    {
        return rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function payload(string $decision, array $rows, array $issues, array $summary = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'ok' => $decision === 'GO_FOR_SEPARATE_SEARCH_QUEUE_AUTHORIZATION',
            'dry_run' => true,
            'write' => false,
            'target_count' => count($rows),
            'summary' => $summary,
            'issues' => array_values(array_unique($issues)),
            'targets' => $rows,
            'negative_guarantees' => [
                'database_write' => false,
                'queue_write' => false,
                'queue_approve' => false,
                'queue_enqueue' => false,
                'search_submit' => false,
                'external_search_api_call' => false,
                'cms_write' => false,
                'llms_sitemap_cache_mutation' => false,
                'deploy' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('decision='.$payload['decision']);
            $this->line('target_count='.$payload['target_count']);
        }

        return $payload['decision'] === 'NO_GO_SAFETY_VIOLATION' ? self::FAILURE : self::SUCCESS;
    }
}
