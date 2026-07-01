<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityProfile;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class PersonalityAgentPostPromotionSearchGateCommand extends Command
{
    private const SCHEMA_VERSION = 'personality-agent-post-promotion-search-gate.v1';

    private const V8_5_V5_BILINGUAL_64_ARTIFACT = 'MBTI64-ZH32-EN32-V8_5-V5-BILINGUAL-PACKAGE-QA-01';

    private const V8_5_V5_BILINGUAL_64_PACKAGE_VERSION = 'mbti64_zh32_en32_v8_5_v5_bilingual_v1';

    private const V8_5_V5_BILINGUAL_64_PACKAGE_FILE_SHA256 = 'a0fd058b82ec40940b8c92546c461086d3bfca7a4b0521aeb92e5cc8b0517b67';

    private const V8_5_V5_BILINGUAL_64_EMBEDDED_PACKAGE_SHA256 = '13ec2c55caf2cf7b48650739fabadfb09ec4a02214cb1af99d5e47d8af2499d8';

    private const SURFACE_PATHS = [
        'sitemap' => '/sitemap.xml',
        'llms' => '/llms.txt',
        'llms_full' => '/llms-full.txt',
    ];

    private const PRIVATE_ROUTE_FAMILIES = [
        'result',
        'results',
        'orders',
        'pay',
        'payment',
        'history',
        'private',
        'account',
    ];

    private const SENSITIVE_QUERY_KEYS = [
        'token',
        'session',
        'result_id',
        'report_id',
        'order_no',
    ];

    protected $signature = 'personality:agent-post-promotion-search-gate
        {--dry-run : Required. Run read-only planning only}
        {--json : Emit JSON summary}
        {--output= : Optional output JSON path}
        {--channel=indexnow : Search Channel to dry-run; v1 supports indexnow}
        {--urls= : Comma-separated canonical URLs/paths or path to JSON list}
        {--package= : Optional agent/promotion package JSON containing target URLs}
        {--v8-5-v5-bilingual-64 : Require the fixed 64 MBTI64 V8.5/V5 bilingual package and URL set}
        {--base-url= : Public site base URL; defaults to seo_intel.public_canonical_host}
        {--timeout=10 : Public HTTP timeout seconds}';

    protected $description = 'Read-only post-promotion search gate for personality agent batches; no enqueue, approve, submit, CMS, sitemap, or llms mutation.';

    public function handle(SearchChannelQueuePlanner $planner): int
    {
        if (! (bool) $this->option('dry-run')) {
            return $this->finish($this->payload('NO_GO_SAFETY_VIOLATION', ['dry_run_required']));
        }

        $channel = strtolower(trim((string) $this->option('channel')));
        if ($channel !== 'indexnow') {
            return $this->finish($this->payload('NO_GO_SAFETY_VIOLATION', ['channel_not_allowed']));
        }

        $baseUrl = $this->baseUrl();
        $targets = $this->targets($baseUrl);
        if ($targets === []) {
            return $this->finish($this->payload('NO_GO_SURFACE_OR_SAFETY', ['target_urls_missing']));
        }

        $fixedSubsetIssues = $this->fixedV85V5Bilingual64Issues($targets);
        if ($fixedSubsetIssues !== []) {
            return $this->finish($this->payload('NO_GO_SAFETY_VIOLATION', $fixedSubsetIssues));
        }

        $surfaceTexts = $this->fetchSurfaceTexts($baseUrl);
        $results = [];
        $issues = [];
        foreach ($targets as $target) {
            $surface = $this->liveSurfaceObservation($target);
            $membership = $this->surfaceMembership($target, $surfaceTexts);
            $truth = $this->urlTruthObservation($target['canonical_url']);
            $plan = $truth['found']
                ? $this->planSummary($planner->plan($channel, (string) $truth['page_entity_type'], 1, $target['canonical_url']))
                : $this->emptyPlanSummary('url_truth_missing');

            $resultIssues = array_values(array_unique([
                ...$surface['issues'],
                ...$membership['issues'],
                ...$truth['issues'],
                ...$plan['issues'],
            ]));
            $issues = [...$issues, ...$resultIssues];

            $results[] = [
                'canonical_url' => $target['canonical_url'],
                'path' => $target['path'],
                'live_surface' => $surface,
                'sitemap_llms_membership' => $membership,
                'url_truth' => $truth,
                'search_queue_plan' => $plan,
                'issues' => $resultIssues,
            ];
        }

        $decision = $this->decision($results);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $decision,
            'final_decision' => $decision,
            'ok' => $decision === 'GO_FOR_INDEXNOW_DRY_RUN',
            'dry_run' => true,
            'write' => false,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'channel' => $channel,
            'base_url' => $baseUrl,
            'contract' => $this->contractSummary($targets),
            'target_count' => count($targets),
            'targets' => $results,
            'counts' => [
                'surface_ok' => $this->countPassing($results, 'live_surface.ok'),
                'sitemap_llms_ok' => $this->countPassing($results, 'sitemap_llms_membership.ok'),
                'url_truth_ready' => $this->countPassing($results, 'url_truth.ready'),
                'planned_or_duplicate_safe' => $this->countPassing($results, 'search_queue_plan.ready'),
            ],
            'issues' => array_values(array_unique($issues)),
            'recommended_next_task' => $decision === 'GO_FOR_INDEXNOW_DRY_RUN'
                ? 'PERSONALITY-AGENT-POST-PROMOTION-INDEXNOW-DRY-RUN-01'
                : $this->recommendedNextTask($decision),
            'safety_flags' => $this->safetyFlags(),
        ];

        $this->writeOutput($payload);

        return $this->finish($payload);
    }

    /**
     * @return list<array{canonical_url:string, path:string}>
     */
    private function targets(string $baseUrl): array
    {
        $values = [];
        $urlsOption = trim((string) $this->option('urls'));
        if ($urlsOption !== '') {
            $values = array_merge($values, $this->valuesFromOption($urlsOption));
        }

        $packagePath = trim((string) $this->option('package'));
        if ($packagePath !== '') {
            $path = $this->safePath($packagePath);
            if ($path !== null) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded)) {
                    $values = array_merge($values, $this->v85V5Bilingual64Requested()
                        ? $this->collectRecommendationTargetUrls($decoded)
                        : $this->collectTargetValues($decoded));
                }
            }
        }

        $targets = [];
        foreach ($values as $value) {
            $target = $this->normalizeTarget($value, $baseUrl);
            if ($target === null) {
                continue;
            }
            $targets[$target['canonical_url']] = $target;
        }

        return array_values($targets);
    }

    private function v85V5Bilingual64Requested(): bool
    {
        return (bool) $this->option('v8-5-v5-bilingual-64');
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function collectRecommendationTargetUrls(array $payload): array
    {
        $recommendations = is_array($payload['recommendations'] ?? null)
            ? array_values((array) $payload['recommendations'])
            : [];

        $values = [];
        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            if ($targetUrl !== '') {
                $values[] = $targetUrl;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{canonical_url:string, path:string}>  $targets
     * @return list<string>
     */
    private function fixedV85V5Bilingual64Issues(array $targets): array
    {
        if (! $this->v85V5Bilingual64Requested()) {
            return [];
        }

        $issues = [];
        $packagePath = trim((string) $this->option('package'));
        $path = $this->safePath($packagePath);
        if ($packagePath === '' || $path === null) {
            $issues[] = 'v8_5_v5_bilingual_64_package_required';

            return $issues;
        }

        if (hash_file('sha256', $path) !== self::V8_5_V5_BILINGUAL_64_PACKAGE_FILE_SHA256) {
            $issues[] = 'unsupported_v8_5_v5_bilingual_64_package_file_sha256';
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $issues[] = 'v8_5_v5_bilingual_64_package_json_invalid';

            return $issues;
        }

        if ((string) ($decoded['artifact'] ?? '') !== self::V8_5_V5_BILINGUAL_64_ARTIFACT) {
            $issues[] = 'unsupported_v8_5_v5_bilingual_64_artifact';
        }
        if ((string) ($decoded['package_version'] ?? '') !== self::V8_5_V5_BILINGUAL_64_PACKAGE_VERSION) {
            $issues[] = 'unsupported_v8_5_v5_bilingual_64_package_version';
        }
        if ((string) ($decoded['package_sha256'] ?? '') !== self::V8_5_V5_BILINGUAL_64_EMBEDDED_PACKAGE_SHA256) {
            $issues[] = 'unsupported_v8_5_v5_bilingual_64_embedded_package_sha256';
        }
        if ((int) ($decoded['target_count'] ?? -1) !== 64) {
            $issues[] = 'v8_5_v5_bilingual_64_target_count_mismatch';
        }

        $summary = is_array($decoded['summary'] ?? null) ? $decoded['summary'] : [];
        if ((int) ($summary['target_count'] ?? -1) !== 64
            || (int) ($summary['variant_pages'] ?? $summary['variant_count'] ?? -1) !== 64
            || (int) ($summary['comparison_pages'] ?? $summary['comparison_count'] ?? -1) !== 0
            || (int) ($summary['zh_pages'] ?? $summary['zh_count'] ?? -1) !== 32
            || (int) ($summary['en_pages'] ?? $summary['en_count'] ?? -1) !== 32
            || (int) ($summary['qa_blocked_count'] ?? $summary['blocked_count'] ?? -1) !== 0) {
            $issues[] = 'v8_5_v5_bilingual_64_summary_mismatch';
        }

        $actualUrls = array_values(array_unique(array_column($targets, 'canonical_url')));
        sort($actualUrls);
        if ($actualUrls !== $this->v85V5Bilingual64Urls()) {
            $issues[] = 'v8_5_v5_bilingual_64_url_set_mismatch';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return array<string, mixed>
     */
    private function contractSummary(array $targets): array
    {
        if (! $this->v85V5Bilingual64Requested()) {
            return [
                'mode' => 'ad_hoc_targets',
            ];
        }

        return [
            'mode' => 'v8_5_v5_bilingual_64',
            'package_file_sha256' => self::V8_5_V5_BILINGUAL_64_PACKAGE_FILE_SHA256,
            'embedded_package_sha256' => self::V8_5_V5_BILINGUAL_64_EMBEDDED_PACKAGE_SHA256,
            'artifact' => self::V8_5_V5_BILINGUAL_64_ARTIFACT,
            'package_version' => self::V8_5_V5_BILINGUAL_64_PACKAGE_VERSION,
            'target_count' => count($targets),
        ];
    }

    /**
     * @return list<string>
     */
    private function v85V5Bilingual64Urls(): array
    {
        $urls = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                foreach (['a', 't'] as $variant) {
                    $urls[] = 'https://fermatmind.com/'.$prefix.'/personality/'.strtolower($typeCode).'-'.$variant;
                }
            }
        }
        sort($urls);

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function valuesFromOption(string $raw): array
    {
        $path = $this->safePath($raw);
        if ($path !== null) {
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $this->collectTargetValues($decoded) : [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function collectTargetValues(array $payload): array
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->collectTargetValues($value));
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            if (in_array((string) $key, ['target_url', 'canonical_url', 'canonicalUrl', 'path'], true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array{canonical_url:string, path:string}|null
     */
    private function normalizeTarget(string $value, string $baseUrl): ?array
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }

        $parts = parse_url($value);
        $path = is_array($parts) && isset($parts['host'])
            ? (string) ($parts['path'] ?? '')
            : $value;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if ($host !== '' && ! in_array($host, ['fermatmind.com', 'www.fermatmind.com'], true)) {
            return null;
        }
        if (! str_starts_with($path, '/en/personality/') && ! str_starts_with($path, '/zh/personality/')) {
            return null;
        }
        if ($this->containsPrivatePattern($path)) {
            return null;
        }

        return [
            'canonical_url' => $baseUrl.$path,
            'path' => $path,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function fetchSurfaceTexts(string $baseUrl): array
    {
        $texts = [];
        foreach (self::SURFACE_PATHS as $key => $path) {
            try {
                $response = Http::timeout($this->timeout())->get($baseUrl.$path);
                $texts[$key] = $response->ok() ? (string) $response->body() : null;
            } catch (\Throwable) {
                $texts[$key] = null;
            }
        }

        return $texts;
    }

    /**
     * @param  array{canonical_url:string, path:string}  $target
     * @return array<string, mixed>
     */
    private function liveSurfaceObservation(array $target): array
    {
        $issues = [];
        $status = null;
        $canonical = null;
        $robots = null;
        $privateMatches = [];

        try {
            $response = Http::timeout($this->timeout())->withOptions(['allow_redirects' => true])->get($target['canonical_url']);
            $status = $response->status();
            $html = (string) $response->body();
            $canonical = $this->firstMatch('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html)
                ?? $this->firstMatch('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html);
            $robots = $this->firstMatch('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html);
            $privateMatches = $this->privateMatches($html);

            if (! $response->ok()) {
                $issues[] = 'live_surface_non_200';
            }
            if ($canonical !== $target['canonical_url']) {
                $issues[] = 'canonical_mismatch';
            }
            if (is_string($robots) && str_contains(strtolower($robots), 'noindex')) {
                $issues[] = 'noindex_present';
            }
            if ($privateMatches !== []) {
                $issues[] = 'private_route_or_sensitive_pattern_present';
            }
        } catch (\Throwable) {
            $issues[] = 'live_surface_request_failed';
        }

        return [
            'ok' => $issues === [],
            'status' => $status,
            'canonical_ok' => $canonical === $target['canonical_url'],
            'robots_indexable' => ! (is_string($robots) && str_contains(strtolower($robots), 'noindex')),
            'private_match_count' => count($privateMatches),
            'private_matches' => $privateMatches,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array{canonical_url:string, path:string}  $target
     * @param  array<string, string|null>  $surfaceTexts
     * @return array<string, mixed>
     */
    private function surfaceMembership(array $target, array $surfaceTexts): array
    {
        $membership = [];
        $issues = [];
        foreach (self::SURFACE_PATHS as $key => $_path) {
            $text = $surfaceTexts[$key] ?? null;
            $present = is_string($text) && str_contains($text, $target['canonical_url']);
            $membership[$key] = $present;
            if (! $present) {
                $issues[] = $key.'_membership_missing';
            }
            if (is_string($text) && $this->containsPrivatePattern($text)) {
                $issues[] = $key.'_private_pattern_present';
            }
        }

        return [
            'ok' => $issues === [],
            'membership' => $membership,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function urlTruthObservation(string $canonicalUrl): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_urls')) {
            return [
                'found' => false,
                'ready' => false,
                'issues' => ['seo_urls_table_missing'],
            ];
        }

        $row = DB::connection($connection)
            ->table('seo_urls')
            ->where('canonical_url', $canonicalUrl)
            ->first();

        if ($row === null) {
            return [
                'found' => false,
                'ready' => false,
                'issues' => ['canonical_url_not_found'],
            ];
        }

        $metadata = json_decode((string) ($row->metadata_json ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $contentHash = strtolower(trim((string) ($metadata['content_hash'] ?? '')));
        $lastmod = trim((string) ($row->lastmod_at ?? ''));
        $issues = [];
        if ($contentHash === '') {
            $issues[] = 'url_truth_content_hash_missing';
        }
        if ($lastmod === '') {
            $issues[] = 'url_truth_lastmod_missing';
        }

        return [
            'found' => true,
            'ready' => $issues === [],
            'page_entity_type' => (string) $row->page_entity_type,
            'locale' => (string) $row->locale,
            'content_hash_present' => $contentHash !== '',
            'lastmod_present' => $lastmod !== '',
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planSummary(array $plan): array
    {
        $planned = (int) ($plan['planned_queue_count'] ?? 0);
        $duplicate = (bool) ($plan['duplicate_detected'] ?? false);
        $issues = [];
        if ($planned < 1 && $duplicate) {
            $issues[] = 'duplicate_active_queue_item';
        } elseif ($planned < 1) {
            $issues[] = (string) (($plan['source_unavailable_reason'] ?? null) ?: 'no_planned_queue_item');
        }

        return [
            'ready' => $planned > 0,
            'planned_queue_count' => $planned,
            'duplicate_detected' => $duplicate,
            'stale_submitted_queue_item_count' => (int) ($plan['stale_submitted_queue_item_count'] ?? 0),
            'reason_code_breakdown' => $plan['reason_code_breakdown'] ?? [],
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlanSummary(string $issue): array
    {
        return [
            'ready' => false,
            'planned_queue_count' => 0,
            'duplicate_detected' => false,
            'stale_submitted_queue_item_count' => 0,
            'reason_code_breakdown' => [],
            'issues' => [$issue],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function decision(array $results): string
    {
        if ($this->countPassing($results, 'live_surface.ok') < count($results)
            || $this->countPassing($results, 'sitemap_llms_membership.ok') < count($results)) {
            return 'NO_GO_SURFACE_OR_SAFETY';
        }
        if ($this->countPassing($results, 'url_truth.ready') < count($results)) {
            return 'CONDITIONAL_URL_TRUTH_REFRESH_REQUIRED';
        }
        if ($this->countPassing($results, 'search_queue_plan.ready') < count($results)) {
            return 'CONDITIONAL_DUPLICATE_OR_POLICY_REVIEW';
        }

        return 'GO_FOR_INDEXNOW_DRY_RUN';
    }

    private function recommendedNextTask(string $decision): string
    {
        return match ($decision) {
            'CONDITIONAL_URL_TRUTH_REFRESH_REQUIRED' => 'PERSONALITY-URL-TRUTH-HANDOFF-REFRESH-01',
            'CONDITIONAL_DUPLICATE_OR_POLICY_REVIEW' => 'PERSONALITY-SEARCH-QUEUE-DUPLICATE-STATE-REVIEW-01',
            default => 'PERSONALITY-POST-PROMOTION-SURFACE-SAFETY-REPAIR-01',
        };
    }

    private function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $matches) === 1 ? (string) $matches[1] : null;
    }

    /**
     * @return list<string>
     */
    private function privateMatches(string $text): array
    {
        $matches = [];
        $lower = strtolower($text);
        foreach (self::SENSITIVE_QUERY_KEYS as $key) {
            if (preg_match('/(?:[?&]|&amp;)'.preg_quote($key, '/').'=|(?:^|[\s"\'])'.preg_quote($key, '/').'=/i', $text) === 1) {
                $matches[] = $key.'=';
            }
        }

        if (preg_match_all('/https?:\/\/[^\s<>"\')]+/i', $text, $urlMatches) > 0) {
            foreach ($urlMatches[0] as $url) {
                $parts = parse_url($url);
                if (! is_array($parts)) {
                    continue;
                }

                $host = strtolower((string) ($parts['host'] ?? ''));
                if (! in_array($host, ['fermatmind.com', 'www.fermatmind.com'], true)) {
                    continue;
                }

                $path = $this->stripLocalePrefix($this->normalizePath((string) ($parts['path'] ?? '/')));
                if ($this->isPrivateRoutePath($path)) {
                    $matches[] = $url;
                }

                $query = (string) ($parts['query'] ?? '');
                if ($query !== '') {
                    parse_str($query, $queryParams);
                    foreach (self::SENSITIVE_QUERY_KEYS as $key) {
                        if (array_key_exists($key, $queryParams)) {
                            $matches[] = $key.'=';
                        }
                    }
                }
            }
        }

        if (preg_match_all('/(?<![A-Za-z0-9_-])\/(?:(?:en|zh)\/)?(?:result|results|orders|pay|payment|history|private|account)(?:[\/?#\s<>"\')]|$)/i', $lower, $pathMatches) > 0) {
            foreach ($pathMatches[0] as $match) {
                $matches[] = trim($match);
            }
        }

        return array_values(array_unique(array_filter($matches)));
    }

    private function containsPrivatePattern(string $text): bool
    {
        return $this->privateMatches($text) !== [];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return rtrim(preg_replace('/\/+/', '/', $path) ?: '/', '/') ?: '/';
    }

    private function stripLocalePrefix(string $path): string
    {
        $stripped = preg_replace('/^\/(?:en|zh)(?=\/|$)/i', '', $path) ?: $path;

        return $stripped === '' ? '/' : $stripped;
    }

    private function isPrivateRoutePath(string $path): bool
    {
        $firstSegment = strtolower(explode('/', trim($path, '/'))[0] ?? '');

        return in_array($firstSegment, self::PRIVATE_ROUTE_FAMILIES, true);
    }

    private function baseUrl(): string
    {
        $option = trim((string) $this->option('base-url'));
        $base = $option !== '' ? $option : (string) config('seo_intel.public_canonical_host', 'https://fermatmind.com');
        $base = rtrim($base, '/');

        return $base === '' ? 'https://fermatmind.com' : $base;
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) $this->option('timeout')));
    }

    private function safePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function writeOutput(array $payload): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '' || str_contains($output, "\0")) {
            return;
        }

        $path = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function countPassing(array $rows, string $key): int
    {
        return collect($rows)
            ->filter(fn (array $row): bool => (bool) data_get($row, $key, false))
            ->count();
    }

    /**
     * @return array<string, bool>
     */
    private function safetyFlags(): array
    {
        return [
            'writes_attempted' => false,
            'writes_committed' => false,
            'enqueue_attempted' => false,
            'enqueue_committed' => false,
            'approval_attempted' => false,
            'search_submission_attempted' => false,
            'live_submission_attempted' => false,
            'external_search_api_calls_attempted' => false,
            'cms_mutation_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'url_truth_write_attempted' => false,
        ];
    }

    private function payload(string $decision, array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $decision,
            'final_decision' => $decision,
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => false,
            'issues' => $issues,
            'safety_flags' => $this->safetyFlags(),
        ];
    }

    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
        }

        return ($payload['final_decision'] ?? $payload['status'] ?? null) === 'NO_GO_SAFETY_VIOLATION'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
