<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class CoreEntrySloObserver
{
    public const SCHEMA_VERSION = 'seo-core-entry-slo-observability.v1';

    public const TASK = 'SEO-CORE-ENTRY-SLO-OBSERVABILITY-01';

    public function __construct(
        private readonly CoreEntrySloManifest $manifest,
        private readonly CoreEntrySloInspector $inspector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function observe(?int $requestedConcurrency = null, ?int $requestedTimeoutSeconds = null): array
    {
        $manifest = $this->manifest->resolve();
        $targets = $manifest['targets'];
        $concurrency = $this->concurrency($requestedConcurrency);
        $timeoutSeconds = $this->timeoutSeconds($requestedTimeoutSeconds);
        $publicBaseUrl = $manifest['public_base_url'];

        $responses = Http::pool(function (Pool $pool) use ($targets, $publicBaseUrl, $timeoutSeconds): void {
            foreach ($targets as $target) {
                $pool->as((string) $target['id'])
                    ->accept('text/html')
                    ->withUserAgent('FermatMind-SEO-Core-Entry-SLO/1.0')
                    ->connectTimeout(min(5, $timeoutSeconds))
                    ->timeout($timeoutSeconds)
                    ->withOptions(['allow_redirects' => false])
                    ->get($publicBaseUrl.(string) $target['path']);
            }
        }, $concurrency);

        $results = [];
        foreach ($targets as $target) {
            $response = $responses[(string) $target['id']] ?? null;

            if (! $response instanceof Response) {
                $results[] = $this->inspector->transportFailure($target);

                continue;
            }

            [$ttfbMs, $ttfbSource] = $this->ttfb($response);
            $results[] = $this->inspector->inspect(
                $target,
                $publicBaseUrl,
                $response->status(),
                $response->headers(),
                $response->body(),
                $ttfbMs,
                $ttfbSource,
            );
        }

        $opsReadModel = $this->opsReadModel($results, $manifest['tier_order']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => 'completed',
            'read_only' => true,
            'slo_met' => $opsReadModel['overall_status'] === 'healthy',
            'observed_at_utc' => now('UTC')->toIso8601String(),
            'manifest' => [
                'sha256' => $manifest['manifest_sha256'],
                'target_count' => count($targets),
                'tier_order' => $manifest['tier_order'],
                'public_host_sha256' => hash('sha256', (string) parse_url($publicBaseUrl, PHP_URL_HOST)),
                'private_url_count' => 0,
            ],
            'execution' => [
                'bounded_concurrency' => true,
                'concurrency' => $concurrency,
                'timeout_seconds' => $timeoutSeconds,
                'redirects_followed' => false,
                'request_method' => 'GET',
                'request_count' => count($targets),
            ],
            'results' => $results,
            'ops_read_model' => $opsReadModel,
            'privacy' => [
                'sanitized' => true,
                'public_paths_only' => true,
                'query_strings_stored' => false,
                'response_body_stored' => false,
                'response_headers_stored' => false,
                'cookies_or_auth_sent' => false,
                'secrets_stored' => false,
                'private_urls_excluded' => true,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array{0:?float, 1:string}
     */
    private function ttfb(Response $response): array
    {
        $stats = $response->handlerStats();
        $startTransfer = $stats['starttransfer_time'] ?? null;

        if (is_numeric($startTransfer) && (float) $startTransfer >= 0) {
            return [(float) $startTransfer * 1000, 'handler_stats_starttransfer'];
        }

        $serverTiming = $response->header('Server-Timing');
        if (preg_match('/(?:^|,)\s*ttfb\s*;\s*dur=([0-9]+(?:\.[0-9]+)?)/i', $serverTiming, $matches) === 1) {
            return [(float) $matches[1], 'server_timing_ttfb'];
        }

        $totalTime = $stats['total_time'] ?? null;
        if (is_numeric($totalTime) && (float) $totalTime >= 0) {
            return [(float) $totalTime * 1000, 'handler_stats_total_fallback'];
        }

        return [null, 'unavailable'];
    }

    private function concurrency(?int $requested): int
    {
        $default = max(1, (int) config('seo_intel.core_entry_slo.default_concurrency', 4));
        $maximum = max(1, (int) config('seo_intel.core_entry_slo.max_concurrency', 4));

        return max(1, min($requested ?? $default, $maximum));
    }

    private function timeoutSeconds(?int $requested): int
    {
        $default = max(1, (int) config('seo_intel.core_entry_slo.default_timeout_seconds', 10));
        $maximum = max(1, (int) config('seo_intel.core_entry_slo.max_timeout_seconds', 15));

        return max(1, min($requested ?? $default, $maximum));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $tierOrder
     * @return array<string, mixed>
     */
    private function opsReadModel(array $results, array $tierOrder): array
    {
        $tiers = [];
        $allCategories = [];
        $deliveryModes = [];
        $highestPriorityIncidentTier = null;

        foreach ($tierOrder as $tier) {
            $tierResults = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['tier'] ?? null) === $tier
            ));
            $incidentResults = array_values(array_filter(
                $tierResults,
                static fn (array $result): bool => ($result['status'] ?? null) === 'incident'
            ));
            $categoryCounts = [];
            $ttfbValues = [];

            foreach ($tierResults as $result) {
                foreach ((array) ($result['incident_categories'] ?? []) as $category) {
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                    $allCategories[$category] = ($allCategories[$category] ?? 0) + 1;
                }

                $deliveryMode = (string) data_get($result, 'dependency_state.delivery_mode', 'unknown');
                $deliveryModes[$deliveryMode] = ($deliveryModes[$deliveryMode] ?? 0) + 1;

                $ttfb = data_get($result, 'ttfb.milliseconds');
                if (is_numeric($ttfb)) {
                    $ttfbValues[] = (float) $ttfb;
                }
            }

            ksort($categoryCounts);
            $status = $incidentResults === [] ? 'healthy' : match ($tier) {
                'L1' => 'critical',
                'L2' => 'high',
                default => 'degraded',
            };

            if ($incidentResults !== [] && $highestPriorityIncidentTier === null) {
                $highestPriorityIncidentTier = $tier;
            }

            $tiers[$tier] = [
                'status' => $status,
                'target_count' => count($tierResults),
                'healthy_count' => count($tierResults) - count($incidentResults),
                'incident_count' => count($incidentResults),
                'max_ttfb_ms' => $ttfbValues === [] ? null : round(max($ttfbValues), 2),
                'incident_category_counts' => $categoryCounts,
            ];
        }

        ksort($allCategories);
        ksort($deliveryModes);

        $overallStatus = match ($highestPriorityIncidentTier) {
            'L1' => 'critical',
            'L2' => 'high',
            'L3' => 'degraded',
            default => 'healthy',
        };

        return [
            'overall_status' => $overallStatus,
            'alert_priority' => $highestPriorityIncidentTier,
            'priority_order' => $tierOrder,
            'target_count' => count($results),
            'healthy_count' => count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'healthy'
            )),
            'incident_count' => count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'incident'
            )),
            'tiers' => $tiers,
            'incident_category_counts' => $allCategories,
            'delivery_mode_counts' => $deliveryModes,
        ];
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
            'sitemap_write' => false,
            'llms_write' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'private_url_probe' => false,
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
        ];
    }
}
