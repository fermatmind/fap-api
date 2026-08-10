<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerJobDetailCacheCoverageService;
use App\Services\Career\CareerRuntimeSloService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Ops\OpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class CareerRuntimeSloCheck extends Command
{
    protected $signature = 'career:runtime-slo-check
        {--lightweight : Skip heavyweight frontend sitemap and llms generation routes}
        {--json : Emit JSON output}';

    protected $description = 'Probe public Career runtime surfaces, evaluate the rolling SLO, and alert without mutating authority.';

    public function handle(
        CareerRuntimeSloService $slo,
        PublicCareerAuthorityResponseCache $cache,
        CareerJobDetailCacheCoverageService $coverageService,
    ): int {
        $site = rtrim((string) config('ops.career_runtime_slo.site_url'), '/');
        $api = rtrim((string) config('ops.career_runtime_slo.api_url'), '/');
        $timeout = max(1, (int) config('ops.career_runtime_slo.timeout_seconds', 8));
        $lightweight = (bool) $this->option('lightweight');
        $responses = [];

        $targets = [
            'api_en' => $api.'/api/v0.5/career/directory?locale=en&page=1&per_page=1',
            'api_zh' => $api.'/api/v0.5/career/directory?locale=zh-CN&page=1&per_page=1',
            'page_en' => $site.'/en/career/jobs',
            'page_zh' => $site.'/zh/career/jobs',
        ];
        if (! $lightweight) {
            $targets = array_merge($targets, [
                'sitemap' => $site.'/sitemap.xml',
                'llms' => $site.'/llms.txt',
                'llms_full' => $site.'/llms-full.txt',
            ]);
        }

        foreach ($targets as $name => $url) {
            $started = hrtime(true);
            try {
                $response = Http::timeout($timeout)->get($url);
                $responses[$name] = $this->summarize($response, (hrtime(true) - $started) / 1_000_000);
            } catch (\Throwable $throwable) {
                $responses[$name] = ['status' => 0, 'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3), 'body' => '', 'error' => $throwable::class];
            }
        }

        $enCount = (int) data_get(json_decode((string) ($responses['api_en']['body'] ?? ''), true), 'public_truth.public_detail_indexable_count', 0);
        $zhCount = (int) data_get(json_decode((string) ($responses['api_zh']['body'] ?? ''), true), 'public_truth.public_detail_indexable_count', 0);
        $minimumDetailTargets = max(1, (int) config('ops.career_runtime_slo.minimum_detail_target_count', 2092));
        $detailCoverage = [
            'contract_version' => 'career.job_detail_cache_coverage.v1',
            'status' => 'not_sampled',
            'reason' => 'lightweight_runtime_probe',
        ];
        if (! $lightweight) {
            $detailInspection = $coverageService->inspect(['en', 'zh-CN']);
            $detailCoverage = $detailInspection['report'];
            $detailSmokeTarget = collect($detailInspection['rows'])->first(
                static fn (array $row): bool => $row['locale'] === 'en'
                    && $row['classification'] !== 'held_or_unpublished_excluded'
            );
            if (is_array($detailSmokeTarget)) {
                $started = hrtime(true);
                try {
                    $detail = Http::timeout($timeout)->get(
                        $api.'/api/v0.5/career/jobs/'.$detailSmokeTarget['slug'],
                        ['locale' => 'en'],
                    );
                    $responses['detail_route_smoke'] = $this->summarize($detail, (hrtime(true) - $started) / 1_000_000);
                } catch (\Throwable $throwable) {
                    $responses['detail_route_smoke'] = [
                        'status' => 0,
                        'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
                        'body' => '',
                        'error' => $throwable::class,
                    ];
                }
            }
        }

        $requiredBodies = $lightweight ? [] : ['sitemap', 'llms', 'llms_full'];
        $smokeFailed = collect($responses)->contains(static fn (array $response): bool => (int) ($response['status'] ?? 0) !== 200)
            || collect($requiredBodies)->contains(fn (string $key): bool => ! str_contains((string) ($responses[$key]['body'] ?? ''), '/career/jobs'));
        $falseEmpty = $enCount > 0 && (
            $this->pageReportsZeroAuthorityCount((string) ($responses['page_en']['body'] ?? ''), 'All occupations')
            || $this->pageReportsZeroAuthorityCount((string) ($responses['page_zh']['body'] ?? ''), '全部职业')
        );
        $cacheStatuses = [$cache->directoryCacheStatus('en'), $cache->directoryCacheStatus('zh-CN')];
        $cacheStale = collect($cacheStatuses)->contains(static fn (array $status): bool => ($status['status'] ?? null) !== 'ready'
            || ! is_numeric($status['age_seconds'] ?? null)
            || (int) $status['age_seconds'] > PublicCareerAuthorityResponseCache::DIRECTORY_CACHE_MAX_AGE_SECONDS
        );
        $lastRebuildMs = (float) collect($cacheStatuses)->max(static fn (array $status): float => (float) ($status['last_rebuild_ms'] ?? 0));
        $evaluationSnapshot = [
            'false_empty' => $falseEmpty,
            'locale_count_mismatch' => $enCount !== $zhCount,
            'cache_stale' => $cacheStale,
            'last_rebuild_ms' => $lastRebuildMs,
            'smoke_failed' => $smokeFailed,
        ];
        if (! $lightweight) {
            $evaluationSnapshot = array_merge($evaluationSnapshot, [
                'detail_cache_missing_count' => (int) ($detailCoverage['missing_count'] ?? 0),
                'detail_cache_broken_count' => (int) ($detailCoverage['broken_count'] ?? 0),
                'detail_cache_target_count' => (int) ($detailCoverage['eligible_target_count'] ?? 0),
                'minimum_detail_target_count' => $minimumDetailTargets,
            ]);
        }
        $evaluation = $slo->evaluate($evaluationSnapshot);

        $probes = collect($responses)->map(static fn (array $response): array => [
            'status' => (int) ($response['status'] ?? 0),
            'duration_ms' => (float) ($response['duration_ms'] ?? 0),
            'response_bytes' => strlen((string) ($response['body'] ?? '')),
            'error' => $response['error'] ?? null,
        ])->all();
        $report = [
            'status' => $evaluation['status'],
            'probe_mode' => $lightweight ? 'lightweight' : 'full',
            'counts' => ['en' => $enCount, 'zh-CN' => $zhCount],
            'cache' => $cacheStatuses,
            'detail_cache_coverage' => $detailCoverage,
            'probes' => $probes,
            'slo' => $evaluation,
        ];
        if ($evaluation['alerts'] !== []) {
            OpsAlertService::send('[Career runtime SLO alert] '.implode(', ', $evaluation['alerts']));
        }
        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $evaluation['alerts'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{status:int,duration_ms:float,body:string} */
    private function summarize(Response $response, float $durationMs): array
    {
        return ['status' => $response->status(), 'duration_ms' => round($durationMs, 3), 'body' => $response->body()];
    }

    private function pageReportsZeroAuthorityCount(string $html, string $label): bool
    {
        if (! str_contains($html, 'career-library-summary')) {
            return false;
        }

        return preg_match('/'.preg_quote($label, '/').'.{0,500}>\s*0\s*</su', $html) === 1;
    }
}
