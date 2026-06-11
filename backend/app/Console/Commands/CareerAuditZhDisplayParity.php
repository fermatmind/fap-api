<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerCliArtifactPathGuard;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CareerAuditZhDisplayParity extends Command
{
    private const VALIDATOR_VERSION = 'career_zh_display_parity_audit_v0.2';

    private const DEFAULT_API_BASE = 'https://api.fermatmind.com/api/v0.5/career/jobs';

    private const DEFAULT_SITE_BASE = 'https://fermatmind.com';

    protected $signature = 'career:audit-zh-display-parity
        {--api-base= : Public career job API base URL}
        {--site-base= : Public site base URL for sample URLs}
        {--slugs= : Optional comma-separated slugs; omitted scans EN/ZH public job indexes}
        {--timeout=15 : HTTP timeout in seconds}
        {--batch-size=40 : Max slugs per concurrent detail batch}
        {--sample-limit=20 : Max sample rows per summary bucket}
        {--summary-only : Omit per-slug items from the JSON report}
        {--assert-live-parity : Exit non-zero when the live parity gate is blocked}
        {--json : Emit JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Read-only EN/ZH public career job display parity audit.';

    public function handle(): int
    {
        try {
            $apiBase = $this->baseUrl((string) ($this->option('api-base') ?: self::DEFAULT_API_BASE));
            $siteBase = $this->baseUrl((string) ($this->option('site-base') ?: self::DEFAULT_SITE_BASE));
            $timeout = max(1, (int) $this->option('timeout'));
            $batchSize = max(1, min(100, (int) $this->option('batch-size')));
            $sampleLimit = max(1, (int) $this->option('sample-limit'));
            $explicitSlugs = $this->csvOption('slugs');
            $assertLiveParity = (bool) $this->option('assert-live-parity');

            $index = $explicitSlugs === []
                ? $this->indexSlugs($apiBase, $timeout)
                : [
                    'en' => $explicitSlugs,
                    'zh-CN' => $explicitSlugs,
                    'failures' => [],
                ];

            $slugs = $explicitSlugs === []
                ? $this->sortedUnique(array_merge($index['en'], $index['zh-CN']))
                : $explicitSlugs;

            $items = $this->auditSlugs($apiBase, $siteBase, $slugs, $timeout, $batchSize);

            $summary = $this->summary($items, $index, $sampleLimit);
            $liveGate = $this->liveGate($summary, $items);
            $report = [
                'validator_version' => self::VALIDATOR_VERSION,
                'decision' => $assertLiveParity
                    ? $liveGate['decision']
                    : (($summary['api_failure_count'] ?? 0) === 0 ? 'pass' : 'blocked'),
                'read_only' => true,
                'writes_database' => false,
                'cms_mutation' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'index_strategy_changed' => false,
                'api_base' => $apiBase,
                'site_base' => $siteBase,
                'scan_scope' => $explicitSlugs === [] ? 'public_index_union' : 'explicit_slugs',
                'assert_live_parity' => $assertLiveParity,
                'live_gate' => $liveGate,
                'summary' => $summary,
                'items' => (bool) $this->option('summary-only') ? [] : $items,
                'next_prs' => [
                    'controlled_import_required' => 'If live_gate.decision is blocked because zh pages remain restricted shells or structurally mismatched, run only an explicitly approved reviewed-workbook import/publish/cache refresh before asserting live parity.',
                ],
            ];

            return $this->finish($report);
        } catch (Throwable $throwable) {
            return $this->finish([
                'validator_version' => self::VALIDATOR_VERSION,
                'decision' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'cms_mutation' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'index_strategy_changed' => false,
                'errors' => [$throwable->getMessage()],
            ]);
        }
    }

    /**
     * @return array{en: list<string>, zh-CN: list<string>, failures: list<array<string, mixed>>}
     */
    private function indexSlugs(string $apiBase, int $timeout): array
    {
        $en = $this->fetchJson($apiBase, ['locale' => 'en'], $timeout);
        $zh = $this->fetchJson($apiBase, ['locale' => 'zh-CN'], $timeout);

        return [
            'en' => $this->slugsFromIndex($en['json']),
            'zh-CN' => $this->slugsFromIndex($zh['json']),
            'failures' => array_values(array_filter([
                $en['ok'] ? null : ['locale' => 'en', 'status' => $en['status'], 'url' => $en['url']],
                $zh['ok'] ? null : ['locale' => 'zh-CN', 'status' => $zh['status'], 'url' => $zh['url']],
            ])),
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function auditSlugs(string $apiBase, string $siteBase, array $slugs, int $timeout, int $batchSize): array
    {
        $items = [];
        foreach (array_chunk($slugs, $batchSize) as $chunk) {
            /** @var array<string, Response> $responses */
            $responses = Http::pool(function (Pool $pool) use ($apiBase, $chunk, $timeout): array {
                $requests = [];
                foreach ($chunk as $slug) {
                    $requests[$slug.'|en'] = $pool->as($slug.'|en')
                        ->timeout($timeout)
                        ->acceptJson()
                        ->get($apiBase.'/'.$slug, ['locale' => 'en']);
                    $requests[$slug.'|zh-CN'] = $pool->as($slug.'|zh-CN')
                        ->timeout($timeout)
                        ->acceptJson()
                        ->get($apiBase.'/'.$slug, ['locale' => 'zh-CN']);
                }

                return $requests;
            });

            foreach ($chunk as $slug) {
                $items[] = $this->auditSlugFromResponses(
                    $siteBase,
                    $slug,
                    $this->responseResult($responses[$slug.'|en'] ?? null, $this->urlWithQuery($apiBase.'/'.$slug, ['locale' => 'en'])),
                    $this->responseResult($responses[$slug.'|zh-CN'] ?? null, $this->urlWithQuery($apiBase.'/'.$slug, ['locale' => 'zh-CN'])),
                );
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSlugFromResponses(string $siteBase, string $slug, array $en, array $zh): array
    {
        $enKeys = $this->displayContentKeys($en['json']);
        $zhKeys = $this->displayContentKeys($zh['json']);
        $enOnly = array_values(array_diff($enKeys, $zhKeys));
        $zhOnly = array_values(array_diff($zhKeys, $enKeys));
        $gateReasons = $this->zhGateReasons($zh['json'], $enOnly, $en, $zh);

        return [
            'slug' => $slug,
            'status' => [
                'en' => $en['status'],
                'zh-CN' => $zh['status'],
            ],
            'sample_urls' => [
                'en' => $siteBase.'/en/career/jobs/'.$slug,
                'zh' => $siteBase.'/zh/career/jobs/'.$slug,
                'api_en' => $en['url'],
                'api_zh' => $zh['url'],
            ],
            'module_counts' => [
                'en' => count($enKeys),
                'zh-CN' => count($zhKeys),
                'en_only' => count($enOnly),
                'zh_only' => count($zhOnly),
            ],
            'missing_modules' => [
                'en_only' => $enOnly,
                'zh_only' => $zhOnly,
            ],
            'zh_gate_reasons' => $gateReasons,
            'classification' => $this->classification($en, $zh, $enOnly, $zhOnly),
        ];
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function summary(array $items, array $index, int $sampleLimit): array
    {
        $classifications = [];
        foreach ($items as $item) {
            $classification = (string) ($item['classification'] ?? 'unknown');
            $classifications[$classification] = ($classifications[$classification] ?? 0) + 1;
        }

        $sampleByClassification = [];
        foreach ($items as $item) {
            $classification = (string) ($item['classification'] ?? 'unknown');
            $sampleByClassification[$classification] ??= [];
            if (count($sampleByClassification[$classification]) < $sampleLimit) {
                $sampleByClassification[$classification][] = [
                    'slug' => $item['slug'],
                    'sample_urls' => $item['sample_urls'],
                    'module_counts' => $item['module_counts'],
                    'zh_gate_reasons' => $item['zh_gate_reasons'],
                ];
            }
        }

        $indexEn = $index['en'] ?? [];
        $indexZh = $index['zh-CN'] ?? [];

        return [
            'total_slugs' => count($items),
            'en_index_count' => count($indexEn),
            'zh_index_count' => count($indexZh),
            'index_en_only_count' => count(array_diff($indexEn, $indexZh)),
            'index_zh_only_count' => count(array_diff($indexZh, $indexEn)),
            'same_module_set' => $classifications['same_module_set'] ?? 0,
            'en_has_modules_zh_missing' => $classifications['en_has_modules_zh_missing'] ?? 0,
            'zh_has_modules_en_missing' => $classifications['zh_has_modules_en_missing'] ?? 0,
            'en_200_zh_not_200' => $classifications['en_200_zh_not_200'] ?? 0,
            'zh_200_en_not_200' => $classifications['zh_200_en_not_200'] ?? 0,
            'both_missing_or_failed' => $classifications['both_missing_or_failed'] ?? 0,
            'api_failure_count' => count((array) ($index['failures'] ?? []))
                + ($classifications['en_200_zh_not_200'] ?? 0)
                + ($classifications['zh_200_en_not_200'] ?? 0)
                + ($classifications['both_missing_or_failed'] ?? 0),
            'classification_counts' => $classifications,
            'index_failures' => $index['failures'] ?? [],
            'samples' => $sampleByClassification,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function liveGate(array $summary, array $items): array
    {
        $restrictedShellCount = 0;
        foreach ($items as $item) {
            if ($this->hasRuntimePublishedShellReason((array) ($item['zh_gate_reasons'] ?? []))) {
                $restrictedShellCount++;
            }
        }

        $blockers = [];
        if ((int) ($summary['api_failure_count'] ?? 0) > 0) {
            $blockers[] = 'api_failures_or_http_mismatches';
        }
        if ((int) ($summary['index_en_only_count'] ?? 0) > 0 || (int) ($summary['index_zh_only_count'] ?? 0) > 0) {
            $blockers[] = 'public_index_locale_mismatch';
        }
        if ((int) ($summary['en_has_modules_zh_missing'] ?? 0) > 0) {
            $blockers[] = 'zh_missing_en_display_modules';
        }
        if ((int) ($summary['zh_has_modules_en_missing'] ?? 0) > 0) {
            $blockers[] = 'zh_has_unexpected_extra_modules';
        }
        if ($restrictedShellCount > 0) {
            $blockers[] = 'zh_restricted_shell_or_integrity_gap';
        }

        return [
            'decision' => $blockers === [] ? 'pass' : 'blocked',
            'blockers' => $blockers,
            'total_slugs' => (int) ($summary['total_slugs'] ?? 0),
            'same_module_set' => (int) ($summary['same_module_set'] ?? 0),
            'module_mismatch_count' => (int) ($summary['en_has_modules_zh_missing'] ?? 0)
                + (int) ($summary['zh_has_modules_en_missing'] ?? 0),
            'restricted_shell_count' => $restrictedShellCount,
            'api_failure_count' => (int) ($summary['api_failure_count'] ?? 0),
            'index_mismatch_count' => (int) ($summary['index_en_only_count'] ?? 0)
                + (int) ($summary['index_zh_only_count'] ?? 0),
            'sitemap_changed' => false,
            'llms_changed' => false,
            'index_strategy_changed' => false,
        ];
    }

    /**
     * @param  list<mixed>  $reasons
     */
    private function hasRuntimePublishedShellReason(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if (str_contains((string) $reason, 'runtime_published_shell')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{ok: bool, status: int|null}  $en
     * @param  array{ok: bool, status: int|null}  $zh
     * @param  list<string>  $enOnly
     * @param  list<string>  $zhOnly
     */
    private function classification(array $en, array $zh, array $enOnly, array $zhOnly): string
    {
        if (($en['ok'] ?? false) && ! ($zh['ok'] ?? false)) {
            return 'en_200_zh_not_200';
        }

        if (($zh['ok'] ?? false) && ! ($en['ok'] ?? false)) {
            return 'zh_200_en_not_200';
        }

        if (! ($en['ok'] ?? false) && ! ($zh['ok'] ?? false)) {
            return 'both_missing_or_failed';
        }

        if ($enOnly !== []) {
            return 'en_has_modules_zh_missing';
        }

        if ($zhOnly !== []) {
            return 'zh_has_modules_en_missing';
        }

        return 'same_module_set';
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    private function displayContentKeys(?array $payload): array
    {
        $content = Arr::get($payload ?? [], 'display_surface_v1.page.content');
        if (! is_array($content)) {
            return [];
        }

        $keys = array_values(array_filter(array_keys($content), static fn ($key): bool => is_string($key) && $key !== ''));
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $enOnly
     * @param  array{ok: bool, status: int|null}  $en
     * @param  array{ok: bool, status: int|null}  $zh
     * @return list<string>
     */
    private function zhGateReasons(?array $payload, array $enOnly, array $en, array $zh): array
    {
        $reasons = [];
        if (! ($zh['ok'] ?? false)) {
            $reasons[] = 'zh_detail_http_'.$this->statusLabel($zh['status'] ?? null);
        }
        if (! ($en['ok'] ?? false)) {
            $reasons[] = 'en_detail_http_'.$this->statusLabel($en['status'] ?? null);
        }
        if ($enOnly !== []) {
            $reasons[] = 'zh_missing_en_display_modules';
        }

        foreach ((array) Arr::get($payload ?? [], 'warnings.red_flags', []) as $flag) {
            $reasons[] = 'red_flag:'.$flag;
        }
        foreach ((array) Arr::get($payload ?? [], 'warnings.amber_flags', []) as $flag) {
            $reasons[] = 'amber_flag:'.$flag;
        }
        foreach ((array) Arr::get($payload ?? [], 'warnings.blocked_claims', []) as $flag) {
            $reasons[] = 'blocked_claim:'.$flag;
        }

        $integrityState = Arr::get($payload ?? [], 'integrity_summary.integrity_state');
        if (is_string($integrityState) && $integrityState !== '') {
            $reasons[] = 'integrity_state:'.$integrityState;
        }
        foreach ((array) Arr::get($payload ?? [], 'integrity_summary.critical_missing_fields', []) as $field) {
            $reasons[] = 'critical_missing_field:'.$field;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array{ok: bool, status: int|null, json: array<string, mixed>|null, url: string}
     */
    private function fetchJson(string $url, array $query, int $timeout): array
    {
        $response = Http::timeout($timeout)->acceptJson()->get($url, $query);
        $fullUrl = $this->urlWithQuery($url, $query);

        return $this->responseResult($response, $fullUrl);
    }

    /**
     * @return array{ok: bool, status: int|null, json: array<string, mixed>|null, url: string}
     */
    private function responseResult(?Response $response, string $fullUrl): array
    {
        return [
            'ok' => $response?->successful() === true,
            'status' => $response?->status(),
            'json' => $response?->successful() === true ? $this->safeJson($response) : null,
            'url' => $fullUrl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeJson(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    private function slugsFromIndex(?array $payload): array
    {
        $items = Arr::get($payload ?? [], 'items', []);
        if (! is_array($items)) {
            return [];
        }

        $slugs = [];
        foreach ($items as $item) {
            $slug = Arr::get($item, 'identity.canonical_slug');
            if (is_string($slug) && trim($slug) !== '') {
                $slugs[] = strtolower(trim($slug));
            }
        }

        return $this->sortedUnique($slugs);
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            return [];
        }

        return $this->sortedUnique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $value),
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
        sort($values);

        return $values;
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Expected a valid URL.');
        }

        return $url;
    }

    private function statusLabel(?int $status): string
    {
        return $status === null ? 'unknown' : (string) $status;
    }

    private function urlWithQuery(string $url, array $query): string
    {
        return $url.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report): int
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode zh display parity report.');
        }

        CareerCliArtifactPathGuard::writeTextOutput($this->option('output'), $json.PHP_EOL);

        if ((bool) $this->option('json')) {
            $this->output->write($json.PHP_EOL, false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->line('validator_version='.(string) ($report['validator_version'] ?? self::VALIDATOR_VERSION));
            $this->line('decision='.(string) ($report['decision'] ?? 'blocked'));
            $this->line('total_slugs='.(string) Arr::get($report, 'summary.total_slugs', 0));
            $this->line('en_has_modules_zh_missing='.(string) Arr::get($report, 'summary.en_has_modules_zh_missing', 0));
        }

        return ($report['decision'] ?? 'blocked') === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
