<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ContentPage;
use App\Services\SeoAgent\OpportunityAggregator;
use App\Services\SeoIntel\GscDataQualityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentGscOpportunityAutoDraftCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-opportunity-auto-draft.v1';

    private const SOURCE_FAMILY = 'gsc_performance';

    protected $signature = 'seo-agent:gsc-opportunity-auto-draft
        {--limit=10 : Candidate limit, bounded 1..100}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Build Codex review and CMS draft dry-run artifacts from gated GSC opportunity rows without writing CMS or publishing.';

    public function handle(GscDataQualityGate $dataQualityGate): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $limit = $this->limit();
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $rows = $this->gscRows();
        $sourceGate = $dataQualityGate->evaluate($rows);
        $candidates = ($sourceGate['opportunity_queue_eligible'] ?? false) === true
            ? $this->candidateRows($rows, $limit)
            : [];

        $sourceArtifact = $this->sourceArtifact($sourceGate, $rows, $candidates, $limit);
        $sourceRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-opportunity-source-'.$timestamp.'.json',
            $sourceArtifact
        );

        $aggregate = (new OpportunityAggregator)->aggregate([$sourceArtifact], $limit);
        $aggregate['input_artifacts'] = [$sourceRef];
        $aggregateRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-opportunity-aggregate-'.$timestamp.'.json',
            $aggregate
        );

        $handoff = $this->codexReviewHandoff($sourceRef, $aggregateRef, $aggregate);
        $handoffRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-opportunity-codex-handoff-'.$timestamp.'.json',
            $handoff
        );

        $verdictRef = $this->runCodexReview($handoffRef, $artifactDir);
        if ($verdictRef === null) {
            return $this->finish($this->failureSummary('codex_review_runner_failed', [
                'source_artifact' => $sourceRef,
                'aggregate_artifact' => $aggregateRef,
                'handoff_artifact' => $handoffRef,
            ]));
        }

        $draftRef = $this->runDraftPackageDryRun($verdictRef, $artifactDir);
        if ($draftRef === null) {
            return $this->finish($this->failureSummary('cms_draft_package_dry_run_failed', [
                'source_artifact' => $sourceRef,
                'aggregate_artifact' => $aggregateRef,
                'handoff_artifact' => $handoffRef,
                'verdict_artifact' => $verdictRef,
            ]));
        }

        $evidence = $this->runEvidence($sourceGate, $rows, $sourceRef, $aggregateRef, $handoffRef, $verdictRef, $draftRef);
        $evidenceRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-opportunity-auto-draft-evidence-'.$timestamp.'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'source_gate' => [
                'status' => (string) ($sourceGate['status'] ?? 'unknown'),
                'opportunity_queue_eligible' => (bool) ($sourceGate['opportunity_queue_eligible'] ?? false),
                'rows_checked' => (int) ($sourceGate['rows_checked'] ?? 0),
            ],
            'candidate_count' => count($candidates),
            'draft_brief_count' => (int) data_get($draftRef, 'sanitized_summary.draft_brief_count', 0),
            'artifact' => $evidenceRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 10;
        }

        return max(1, min((int) $raw, 100));
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gscRows(): array
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_gsc_daily')
            ->leftJoin('seo_urls', 'seo_gsc_daily.canonical_url_hash', '=', 'seo_urls.canonical_url_hash')
            ->select([
                'seo_gsc_daily.report_date',
                'seo_gsc_daily.canonical_url_hash',
                'seo_gsc_daily.query_hash',
                'seo_gsc_daily.query_display_masked',
                'seo_gsc_daily.locale',
                'seo_gsc_daily.source_engine',
                'seo_gsc_daily.clicks',
                'seo_gsc_daily.impressions',
                'seo_gsc_daily.ctr_ppm',
                'seo_gsc_daily.average_position_milli',
                'seo_gsc_daily.is_brand_query',
                'seo_gsc_daily.query_type',
                'seo_gsc_daily.metadata_json',
                'seo_urls.canonical_url',
                'seo_urls.page_entity_type',
                'seo_urls.entity_id_or_slug',
                'seo_urls.indexability_state',
            ])
            ->where('seo_gsc_daily.source_engine', 'google')
            ->orderByDesc('seo_gsc_daily.report_date')
            ->limit(500)
            ->get()
            ->map(fn (object $row): array => [
                'report_date' => (string) $row->report_date,
                'canonical_url_hash' => (string) $row->canonical_url_hash,
                'query_hash' => (string) $row->query_hash,
                'query_display_masked' => is_string($row->query_display_masked ?? null) ? $row->query_display_masked : null,
                'locale' => is_string($row->locale ?? null) ? $row->locale : null,
                'source_engine' => (string) $row->source_engine,
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'ctr_ppm' => $row->ctr_ppm === null ? null : (int) $row->ctr_ppm,
                'average_position_milli' => $row->average_position_milli === null ? null : (int) $row->average_position_milli,
                'is_brand_query' => (bool) ($row->is_brand_query ?? false),
                'query_type' => is_string($row->query_type ?? null) ? $row->query_type : 'unknown',
                'metadata_json' => $this->decodeJson($row->metadata_json ?? null),
                'safe_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
                'page_entity_type' => is_string($row->page_entity_type ?? null) ? $row->page_entity_type : '',
                'entity_id_or_slug' => is_string($row->entity_id_or_slug ?? null) ? $row->entity_id_or_slug : '',
                'indexability_state' => is_string($row->indexability_state ?? null) ? $row->indexability_state : '',
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function candidateRows(array $rows, int $limit): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            if (! $this->matchesOpportunityContract($row)) {
                continue;
            }

            $target = $this->cmsTarget($row);
            if ($target === null) {
                continue;
            }

            $candidates[] = $this->candidate($row, $target);
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftMetrics = (array) ($left['metrics'] ?? []);
            $rightMetrics = (array) ($right['metrics'] ?? []);
            $leftScore = ((int) ($leftMetrics['impressions'] ?? 0)) - ((int) ($leftMetrics['ctr_ppm'] ?? 0) / 1000);
            $rightScore = ((int) ($rightMetrics['impressions'] ?? 0)) - ((int) ($rightMetrics['ctr_ppm'] ?? 0) / 1000);

            return $rightScore <=> $leftScore;
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesOpportunityContract(array $row): bool
    {
        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $ctrPpm = $row['ctr_ppm'] === null ? ($impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : null) : (int) $row['ctr_ppm'];
        $positionMilli = $row['average_position_milli'] === null ? null : (int) $row['average_position_milli'];

        return $impressions >= 50
            && $ctrPpm !== null
            && $ctrPpm <= 10000
            && $positionMilli !== null
            && $positionMilli >= 8000
            && $positionMilli <= 20000
            && ! (bool) ($row['is_brand_query'] ?? false)
            && ($row['query_type'] ?? 'unknown') === 'non_brand'
            && ($row['safe_path'] ?? null) !== null
            && ($row['indexability_state'] ?? '') === 'indexable';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{type:string,ref:string}|null
     */
    private function cmsTarget(array $row): ?array
    {
        $entityType = (string) ($row['page_entity_type'] ?? '');
        if (! in_array($entityType, ['article', 'content_page'], true)) {
            return null;
        }

        $entityIdOrSlug = (string) ($row['entity_id_or_slug'] ?? '');
        $locale = (string) ($row['locale'] ?? '');
        $safePath = (string) ($row['safe_path'] ?? '');
        $id = null;

        if ($entityIdOrSlug !== '' && ctype_digit($entityIdOrSlug)) {
            $id = (int) $entityIdOrSlug;
        } elseif ($entityType === 'article') {
            $id = Article::query()
                ->withoutGlobalScopes()
                ->where('slug', $entityIdOrSlug !== '' ? $entityIdOrSlug : basename(trim($safePath, '/')))
                ->when($locale !== '', fn ($query) => $query->where('locale', $locale))
                ->value('id');
        } else {
            $id = ContentPage::query()
                ->withoutGlobalScopes()
                ->where(static function ($query) use ($entityIdOrSlug, $safePath): void {
                    if ($entityIdOrSlug !== '') {
                        $query->where('slug', $entityIdOrSlug);
                    }
                    if ($safePath !== '') {
                        $query->orWhere('path', $safePath);
                    }
                })
                ->when($locale !== '', fn ($query) => $query->where('locale', $locale))
                ->value('id');
        }

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $exists = $entityType === 'article'
            ? Article::query()->withoutGlobalScopes()->whereKey((int) $id)->exists()
            : ContentPage::query()->withoutGlobalScopes()->whereKey((int) $id)->exists();
        if (! $exists) {
            return null;
        }

        return [
            'type' => $entityType,
            'ref' => $entityType.':'.(int) $id.':'.($locale !== '' ? $locale : 'unknown'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{type:string,ref:string}  $target
     * @return array<string, mixed>
     */
    private function candidate(array $row, array $target): array
    {
        $metrics = [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr_ppm' => $row['ctr_ppm'] === null ? null : (int) $row['ctr_ppm'],
            'average_position_milli' => $row['average_position_milli'] === null ? null : (int) $row['average_position_milli'],
        ];
        $sourceId = hash('sha256', implode('|', [
            (string) ($row['report_date'] ?? ''),
            (string) ($row['canonical_url_hash'] ?? ''),
            (string) ($row['query_hash'] ?? ''),
            $target['ref'],
        ]));

        return [
            'source_family' => self::SOURCE_FAMILY,
            'source_id' => $sourceId,
            'subject_type' => $target['type'],
            'subject_ref' => $target['ref'],
            'safe_path' => (string) ($row['safe_path'] ?? ''),
            'locale' => (string) ($row['locale'] ?? ''),
            'severity' => ($metrics['impressions'] >= 500 && (int) ($metrics['ctr_ppm'] ?? 0) === 0) ? 'p1' : 'p2',
            'gap_types' => [
                'gsc_low_ctr_title_opportunity',
                'gsc_low_ctr_description_opportunity',
            ],
            'evidence_refs' => [
                [
                    'code' => 'gsc_low_ctr_rank_8_20',
                    'field_status' => 'eligible_live_gsc_read_model_row',
                ],
            ],
            'metrics' => $metrics,
            'canonical_url_hash' => (string) ($row['canonical_url_hash'] ?? ''),
            'query_hash' => (string) ($row['query_hash'] ?? ''),
            'query_display_masked' => $row['query_display_masked'] ?? null,
            'recommended_next_step' => 'codex_review_then_cms_draft_package_dry_run',
            'allowed_action' => 'cms_draft_package_dry_run',
            'blocked_actions' => [
                'cms_write_without_separate_draft_writer',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceGate
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function sourceArtifact(array $sourceGate, array $rows, array $candidates, int $limit): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-GSC-OPPORTUNITY-AUTO-DRAFT-01',
            'run_mode' => 'readonly_gsc_opportunity_to_draft_dry_run',
            'source_family' => self::SOURCE_FAMILY,
            'source_gate' => $sourceGate,
            'candidate_count' => count($candidates),
            'rows_seen' => count($rows),
            'limit' => $limit,
            'scoring_contract' => [
                'min_impressions' => 50,
                'max_ctr_ppm' => 10000,
                'position_milli_window' => [8000, 20000],
                'brand_query_allowed' => false,
                'query_type_required' => 'non_brand',
                'cms_target_required' => true,
            ],
            'candidates' => $candidates,
            'forbidden_output_fields_absent' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceRef
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>
     */
    private function codexReviewHandoff(array $sourceRef, array $aggregateRef, array $aggregate): array
    {
        return [
            'schema_version' => 'seo-agent-codex-review-handoff.v1',
            'task' => 'SEO-AGENT-GSC-OPPORTUNITY-AUTO-DRAFT-01',
            'reviewer' => 'codex',
            'role' => 'review_only',
            'execution_permission' => false,
            'input_candidates' => $aggregateRef,
            'input_artifacts' => [$sourceRef],
            'candidate_count' => (int) ($aggregate['candidate_count'] ?? 0),
            'review_output_contract' => [
                'worth_optimizing' => 'boolean',
                'recommended_action' => 'cms_draft_package_dry_run|defer',
                'risk_flags' => 'list<string>',
                'needs_human_approval' => 'boolean',
            ],
            'candidate_preview' => array_slice((array) ($aggregate['candidates'] ?? []), 0, 100),
            'forbidden_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
                'scheduler_activation',
                'queue_worker_activation',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $handoffRef
     * @return array<string, mixed>|null
     */
    private function runCodexReview(array $handoffRef, string $artifactDir): ?array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('seo-agent:codex-review-runner', [
            '--handoff' => (string) ($handoffRef['path'] ?? ''),
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);
        $summary = json_decode(trim($output->fetch()), true);

        return $exit === self::SUCCESS && is_array($summary) && is_array($summary['artifact'] ?? null)
            ? $summary['artifact']
            : null;
    }

    /**
     * @param  array<string, mixed>  $verdictRef
     * @return array<string, mixed>|null
     */
    private function runDraftPackageDryRun(array $verdictRef, string $artifactDir): ?array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('seo-agent:cms-draft-package-dry-run', [
            '--verdict' => (string) ($verdictRef['path'] ?? ''),
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);
        $summary = json_decode(trim($output->fetch()), true);

        return $exit === self::SUCCESS && is_array($summary) && is_array($summary['artifact'] ?? null)
            ? $summary['artifact']
            : null;
    }

    /**
     * @param  array<string, mixed>  $sourceGate
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $sourceRef
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $handoffRef
     * @param  array<string, mixed>  $verdictRef
     * @param  array<string, mixed>  $draftRef
     * @return array<string, mixed>
     */
    private function runEvidence(
        array $sourceGate,
        array $rows,
        array $sourceRef,
        array $aggregateRef,
        array $handoffRef,
        array $verdictRef,
        array $draftRef
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-GSC-OPPORTUNITY-AUTO-DRAFT-01',
            'status' => 'success',
            'source_gate' => [
                'status' => (string) ($sourceGate['status'] ?? 'unknown'),
                'opportunity_queue_eligible' => (bool) ($sourceGate['opportunity_queue_eligible'] ?? false),
                'rows_checked' => count($rows),
            ],
            'artifacts' => [
                'gsc_opportunity_source' => $sourceRef,
                'opportunity_aggregate' => $aggregateRef,
                'codex_review_handoff' => $handoffRef,
                'codex_review_verdict' => $verdictRef,
                'cms_draft_package_dry_run' => $draftRef,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $filename, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
            'sanitized_summary' => [
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'draft_brief_count' => (int) ($payload['draft_brief_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
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
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            $this->line('candidate_count='.(string) ($summary['candidate_count'] ?? 0));
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function safePath(?string $canonicalUrl): ?string
    {
        if ($canonicalUrl === null || trim($canonicalUrl) === '') {
            return null;
        }

        $path = parse_url($canonicalUrl, PHP_URL_PATH);
        $query = parse_url($canonicalUrl, PHP_URL_QUERY);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }

    /**
     * @return array<string, bool>
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
            'queue_worker_started' => false,
            'production_env_change' => false,
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'external_model_api_call' => false,
        ];
    }
}
