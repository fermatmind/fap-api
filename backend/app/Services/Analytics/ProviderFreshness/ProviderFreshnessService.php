<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProviderFreshnessService
{
    public function __construct(
        private readonly GoogleAnalyticsDataAdapter $google,
        private readonly BaiduTongjiAdapter $baidu,
        private readonly ProviderSnapshotStore $store,
        private readonly ProviderReconciler $reconciler,
    ) {}

    /** @return array<string,mixed> */
    public function refresh(bool $persist = true): array
    {
        $now = $this->now();
        $target = $now->subDay()->startOfDay();
        $previous = $this->store->read();
        $providers = [];

        foreach ([
            GoogleAnalyticsDataAdapter::PROVIDER => $this->google->fetch($target),
            BaiduTongjiAdapter::PROVIDER => $this->baidu->fetch($target),
        ] as $provider => $result) {
            $providers[$provider] = $this->snapshotFromResult(
                $provider,
                $result,
                is_array($previous['providers'][$provider] ?? null) ? $previous['providers'][$provider] : [],
                $now,
            );
        }

        $backend = $this->backendGlobalSnapshot($target, $now);
        $snapshot = $this->assemble($providers, $backend, $target, $now);

        if ($persist) {
            $this->store->write($snapshot);
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $now = $this->now();
        $target = $now->subDay()->startOfDay();
        $stored = $this->store->read();
        $providers = [];

        foreach ([GoogleAnalyticsDataAdapter::PROVIDER, BaiduTongjiAdapter::PROVIDER] as $provider) {
            $candidate = is_array($stored['providers'][$provider] ?? null) ? $stored['providers'][$provider] : [];
            $providers[$provider] = $candidate === []
                ? $this->emptyProviderSnapshot($provider)
                : $this->withCalculatedStaleness($candidate, $now);
        }

        return $this->assemble($providers, $this->backendGlobalSnapshot($target, $now), $target, $now);
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $previous */
    private function snapshotFromResult(string $provider, array $result, array $previous, CarbonImmutable $now): array
    {
        if (($result['outcome'] ?? null) === 'unconfigured') {
            return $this->emptyProviderSnapshot($provider);
        }

        $lastAttempt = ($result['request_attempted'] ?? false) ? $now->toIso8601String() : ($previous['last_attempt_at'] ?? null);
        if (($result['outcome'] ?? null) === 'success') {
            $snapshot = [
                'provider' => $provider,
                'status' => 'healthy',
                'last_attempt_at' => $lastAttempt,
                'last_success_at' => $now->toIso8601String(),
                'data_through' => $result['data_through'],
                'metrics' => $result['metrics'],
                'using_lkg' => false,
                'diagnostic_code' => null,
            ];

            return $this->withCalculatedStaleness($snapshot, $now);
        }

        $hasLkg = is_array($previous['metrics'] ?? null)
            && $previous['metrics'] !== []
            && is_string($previous['last_success_at'] ?? null)
            && is_string($previous['data_through'] ?? null);

        $snapshot = [
            'provider' => $provider,
            'status' => $hasLkg ? 'degraded' : 'unknown',
            'last_attempt_at' => $lastAttempt,
            'last_success_at' => $hasLkg ? $previous['last_success_at'] : null,
            'data_through' => $hasLkg ? $previous['data_through'] : null,
            'metrics' => $hasLkg ? $previous['metrics'] : [],
            'using_lkg' => $hasLkg,
            'diagnostic_code' => (string) ($result['diagnostic_code'] ?? 'adapter_failed'),
        ];

        return $hasLkg ? $this->withCalculatedStaleness($snapshot, $now) : $snapshot;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function withCalculatedStaleness(array $snapshot, CarbonImmutable $now): array
    {
        $dataThrough = $snapshot['data_through'] ?? null;
        $lastSuccess = $snapshot['last_success_at'] ?? null;
        if (! is_string($dataThrough) || ! is_string($lastSuccess)) {
            return $snapshot;
        }

        try {
            $through = CarbonImmutable::parse($dataThrough, $this->timezone())->startOfDay();
            $success = CarbonImmutable::parse($lastSuccess, $this->timezone());
        } catch (\Throwable) {
            $snapshot['status'] = 'unknown';
            $snapshot['diagnostic_code'] = 'invalid_cached_snapshot';

            return $snapshot;
        }

        $allowedLag = max(0, (int) config('analytics.provider_freshness.allowed_provider_lag_days', 2));
        $target = $now->subDay()->startOfDay();
        if ($through->lt($target->subDays($allowedLag)) || $success->lt($now->subDays($allowedLag + 2))) {
            $snapshot['status'] = 'stale';
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function backendGlobalSnapshot(CarbonImmutable $target, CarbonImmutable $now): array
    {
        if (! SchemaBaseline::hasTable('analytics_funnel_daily')) {
            return $this->missingBackend('table_missing');
        }

        $query = DB::table('analytics_funnel_daily')
            ->where('org_id', 0)
            ->whereDate('day', $target->toDateString());

        if (! $query->exists()) {
            return $this->missingBackend('global_row_missing');
        }

        $row = $query->selectRaw('COALESCE(SUM(started_attempts), 0) AS activity')
            ->selectRaw('MAX(last_refreshed_at) AS last_refreshed_at')
            ->first();
        $lastRefreshed = is_string($row?->last_refreshed_at ?? null) ? (string) $row->last_refreshed_at : null;
        $status = 'unknown';
        if ($lastRefreshed !== null) {
            try {
                $staleHours = max(1, (int) config('analytics.provider_freshness.backend_stale_hours', 30));
                $status = CarbonImmutable::parse($lastRefreshed, $this->timezone())->lt($now->subHours($staleHours))
                    ? 'stale'
                    : 'healthy';
            } catch (\Throwable) {
                $status = 'unknown';
            }
        }

        return [
            'scope' => 'global_org0',
            'org_id' => 0,
            'status' => $status,
            'row_exists' => true,
            'data_through' => $target->toDateString(),
            'last_refreshed_at' => $lastRefreshed,
            'activity' => (int) ($row?->activity ?? 0),
            'diagnostic_code' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function missingBackend(string $code): array
    {
        return [
            'scope' => 'global_org0',
            'org_id' => 0,
            'status' => 'unknown',
            'row_exists' => false,
            'data_through' => null,
            'last_refreshed_at' => null,
            'activity' => null,
            'diagnostic_code' => $code,
        ];
    }

    /** @param array<string,array<string,mixed>> $providers @param array<string,mixed> $backend @return array<string,mixed> */
    private function assemble(array $providers, array $backend, CarbonImmutable $target, CarbonImmutable $now): array
    {
        return [
            'schema_version' => ProviderSnapshotStore::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'target_date' => $target->toDateString(),
            'timezone' => $this->timezone(),
            'backend_global' => $backend,
            'providers' => $providers,
            'reconciliation' => $this->reconciler->reconcile($backend, $providers),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyProviderSnapshot(string $provider): array
    {
        return [
            'provider' => $provider,
            'status' => 'unconfigured',
            'last_attempt_at' => null,
            'last_success_at' => null,
            'data_through' => null,
            'metrics' => [],
            'using_lkg' => false,
            'diagnostic_code' => 'unconfigured',
        ];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('analytics.provider_freshness.timezone', 'Asia/Shanghai');
    }
}
