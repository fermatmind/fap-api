<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

final class ProviderReconciler
{
    /**
     * @param  array<string,mixed>  $backend
     * @param  array<string,array<string,mixed>>  $providers
     * @return array{status:string,reason_code:string}
     */
    public function reconcile(array $backend, array $providers): array
    {
        if (($backend['status'] ?? 'unknown') === 'stale') {
            return $this->result('stale', 'backend_global_read_model_stale');
        }

        if (! ($backend['row_exists'] ?? false)) {
            return $this->result('unknown', 'backend_global_missing');
        }

        foreach ($providers as $provider) {
            if (($provider['status'] ?? 'unknown') === 'stale') {
                return $this->result('stale', 'provider_data_through_old');
            }
        }

        if (collect($providers)->contains(fn (array $provider): bool => ($provider['status'] ?? null) === 'unconfigured')) {
            return $this->result('unconfigured', 'provider_not_configured');
        }

        $backendActivity = $backend['activity'] ?? null;
        $minimum = max(1, (int) config('analytics.provider_freshness.minimum_backend_activity', 5));
        if (! is_int($backendActivity) || $backendActivity < $minimum) {
            return $this->result('unknown', 'no_activity');
        }

        $ga = $this->providerCount($providers[GoogleAnalyticsDataAdapter::PROVIDER] ?? [], 'event_count');
        $baidu = $this->providerCount($providers[BaiduTongjiAdapter::PROVIDER] ?? [], 'page_views');

        if ($ga === null || $baidu === null) {
            return $this->result('degraded', 'provider_data_unavailable');
        }

        if ($ga === 0 && $baidu === 0) {
            return $this->result('investigate', 'backend_active_providers_zero');
        }

        if (($ga === 0 && $baidu > 0) || ($baidu === 0 && $ga > 0)) {
            return $this->result('degraded', 'provider_direction_disagrees');
        }

        return $this->result('healthy', 'directional_signals_present');
    }

    /** @param array<string,mixed> $provider */
    private function providerCount(array $provider, string $key): ?int
    {
        $value = $provider['metrics'][$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @return array{status:string,reason_code:string} */
    private function result(string $status, string $reason): array
    {
        return ['status' => $status, 'reason_code' => $reason];
    }
}
