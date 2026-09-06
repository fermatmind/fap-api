<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Council-only switch. Never confers business action or provider authority. */
final class Platform12RuntimeControl
{
    public const CACHE_KEY = 'seo:council:platform12:runtime:v1';

    public function __construct(
        private readonly Platform12ContractRegistry $contracts,
        private readonly PolicyGatewayRegistry $policy,
        private readonly Platform12ActivationEvidence $activation,
    ) {}

    public function prerequisite(): string
    {
        if (! config('seo_council.scheduler_enabled', false)
            || ! config('seo_council.daily_read_only_enabled', false)) {
            return 'DISABLED';
        }
        if (! $this->businessGuardsClosed()) {
            return 'WRITE_GUARD_HOLD';
        }
        if (! app()->environment('production')) {
            return app()->environment(['staging', 'testing']) ? 'READY' : 'ENVIRONMENT_HOLD';
        }

        return $this->activation->inspect()['state'];
    }

    public function status(): array
    {
        try {
            $prerequisite = $this->prerequisite();
            $activation = $this->activation->inspect();
            $capability = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
            $store = $this->store();
            $value = $store->get(self::CACHE_KEY);
            $state = is_array($value) ? $value : [];
            $reason = $prerequisite !== 'READY' ? $prerequisite
                : (($state['paused'] ?? true) ? 'PAUSED' : 'ACTIVE_READ_ONLY');
            if ($reason === 'ACTIVE_READ_ONLY'
                && ($state['catalog_hash'] ?? null) !== $this->contracts->missionCatalog()['catalog_hash']) {
                $reason = 'CATALOG_DRIFT_HOLD';
            }

            return [
                'state' => $reason, 'computation_enabled' => $reason === 'ACTIVE_READ_ONLY',
                'audit_enabled' => $prerequisite === 'READY', 'business_write_enabled' => false,
                'activated_at' => $state['activated_at'] ?? null,
                'pause_intent' => $state === [] ? 'UNSET' : (($state['paused'] ?? true) ? 'PAUSED' : 'RUNNING'),
                'generation' => $state['generation'] ?? null,
                'catalog_hash' => $state['catalog_hash'] ?? null,
                'activation_source_sha' => data_get($activation, 'manifest.validation.nightly.sha'),
                'activation_bound_sha' => data_get($activation, 'manifest.bound_production_sha'),
                'version_vector' => $capability['version_vector'],
                'version_vector_hash' => $capability['version_vector_hash'],
            ];
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'computation_enabled' => false,
                'audit_enabled' => false, 'business_write_enabled' => false,
                'activated_at' => null, 'pause_intent' => 'UNSET', 'generation' => null, 'catalog_hash' => null,
                'activation_source_sha' => null, 'activation_bound_sha' => null,
                'version_vector' => null, 'version_vector_hash' => null];
        }
    }

    public function change(bool $pause): array
    {
        try {
            if (! $pause && $this->prerequisite() !== 'READY') {
                return $this->status();
            }
            $store = $this->store();
            $changed = $store->lock(self::CACHE_KEY.':lock', 5)->get(function () use ($store, $pause): bool {
                $old = $store->get(self::CACHE_KEY);
                if (! $pause && $this->prerequisite() !== 'READY') {
                    return false;
                }

                return $store->forever(self::CACHE_KEY, [
                    'paused' => $pause,
                    'activated_at' => is_array($old) && isset($old['activated_at'])
                        ? $old['activated_at'] : ($pause ? null : now('UTC')->format('Y-m-d\TH:i:s\Z')),
                    'catalog_hash' => $this->contracts->missionCatalog()['catalog_hash'],
                    'version_vector' => app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'],
                    'query_key_version' => config('seo_agent_evidence.query_hmac_key_version'),
                    'generation' => bin2hex(random_bytes(16)),
                ]);
            });

            return $changed === true ? $this->status()
                : ['state' => 'CONTROL_WRITE_HOLD', 'computation_enabled' => false, 'business_write_enabled' => false];
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'computation_enabled' => false, 'business_write_enabled' => false];
        }
    }

    public function admits(string $caller, array $request): bool
    {
        $id = (string) ($request['mission_id'] ?? '');
        $matched = false;
        foreach (array_keys(Platform12DailyMissionSet::IDS) as $index) {
            $matched = $matched || preg_match('/^seo\.platform12\.daily_check_'.$index.':\d{4}-\d{2}-\d{2}(?::acceptance:[a-f0-9]{12})?$/D', $id) === 1;
        }

        return $caller === 'scheduler' && $matched
            && ($request['mission_type'] ?? null) === 'bounded_review'
            && ($request['autonomy'] ?? null) === 'L0'
            && ($request['family'] ?? null) === 'other_public'
            && ($request['locale'] ?? null) === 'zh-CN'
            && ($request['tool_scope'] ?? null) === [] && ($request['egress_scope'] ?? null) === []
            && ($request['requested_role'] ?? null) === null
            && $this->status()['computation_enabled'];
    }

    public function frozenVersionVector(): array
    {
        $state = $this->store()->get(self::CACHE_KEY);

        return is_array($state) && is_array($state['version_vector'] ?? null) ? $state['version_vector'] : [];
    }

    public function frozenQueryKeyVersion(): ?string
    {
        $state = $this->store()->get(self::CACHE_KEY);
        $version = is_array($state) ? ($state['query_key_version'] ?? null) : null;

        return is_string($version) && preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/D', $version) === 1 ? $version : null;
    }

    public function businessGuardsClosed(): bool
    {
        $controls = $this->policy->runtimeControls();

        return ($controls['post12_agent_write_enabled'] ?? null) === false
            && ($controls['global_write_gate'] ?? null) === false
            && ! config('seo_council.model_runtime_enabled', false)
            && ! config('seo_council.tool_broker_enabled', false)
            && $this->policy->dependencyStatus() === 'READY';
    }

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        $name = (string) config('seo_council.runtime_cache_store', config('cache.default'));
        $driver = config('cache.stores.'.$name.'.driver');
        if (! app()->environment('testing') && ! in_array($driver, ['redis', 'database'], true)) {
            throw new \RuntimeException('SHARED_CACHE_REQUIRED');
        }

        return Cache::store($name);
    }
}
