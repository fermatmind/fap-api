<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentGscPostPublishFeedbackCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-post-publish-feedback.v1';

    private const SINGLE_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    private const AUTO_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-auto-canary.v1';

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

    protected $signature = 'seo-agent:gsc-post-publish-feedback
        {--publish-evidence= : Path to SEO Agent CMS publish evidence JSON}
        {--window=7 : Feedback window in days; allowed values: 7, 14, 28}
        {--artifact-dir= : Directory for sanitized feedback evidence artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Read GSC read-model rows for published SEO Agent canaries and classify post-publish feedback without writes.';

    public function handle(): int
    {
        $evidencePath = $this->readablePath((string) $this->option('publish-evidence'));
        if ($evidencePath === null) {
            return $this->finish($this->failureSummary('publish_evidence_unreadable'));
        }

        $window = $this->window();
        if ($window === null) {
            return $this->finish($this->failureSummary('window_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($evidencePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $evidence = json_decode($raw, true);
        if (! is_array($evidence)) {
            return $this->finish($this->failureSummary('publish_evidence_json_invalid'));
        }

        $issue = $this->validatePublishEvidence($evidence);
        if ($issue !== null) {
            return $this->finish($this->failureSummary($issue));
        }

        $refs = $this->publishedRefs($evidence);
        if ($refs === []) {
            return $this->finish($this->failureSummary('published_content_page_refs_missing'));
        }

        $targetReports = [];
        foreach ($refs as $ref) {
            $targetReports[] = $this->targetFeedback($ref, $window);
        }

        $artifact = $this->writeEvidence($artifactDir, $evidencePath, $window, $targetReports);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'window_days' => $window,
            'target_count' => count($targetReports),
            'classification_counts' => $this->classificationCounts($targetReports),
            'artifact' => $artifact,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function window(): ?int
    {
        $window = filter_var($this->option('window'), FILTER_VALIDATE_INT);

        return in_array($window, [7, 14, 28], true) ? $window : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/gsc-post-publish-feedback');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function validatePublishEvidence(array $evidence): ?string
    {
        $schema = (string) ($evidence['schema_version'] ?? '');
        if (! in_array($schema, [self::SINGLE_PUBLISH_SCHEMA_VERSION, self::AUTO_PUBLISH_SCHEMA_VERSION], true)) {
            return 'publish_evidence_schema_invalid';
        }
        if (($evidence['status'] ?? null) !== 'success') {
            return 'publish_evidence_not_success';
        }

        $publishedCount = $schema === self::SINGLE_PUBLISH_SCHEMA_VERSION
            ? (int) ($evidence['published_count'] ?? 0)
            : (int) data_get($evidence, 'publish_summary.published_count', 0);
        $skippedCount = $schema === self::SINGLE_PUBLISH_SCHEMA_VERSION
            ? (int) ($evidence['rows_skipped_existing'] ?? 0)
            : (int) data_get($evidence, 'publish_summary.rows_skipped_existing', 0);

        if ($publishedCount + $skippedCount < 1) {
            return 'publish_evidence_has_no_published_or_existing_content_page';
        }

        if ((bool) data_get($evidence, 'negative_guarantees.search_channel_enqueue', true) !== false
            || (bool) data_get($evidence, 'negative_guarantees.search_channel_submit', true) !== false
            || (bool) data_get($evidence, 'negative_guarantees.indexing_request', true) !== false
            || (bool) data_get($evidence, 'negative_guarantees.scheduler_activation', true) !== false) {
            return 'publish_evidence_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array{subject_ref:string,safe_path:string}>
     */
    private function publishedRefs(array $evidence): array
    {
        $refs = [];
        $schema = (string) ($evidence['schema_version'] ?? '');
        $containers = $schema === self::AUTO_PUBLISH_SCHEMA_VERSION
            ? (array) ($evidence['publish_results'] ?? [])
            : [$evidence];

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }
            foreach ((array) ($container['affected_refs'] ?? []) as $affected) {
                if (! is_array($affected)) {
                    continue;
                }
                if (($affected['target_model'] ?? '') !== 'content_page') {
                    continue;
                }
                if (! in_array((string) ($affected['status'] ?? ''), ['published', 'skipped_existing'], true)) {
                    continue;
                }
                $subjectRef = trim((string) ($affected['subject_ref'] ?? ''));
                if (! $this->contentPageIdFromRef($subjectRef)) {
                    continue;
                }
                $refs[$subjectRef] = [
                    'subject_ref' => $subjectRef,
                    'safe_path' => $this->safePath((string) ($affected['safe_path'] ?? '')),
                ];
            }
        }

        return array_values($refs);
    }

    /**
     * @param  array{subject_ref:string,safe_path:string}  $ref
     * @return array<string, mixed>
     */
    private function targetFeedback(array $ref, int $window): array
    {
        $pageId = $this->contentPageIdFromRef($ref['subject_ref']);
        $page = $pageId ? ContentPage::query()->withoutGlobalScopes()->find($pageId) : null;
        if (! $page instanceof ContentPage) {
            return $this->targetBlocked($ref, 'content_page_not_found');
        }

        $publishedAt = $page->published_at instanceof Carbon ? $page->published_at->copy()->startOfDay() : null;
        if ($publishedAt === null) {
            return $this->targetBlocked($ref, 'published_at_missing');
        }

        $urlTruth = $this->urlTruthForPage($page, $ref['safe_path']);
        if ($urlTruth === null) {
            return $this->targetBlocked($ref, 'url_truth_missing');
        }

        $rows = $this->gscRows((string) $urlTruth['canonical_url_hash'], $publishedAt, $window);
        $gate = $this->rowGate($rows);
        $before = $this->aggregateRows($rows, $publishedAt->copy()->subDays($window), $publishedAt->copy()->subDay());
        $after = $this->aggregateRows($rows, $publishedAt, $publishedAt->copy()->addDays($window - 1));
        $classification = $this->classify($before, $after, $gate);

        return [
            'subject_ref' => $ref['subject_ref'],
            'target_model' => 'content_page',
            'safe_path' => $this->safePath($ref['safe_path']),
            'canonical_url_hash' => (string) $urlTruth['canonical_url_hash'],
            'published_date' => $publishedAt->toDateString(),
            'window_days' => $window,
            'source_gate' => $gate,
            'before' => $before,
            'after' => $after,
            'classification' => $classification,
        ];
    }

    /**
     * @param  array{subject_ref:string,safe_path:string}  $ref
     * @return array<string, mixed>
     */
    private function targetBlocked(array $ref, string $issue): array
    {
        return [
            'subject_ref' => $ref['subject_ref'],
            'target_model' => 'content_page',
            'safe_path' => $this->safePath($ref['safe_path']),
            'source_gate' => [
                'status' => 'blocked',
                'reasons' => [$issue],
                'rows_checked' => 0,
            ],
            'classification' => 'insufficient_data',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function urlTruthForPage(ContentPage $page, string $safePath): ?array
    {
        $query = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_urls')
            ->select(['canonical_url_hash', 'canonical_url', 'indexability_state', 'page_entity_type', 'entity_id_or_slug'])
            ->where('page_entity_type', 'content_page')
            ->where('entity_id_or_slug', (string) $page->id)
            ->where('indexability_state', 'indexable')
            ->orderByDesc('updated_at');

        $rows = $query->get()->map(fn (object $row): array => [
            'canonical_url_hash' => (string) $row->canonical_url_hash,
            'canonical_url' => (string) $row->canonical_url,
            'safe_path' => $this->safePathFromCanonicalUrl((string) $row->canonical_url),
        ])->all();

        foreach ($rows as $row) {
            if ($safePath !== '' && $row['safe_path'] === $safePath) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gscRows(string $canonicalUrlHash, Carbon $publishedAt, int $window): array
    {
        $start = $publishedAt->copy()->subDays($window)->toDateString();
        $end = $publishedAt->copy()->addDays($window - 1)->toDateString();

        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_gsc_daily')
            ->select([
                'report_date',
                'canonical_url_hash',
                'query_hash',
                'locale',
                'source_engine',
                'clicks',
                'impressions',
                'ctr_ppm',
                'average_position_milli',
                'is_brand_query',
                'query_type',
                'data_state',
                'metadata_json',
            ])
            ->where('canonical_url_hash', $canonicalUrlHash)
            ->where('source_engine', 'google')
            ->whereBetween('report_date', [$start, $end])
            ->orderBy('report_date')
            ->get()
            ->map(fn (object $row): array => [
                'report_date' => substr((string) $row->report_date, 0, 10),
                'canonical_url_hash' => (string) $row->canonical_url_hash,
                'query_hash' => (string) $row->query_hash,
                'locale' => is_string($row->locale ?? null) ? $row->locale : null,
                'source_engine' => (string) $row->source_engine,
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'ctr_ppm' => $row->ctr_ppm === null ? null : (int) $row->ctr_ppm,
                'average_position_milli' => $row->average_position_milli === null ? null : (int) $row->average_position_milli,
                'is_brand_query' => (bool) ($row->is_brand_query ?? false),
                'query_type' => is_string($row->query_type ?? null) ? $row->query_type : 'unknown',
                'data_state' => is_string($row->data_state ?? null) ? $row->data_state : 'unknown',
                'metadata_json' => $this->decodeJson($row->metadata_json ?? null),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowGate(array $rows): array
    {
        $reasons = [];
        $origins = [];
        foreach ($rows as $row) {
            $metadata = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
            $origin = (string) ($metadata['data_origin'] ?? $metadata['row_source'] ?? 'unknown');
            $origins[$origin] = true;
            if ($origin !== 'live_gsc_api') {
                $reasons[] = 'non_live_gsc_api_origin';
            }
            if (($row['source_engine'] ?? '') !== 'google') {
                $reasons[] = 'non_google_source_engine';
            }
            if (($row['data_state'] ?? '') !== 'final') {
                $reasons[] = 'non_final_data_state';
            }
            if (($row['canonical_url_hash'] ?? '') === '' || ($row['query_hash'] ?? '') === '') {
                $reasons[] = 'missing_required_hashes';
            }
        }

        if ($rows === []) {
            $reasons[] = 'no_gsc_rows_for_window';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'status' => $reasons === [] ? 'pass' : 'blocked',
            'reasons' => $reasons,
            'rows_checked' => count($rows),
            'data_origins' => array_keys($origins),
            'allowed_data_origin' => 'live_gsc_api',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregateRows(array $rows, Carbon $start, Carbon $end): array
    {
        $selected = array_values(array_filter($rows, static function (array $row) use ($start, $end): bool {
            $date = Carbon::parse((string) $row['report_date'])->startOfDay();

            return $date->betweenIncluded($start, $end);
        }));
        $clicks = array_sum(array_map(static fn (array $row): int => (int) ($row['clicks'] ?? 0), $selected));
        $impressions = array_sum(array_map(static fn (array $row): int => (int) ($row['impressions'] ?? 0), $selected));
        $weightedPosition = 0;
        foreach ($selected as $row) {
            $weightedPosition += ((int) ($row['average_position_milli'] ?? 0)) * max(1, (int) ($row['impressions'] ?? 0));
        }
        $positionDenominator = array_sum(array_map(static fn (array $row): int => max(1, (int) ($row['impressions'] ?? 0)), $selected));

        return [
            'date_start' => $start->toDateString(),
            'date_end' => $end->toDateString(),
            'row_count' => count($selected),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : null,
            'average_position_milli' => $positionDenominator > 0 ? (int) floor($weightedPosition / $positionDenominator) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $gate
     */
    private function classify(array $before, array $after, array $gate): string
    {
        if (($gate['status'] ?? '') !== 'pass'
            || (int) ($before['impressions'] ?? 0) < 10
            || (int) ($after['impressions'] ?? 0) < 10) {
            return 'insufficient_data';
        }

        $clickDelta = (int) ($after['clicks'] ?? 0) - (int) ($before['clicks'] ?? 0);
        $ctrDelta = (int) ($after['ctr_ppm'] ?? 0) - (int) ($before['ctr_ppm'] ?? 0);
        $positionBefore = (int) ($before['average_position_milli'] ?? 0);
        $positionAfter = (int) ($after['average_position_milli'] ?? 0);
        $positionImproved = $positionBefore > 0 && $positionAfter > 0 && $positionAfter <= $positionBefore - 500;
        $positionDeclined = $positionBefore > 0 && $positionAfter > 0 && $positionAfter >= $positionBefore + 500;

        if ($clickDelta > 0 || $ctrDelta >= 1000 || $positionImproved) {
            return 'improved';
        }
        if ($clickDelta < 0 && $ctrDelta <= -1000 && $positionDeclined) {
            return 'declined';
        }

        return 'flat';
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array<string, int>
     */
    private function classificationCounts(array $targets): array
    {
        $counts = [
            'improved' => 0,
            'flat' => 0,
            'declined' => 0,
            'insufficient_data' => 0,
        ];
        foreach ($targets as $target) {
            $classification = (string) ($target['classification'] ?? 'insufficient_data');
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array<string, mixed>
     */
    private function writeEvidence(string $artifactDir, string $publishEvidencePath, int $window, array $targets): array
    {
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-GSC-POST-PUBLISH-FEEDBACK-01',
            'status' => 'success',
            'run_mode' => 'readonly_post_publish_feedback',
            'command' => 'php artisan seo-agent:gsc-post-publish-feedback',
            'publish_evidence' => $this->artifactRef($publishEvidencePath, 'seo-agent-cms-publish-evidence'),
            'window_days' => $window,
            'target_count' => count($targets),
            'classification_counts' => $this->classificationCounts($targets),
            'targets' => $targets,
            'allowed_actions' => [
                'read_publish_evidence',
                'read_content_page_publish_state',
                'read_url_truth',
                'read_seo_gsc_daily',
                'evidence_artifact_write',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];

        $path = rtrim($artifactDir, '/').'/seo-agent-gsc-post-publish-feedback-'.$timestamp.'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('feedback_artifact_write_failed');
        }

        return $this->artifactRef($path, self::SCHEMA_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => $schemaVersion,
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
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function contentPageIdFromRef(string $subjectRef): ?int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== 'content_page' || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }

    private function safePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $parts = parse_url($path);
        if (is_array($parts) && isset($parts['path'])) {
            $path = (string) $parts['path'];
        }

        $path = '/'.ltrim($path, '/');

        return preg_replace('#/+#', '/', $path) ?: '/';
    }

    private function safePathFromCanonicalUrl(string $canonicalUrl): string
    {
        $path = parse_url($canonicalUrl, PHP_URL_PATH);

        return $this->safePath(is_string($path) ? $path : '');
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

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'google_search_console_live_api_call',
            'google_indexing_api_call',
            'search_channel_enqueue',
            'search_channel_submit',
            'cms_write',
            'cms_publish',
            'database_write',
            'scheduler_activation',
            'queue_worker_activation',
            'frontend_mutation',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'google_search_console_live_api_call' => false,
            'google_indexing_api_call' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'database_write' => false,
            'scheduler_activation' => false,
            'queue_worker_activation' => false,
            'frontend_mutation' => false,
            'canonical_url_exposed' => false,
            'query_text_exposed' => false,
        ];
    }
}
