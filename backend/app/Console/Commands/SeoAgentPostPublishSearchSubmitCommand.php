<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use Illuminate\Console\Command;

final class SeoAgentPostPublishSearchSubmitCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-post-publish-search-submit.v1';

    private const PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

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

    protected $signature = 'seo-agent:post-publish-search-submit
        {--publish-evidence= : Path to seo-agent-cms-publish-canary.v1 JSON evidence}
        {--channels=indexnow,google_sitemap : Comma-separated Search Channel queue channels}
        {--limit=1 : First post-publish bridge requires exactly 1 published URL}
        {--base-url= : Public site base URL used to resolve safe paths; defaults to app.url}
        {--confirm-evidence-sha256= : Required publish evidence sha256 for execute mode}
        {--execute : Actually enqueue Search Channel rows; no live external submission}
        {--json : Emit JSON summary}';

    protected $description = 'Bridge one SEO Agent publish canary into Search Channel queue evidence without live external submission.';

    public function handle(SearchChannelQueuePlanner $planner, SearchChannelQueueWriteService $writer): int
    {
        $evidencePath = $this->readablePath((string) $this->option('publish-evidence'));
        if ($evidencePath === null) {
            return $this->finish($this->failureSummary('publish_evidence_unreadable'));
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_equal_one'));
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

        $evidenceIssue = $this->validatePublishEvidence($evidence);
        if ($evidenceIssue !== null) {
            return $this->finish($this->failureSummary($evidenceIssue));
        }

        $affected = $this->firstAffectedRef($evidence);
        if ($affected === null) {
            return $this->finish($this->failureSummary('published_ref_missing'));
        }

        $targetIssue = $this->validatePublishedContentPage($affected);
        if ($targetIssue !== null) {
            return $this->finish($this->failureSummary($targetIssue));
        }

        $safePath = (string) ($affected['safe_path'] ?? '');
        $canonicalUrl = $this->canonicalUrl($safePath);
        if ($canonicalUrl === null) {
            return $this->finish($this->failureSummary('canonical_url_resolution_failed'));
        }

        $channels = $this->channels();
        if ($channels === []) {
            return $this->finish($this->failureSummary('channels_missing'));
        }

        $evidenceSha = hash_file('sha256', $evidencePath) ?: '';
        $execute = (bool) $this->option('execute');
        if ($execute && (string) $this->option('confirm-evidence-sha256') !== $evidenceSha) {
            return $this->finish($this->failureSummary('evidence_sha256_confirmation_mismatch', [
                'publish_evidence_sha256' => $evidenceSha,
            ]));
        }

        $plans = [];
        $plannedItems = [];
        $issues = [];
        $duplicates = 0;

        foreach ($channels as $channel) {
            $plan = $planner->plan($channel, 'content_page', 20, $canonicalUrl);
            $plans[$channel] = $this->safePlanSummary($plan);
            if ((int) ($plan['planned_queue_count'] ?? 0) > 0) {
                $plannedItems = array_merge($plannedItems, (array) ($plan['planned_items'] ?? []));
            } elseif ((bool) ($plan['duplicate_detected'] ?? false)) {
                $duplicates++;
            } else {
                $issues[] = $channel.':'.(string) (($plan['source_unavailable_reason'] ?? null) ?: 'no_eligible_queue_item');
            }
        }

        $plannedItems = array_slice($plannedItems, 0, count($channels));

        if (! $execute) {
            return $this->finish([
                'schema_version' => self::SCHEMA_VERSION,
                'ok' => true,
                'status' => 'planned',
                'dry_run' => true,
                'execute' => false,
                'publish_evidence_sha256' => $evidenceSha,
                'canonical_url_hash' => hash('sha256', $canonicalUrl),
                'safe_path' => $safePath,
                'channels' => $channels,
                'planned_queue_count' => count($plannedItems),
                'duplicate_count' => $duplicates,
                'plans' => $plans,
                'issues' => array_values(array_unique($issues)),
                'search_channel_enqueue_attempted' => false,
                'search_channel_enqueue_committed' => false,
                'google_indexing_request_planned' => in_array('google_sitemap', $channels, true),
                'google_indexing_live_api_called' => false,
                'boundaries' => $this->boundaries(false),
            ]);
        }

        if ($plannedItems === [] && $duplicates < count($channels)) {
            return $this->finish($this->failureSummary('no_eligible_queue_items', [
                'publish_evidence_sha256' => $evidenceSha,
                'canonical_url_hash' => hash('sha256', $canonicalUrl),
                'plans' => $plans,
                'issues' => array_values(array_unique($issues)),
            ]));
        }

        $writeResult = $plannedItems === [] ? ['batch_ids' => [], 'written_items' => 0] : $writer->write($plannedItems);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'publish_evidence_sha256' => $evidenceSha,
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'safe_path' => $safePath,
            'channels' => $channels,
            'batch_ids' => $writeResult['batch_ids'],
            'written_items' => (int) $writeResult['written_items'],
            'duplicate_count' => $duplicates,
            'search_channel_enqueue_attempted' => true,
            'search_channel_enqueue_committed' => ((int) $writeResult['written_items']) > 0,
            'search_submission_attempted' => false,
            'google_indexing_request_planned' => in_array('google_sitemap', $channels, true),
            'google_indexing_live_api_called' => false,
            'plans' => $plans,
            'boundaries' => $this->boundaries(((int) $writeResult['written_items']) > 0),
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

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function validatePublishEvidence(array $evidence): ?string
    {
        if (($evidence['schema_version'] ?? null) !== self::PUBLISH_SCHEMA_VERSION) {
            return 'publish_evidence_schema_invalid';
        }
        if (($evidence['status'] ?? null) !== 'success' || (bool) ($evidence['execute'] ?? false) !== true) {
            return 'publish_evidence_not_success_execute';
        }
        if ((bool) ($evidence['writes_committed'] ?? false) !== true || (int) ($evidence['published_count'] ?? 0) !== 1) {
            return 'publish_evidence_missing_one_committed_publish';
        }
        if ((bool) data_get($evidence, 'rollback_evidence.available') !== true) {
            return 'rollback_evidence_missing';
        }
        if ((bool) data_get($evidence, 'boundaries.cms_publish') !== true
            || (bool) data_get($evidence, 'boundaries.search_channel_enqueue') !== false
            || (bool) data_get($evidence, 'boundaries.indexing_request') !== false) {
            return 'publish_evidence_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>|null
     */
    private function firstAffectedRef(array $evidence): ?array
    {
        foreach ((array) ($evidence['affected_refs'] ?? []) as $ref) {
            if (is_array($ref) && ($ref['status'] ?? null) === 'published') {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $affected
     */
    private function validatePublishedContentPage(array $affected): ?string
    {
        if (($affected['target_model'] ?? null) !== 'content_page') {
            return 'target_model_not_supported';
        }

        $pageId = $this->idFromSubjectRef((string) ($affected['subject_ref'] ?? ''), 'content_page');
        if ($pageId < 1) {
            return 'subject_ref_invalid';
        }

        $page = ContentPage::query()->withoutGlobalScopes()->find($pageId);
        if (! $page instanceof ContentPage) {
            return 'content_page_not_found';
        }
        if ((string) $page->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $page->is_public || ! (bool) $page->is_indexable) {
            return 'content_page_not_public_indexable';
        }

        return null;
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== $expectedType || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return 0;
        }

        return (int) $parts[1];
    }

    private function canonicalUrl(string $safePath): ?string
    {
        if ($safePath === '' || ! str_starts_with($safePath, '/') || str_starts_with($safePath, '//')) {
            return null;
        }

        $base = trim((string) ($this->option('base-url') ?: config('app.url')));
        if ($base === '') {
            return null;
        }

        $url = rtrim($base, '/').$safePath;

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * @return list<string>
     */
    private function channels(): array
    {
        $channels = array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', (string) $this->option('channels')),
        ), static fn (string $channel): bool => $channel !== '')));

        return array_values(array_filter($channels, static fn (string $channel): bool => in_array($channel, ['indexnow', 'google_sitemap'], true)));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function safePlanSummary(array $plan): array
    {
        return [
            'source_unavailable_reason' => $plan['source_unavailable_reason'] ?? null,
            'candidate_count' => (int) ($plan['candidate_count'] ?? 0),
            'eligible_count' => (int) ($plan['eligible_count'] ?? 0),
            'blocked_count' => (int) ($plan['blocked_count'] ?? 0),
            'planned_queue_count' => (int) ($plan['planned_queue_count'] ?? 0),
            'duplicate_detected' => (bool) ($plan['duplicate_detected'] ?? false),
            'reason_code_breakdown' => (array) ($plan['reason_code_breakdown'] ?? []),
        ];
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
            'search_channel_enqueue_attempted' => false,
            'search_channel_enqueue_committed' => false,
            'search_submission_attempted' => false,
            'google_indexing_live_api_called' => false,
            'boundaries' => $this->boundaries(false),
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
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, bool>
     */
    private function boundaries(bool $queueWritten): array
    {
        return [
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => $queueWritten,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'google_indexing_live_api_call' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
