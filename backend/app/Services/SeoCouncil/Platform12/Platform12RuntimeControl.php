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

    public const PAUSE_MANUAL = 'manual';

    public const PAUSE_ACCEPTANCE = 'acceptance_protection';

    public const PAUSE_LEGACY = 'historical_unknown';

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
            $state = $this->normalizedState($this->store()->get(self::CACHE_KEY));
            $acceptance = ($state['paused'] ?? false) === true
                && ($state['pause_source'] ?? null) === self::PAUSE_ACCEPTANCE;
            $reason = $this->reason($prerequisite, $state, $acceptance);
            if ($reason === 'ACTIVE_READ_ONLY'
                && ($state['catalog_hash'] ?? null) !== $this->contracts->missionCatalog()['catalog_hash']) {
                $reason = 'CATALOG_DRIFT_HOLD';
            }

            return [
                'state' => $reason,
                'runtime_phase' => in_array($prerequisite, ['READY', 'CONTROLLED_ACCEPTANCE_ONLY'], true)
                    ? ($acceptance || $prerequisite === 'CONTROLLED_ACCEPTANCE_ONLY'
                        ? 'CONTROLLED_ACCEPTANCE_ONLY' : 'ACTIVE_READ_ONLY')
                    : 'DISABLED',
                'computation_enabled' => $reason === 'ACTIVE_READ_ONLY',
                'controlled_acceptance_enabled' => $acceptance
                    && in_array($prerequisite, ['READY', 'CONTROLLED_ACCEPTANCE_ONLY'], true),
                'audit_enabled' => in_array($prerequisite, ['READY', 'CONTROLLED_ACCEPTANCE_ONLY'], true),
                'business_write_enabled' => false,
                'activated_at' => $state['activated_at'] ?? null,
                'pause_intent' => $this->pauseIntent($state),
                'pause_source' => $state['pause_source'] ?? null,
                'pause_reason' => $state['pause_reason'] ?? null,
                'changed_at' => $state['changed_at'] ?? null,
                'operation_ref' => $state['operation_ref'] ?? null,
                'generation' => $state['generation'] ?? null,
                'historical_pause_adopted' => (bool) ($state['historical_pause_adopted'] ?? false),
                'catalog_hash' => $state['catalog_hash'] ?? null,
                'activation_source_sha' => data_get($activation, 'manifest.compatibility.source_sha'),
                'activation_bound_sha' => data_get($activation, 'manifest.bound_production_sha'),
                'activation_basis' => data_get($activation, 'manifest.activation_basis'),
                'notification_configuration_verified' => $this->notificationConfigurationVerified(),
                'version_vector' => $capability['version_vector'],
                'version_vector_hash' => $capability['version_vector_hash'],
            ];
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'runtime_phase' => 'DISABLED',
                'computation_enabled' => false, 'controlled_acceptance_enabled' => false,
                'audit_enabled' => false, 'business_write_enabled' => false,
                'activated_at' => null, 'pause_intent' => 'UNSET', 'pause_source' => null,
                'pause_reason' => null, 'changed_at' => null, 'operation_ref' => null,
                'generation' => null, 'historical_pause_adopted' => false,
                'catalog_hash' => null, 'activation_source_sha' => null,
                'activation_bound_sha' => null, 'activation_basis' => null,
                'notification_configuration_verified' => false,
                'version_vector' => null, 'version_vector_hash' => null];
        }
    }

    /** Explicit operator pause/resume. */
    public function change(bool $pause): array
    {
        try {
            if (! $pause && $this->prerequisite() !== 'READY') {
                return $this->status();
            }
            $store = $this->store();
            $changed = $store->lock(self::CACHE_KEY.':lock', 5)->get(function () use ($store, $pause): bool {
                $old = $this->normalizedState($store->get(self::CACHE_KEY));
                if (! $pause && $this->prerequisite() !== 'READY') {
                    return false;
                }

                return $store->forever(self::CACHE_KEY, $this->statePayload(
                    paused: $pause,
                    old: $old,
                    pauseSource: $pause ? self::PAUSE_MANUAL : null,
                    pauseReason: $pause ? 'OPERATOR_PAUSE' : null,
                    operationRef: $pause ? 'operator:seo-council-runtime' : null,
                ));
            });

            return $changed === true ? $this->status()
                : ['state' => 'CONTROL_WRITE_HOLD', 'computation_enabled' => false, 'business_write_enabled' => false];
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'computation_enabled' => false, 'business_write_enabled' => false];
        }
    }

    public function beginControlledAcceptance(string $operationRef, bool $adoptHistoricalPause = false): array
    {
        if (! $this->validOperationRef($operationRef)
            || ($adoptHistoricalPause && ! app()->environment(['staging', 'testing']))
            || ! in_array($this->prerequisite(), ['READY', 'CONTROLLED_ACCEPTANCE_ONLY'], true)) {
            return $this->status();
        }
        try {
            $store = $this->store();
            $store->lock(self::CACHE_KEY.':lock', 5)->get(function () use ($store, $operationRef, $adoptHistoricalPause): void {
                $old = $this->normalizedState($store->get(self::CACHE_KEY));
                $pauseSource = $old['pause_source'] ?? null;
                if ($pauseSource === self::PAUSE_MANUAL
                    || ($pauseSource === self::PAUSE_LEGACY && ! $adoptHistoricalPause)) {
                    return;
                }
                if ($pauseSource === self::PAUSE_ACCEPTANCE
                    && ($old['operation_ref'] ?? null) === $operationRef) {
                    return;
                }
                $restore = $pauseSource === self::PAUSE_ACCEPTANCE
                    ? ($old['acceptance_restore'] ?? ['paused' => false, 'pause_source' => null, 'pause_reason' => null])
                    : ['paused' => $pauseSource === self::PAUSE_LEGACY ? false : (bool) ($old['paused'] ?? false),
                        'pause_source' => $pauseSource === self::PAUSE_LEGACY ? null : $pauseSource,
                        'pause_reason' => $pauseSource === self::PAUSE_LEGACY ? null : ($old['pause_reason'] ?? null)];
                $payload = $this->statePayload(true, $old, self::PAUSE_ACCEPTANCE,
                    'CONTROLLED_ACCEPTANCE_IN_PROGRESS', $operationRef);
                $payload['acceptance_restore'] = $restore;
                $payload['historical_pause_adopted'] = $pauseSource === self::PAUSE_LEGACY;
                $store->forever(self::CACHE_KEY, $payload);
            });

            return $this->status();
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'controlled_acceptance_enabled' => false,
                'business_write_enabled' => false];
        }
    }

    public function finishControlledAcceptance(string $operationRef, string $expectedGeneration, bool $success): array
    {
        if (! $this->validOperationRef($operationRef) || preg_match('/^[a-f0-9]{32}$/D', $expectedGeneration) !== 1) {
            return $this->status();
        }
        try {
            $store = $this->store();
            $store->lock(self::CACHE_KEY.':lock', 5)->get(function () use ($store, $operationRef, $expectedGeneration, $success): void {
                $old = $this->normalizedState($store->get(self::CACHE_KEY));
                if (($old['pause_source'] ?? null) !== self::PAUSE_ACCEPTANCE
                    || ($old['operation_ref'] ?? null) !== $operationRef
                    || ! hash_equals((string) ($old['generation'] ?? ''), $expectedGeneration)) {
                    return;
                }
                if (! $success) {
                    $store->forever(self::CACHE_KEY, $this->statePayload(true, $old, self::PAUSE_ACCEPTANCE,
                        'CONTROLLED_ACCEPTANCE_FAILED', $operationRef));

                    return;
                }
                if ($this->prerequisite() !== 'READY') {
                    return;
                }
                $restore = is_array($old['acceptance_restore'] ?? null) ? $old['acceptance_restore'] : [];
                $store->forever(self::CACHE_KEY, $this->statePayload(
                    paused: (bool) ($restore['paused'] ?? false),
                    old: $old,
                    pauseSource: is_string($restore['pause_source'] ?? null) ? $restore['pause_source'] : null,
                    pauseReason: is_string($restore['pause_reason'] ?? null) ? $restore['pause_reason'] : null,
                    operationRef: null,
                ));
            });

            return $this->status();
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
        $acceptance = str_contains($id, ':acceptance:');
        $status = $this->status();

        return $caller === 'scheduler' && $matched
            && ($request['mission_type'] ?? null) === 'bounded_review'
            && ($request['autonomy'] ?? null) === 'L0'
            && ($request['family'] ?? null) === 'other_public'
            && ($request['locale'] ?? null) === 'zh-CN'
            && ($request['tool_scope'] ?? null) === [] && ($request['egress_scope'] ?? null) === []
            && ($request['requested_role'] ?? null) === null
            && ($acceptance ? ($status['controlled_acceptance_enabled'] ?? false) : $status['computation_enabled']);
    }

    public function frozenVersionVector(): array
    {
        $state = $this->normalizedState($this->store()->get(self::CACHE_KEY));

        return is_array($state['version_vector'] ?? null) ? $state['version_vector'] : [];
    }

    public function frozenQueryKeyVersion(): ?string
    {
        $state = $this->normalizedState($this->store()->get(self::CACHE_KEY));
        $version = $state['query_key_version'] ?? null;

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

    private function reason(string $prerequisite, array $state, bool $acceptance): string
    {
        if (! in_array($prerequisite, ['READY', 'CONTROLLED_ACCEPTANCE_ONLY'], true)) {
            return $prerequisite;
        }
        if (($state['pause_source'] ?? null) === self::PAUSE_MANUAL) {
            return 'MANUAL_PAUSE_HOLD';
        }
        if (($state['pause_source'] ?? null) === self::PAUSE_LEGACY) {
            return 'HISTORICAL_PAUSE_UNKNOWN_HOLD';
        }
        if ($acceptance) {
            return 'CONTROLLED_ACCEPTANCE_ONLY';
        }
        if ($prerequisite === 'CONTROLLED_ACCEPTANCE_ONLY') {
            return 'CONTROLLED_ACCEPTANCE_PREPARATION_HOLD';
        }

        return ($state['paused'] ?? true) ? 'PAUSED' : 'ACTIVE_READ_ONLY';
    }

    private function pauseIntent(array $state): string
    {
        if ($state === []) {
            return 'UNSET';
        }
        if (($state['pause_source'] ?? null) === self::PAUSE_LEGACY) {
            return 'UNKNOWN';
        }

        return ($state['paused'] ?? true) ? 'PAUSED' : 'RUNNING';
    }

    private function normalizedState(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        if (! array_key_exists('pause_source', $value)) {
            $value['pause_source'] = ($value['paused'] ?? true) ? self::PAUSE_LEGACY : null;
            $value['pause_reason'] = ($value['paused'] ?? true) ? 'LEGACY_BOOLEAN_STATE' : 'LEGACY_RUNNING_STATE_MIGRATED';
            $value['changed_at'] = null;
            $value['operation_ref'] = null;
        }

        return $value;
    }

    private function statePayload(
        bool $paused,
        array $old,
        ?string $pauseSource,
        ?string $pauseReason,
        ?string $operationRef,
    ): array {
        return [
            'paused' => $paused,
            'pause_source' => $pauseSource,
            'pause_reason' => $pauseReason,
            'changed_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'operation_ref' => $operationRef,
            'activated_at' => isset($old['activated_at']) ? $old['activated_at']
                : ($paused ? null : now('UTC')->format('Y-m-d\TH:i:s\Z')),
            'catalog_hash' => $this->contracts->missionCatalog()['catalog_hash'],
            'version_vector' => app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'],
            'query_key_version' => config('seo_agent_evidence.query_hmac_key_version'),
            'generation' => bin2hex(random_bytes(16)),
        ];
    }

    private function validOperationRef(string $operationRef): bool
    {
        return preg_match('/^deploy:[1-9][0-9]{0,19}:[1-9][0-9]{0,4}:[a-f0-9]{40}$/D', $operationRef) === 1;
    }

    private function notificationConfigurationVerified(): bool
    {
        $url = trim((string) config('ops.alert.webhook', ''));
        $parts = $url === '' ? false : parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
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
