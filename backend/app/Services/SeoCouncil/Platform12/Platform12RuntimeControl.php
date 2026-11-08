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

        return 'READY';
    }

    public function status(): array
    {
        try {
            $prerequisite = $this->prerequisite();
            $capability = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
            $value = $this->store()->get(self::CACHE_KEY);
            $state = is_array($value) ? $value : [];
            $selected = $this->selection($state['selected_missions'] ?? []);
            $paused = ($state['paused'] ?? true) !== false;
            $generationValid = is_string($state['generation'] ?? null)
                && preg_match('/^[a-f0-9]{32}$/D', $state['generation']) === 1;
            $catalogValid = ($state['catalog_hash'] ?? null) === $this->contracts->missionCatalog()['catalog_hash'];
            $naturalEnvironment = app()->environment(['production', 'testing']);
            $reason = match (true) {
                $paused => 'PAUSED',
                $prerequisite !== 'READY' => $prerequisite,
                ! $naturalEnvironment => 'ENVIRONMENT_HOLD',
                $selected === [] => 'MISSION_SELECTION_EMPTY',
                ! $generationValid => 'RUNTIME_GENERATION_HOLD',
                ! $catalogValid => 'CATALOG_DRIFT_HOLD',
                default => 'ACTIVE_READ_ONLY',
            };
            $missionStates = [];
            $effective = [];
            foreach (Platform12DailyMissionSet::IDS as $id) {
                $chosen = in_array($id, $selected, true);
                $first = $state['mission_activated_at'][$id] ?? null;
                $firstTimestamp = is_string($first) ? strtotime($first) : false;
                $firstValid = $firstTimestamp !== false && $firstTimestamp <= now('UTC')->getTimestamp();
                $allowed = $chosen && $reason === 'ACTIVE_READ_ONLY' && $firstValid;
                if ($allowed) {
                    $effective[] = $id;
                }
                $missionStates[$id] = ['selected' => $chosen, 'first_enabled_at' => $first,
                    'run_allowed' => $allowed,
                    'reason' => ! $chosen ? 'MISSION_NOT_SELECTED'
                        : ($reason !== 'ACTIVE_READ_ONLY' ? $reason : ($firstValid ? 'ACTIVE_READ_ONLY' : 'FIRST_ENABLE_HOLD'))];
            }
            if ($reason === 'ACTIVE_READ_ONLY' && $effective === []) {
                $reason = 'FIRST_ENABLE_HOLD';
            }

            return [
                'state' => $reason, 'computation_enabled' => $reason === 'ACTIVE_READ_ONLY',
                'audit_enabled' => $prerequisite === 'READY', 'business_write_enabled' => false,
                'public_gate' => $prerequisite,
                'selected_missions' => $selected, 'effective_mission_ids' => $effective, 'effective_enabled_missions' => count($effective),
                'missions' => $missionStates,
                'model_runtime_enabled' => false, 'tool_broker_enabled' => false,
                'post12_agent_write_enabled' => false, '28_day_clock' => 'NOT_STARTED',
                'activated_at' => $state['activated_at'] ?? null,
                'pause_intent' => $state === [] ? 'UNSET' : ($paused ? 'PAUSED' : 'RUNNING'),
                'generation' => $state['generation'] ?? null,
                'catalog_hash' => $state['catalog_hash'] ?? null,
                'version_vector' => $capability['version_vector'],
                'version_vector_hash' => $capability['version_vector_hash'],
            ];
        } catch (Throwable) {
            return ['state' => 'SHARED_CACHE_HOLD', 'computation_enabled' => false,
                'audit_enabled' => false, 'business_write_enabled' => false,
                'public_gate' => 'SHARED_CACHE_HOLD', 'selected_missions' => [], 'effective_mission_ids' => [], 'effective_enabled_missions' => 0,
                'missions' => [],
                'activated_at' => null, 'pause_intent' => 'UNSET', 'generation' => null, 'catalog_hash' => null,
                'version_vector' => null, 'version_vector_hash' => null];
        }
    }

    public function change(bool $pause, array $missions = [], ?string $expectedGeneration = null): array
    {
        if (! $pause && ($missions === [] || $this->selection($missions) === [])) {
            return ['state' => 'MISSION_SELECTION_DENIED', 'computation_enabled' => false, 'business_write_enabled' => false];
        }
        try {
            if (! $pause && $this->prerequisite() !== 'READY') {
                return $this->status();
            }
            $store = $this->store();
            $transition = function () use ($store, $pause, $missions, $expectedGeneration): bool {
                $old = $store->get(self::CACHE_KEY);
                if (! $pause && $this->prerequisite() !== 'READY') {
                    return false;
                }

                $old = is_array($old) ? $old : [];
                if ($expectedGeneration !== null && ($old['generation'] ?? null) !== $expectedGeneration) {
                    return false;
                }
                $first = is_array($old['mission_activated_at'] ?? null) ? $old['mission_activated_at'] : [];
                if (! $pause) {
                    foreach ($missions as $id) {
                        $first[$id] ??= now('UTC')->format('Y-m-d\TH:i:s\Z');
                    }
                }

                return $store->forever(self::CACHE_KEY, [...$old,
                    'selected_missions' => $pause ? $this->selection($old['selected_missions'] ?? []) : $missions,
                    'mission_activated_at' => $first,
                    'paused' => $pause,
                    'activated_at' => is_array($old) && isset($old['activated_at'])
                        ? $old['activated_at'] : ($pause ? null : now('UTC')->format('Y-m-d\TH:i:s\Z')),
                    'catalog_hash' => $pause ? ($old['catalog_hash'] ?? null) : $this->contracts->missionCatalog()['catalog_hash'],
                    'version_vector' => $pause ? ($old['version_vector'] ?? []) : app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'],
                    'query_key_version' => $pause ? ($old['query_key_version'] ?? null) : config('seo_agent_evidence.query_hmac_key_version'),
                    'generation' => bin2hex(random_bytes(16)),
                ]);
            };
            // Generation-bound callers may wait for a natural tick to finish.
            // All paths still validate the expected generation after acquiring it.
            $lock = $store->lock(self::CACHE_KEY.':lock', $expectedGeneration === null ? 5 : 60);
            $changed = $expectedGeneration === null ? $lock->get($transition) : $lock->block(5, $transition);

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
        $canonical = null;
        foreach (array_keys(Platform12DailyMissionSet::IDS) as $index) {
            $matches = preg_match('/^seo\.platform12\.daily_check_'.$index.':\d{4}-\d{2}-\d{2}$/D', $id) === 1;
            if ($matches) {
                $matched = true;
                $canonical = Platform12DailyMissionSet::IDS[$index];
            }
        }

        return $caller === 'scheduler' && $matched
            && ($request['mission_type'] ?? null) === 'bounded_review'
            && ($request['autonomy'] ?? null) === 'L0'
            && ($request['family'] ?? null) === 'other_public'
            && ($request['locale'] ?? null) === 'zh-CN'
            && ($request['tool_scope'] ?? null) === [] && ($request['egress_scope'] ?? null) === []
            && ($request['requested_role'] ?? null) === null
            && $canonical !== null && $this->allowsMission($canonical);
    }

    public function withControlLock(callable $callback): mixed
    {
        return $this->store()->lock(self::CACHE_KEY.':lock', 60)->block(5, $callback);
    }

    public function allowsMission(string $mission, ?string $generation = null): bool
    {
        $state = $this->status();

        return ($generation === null || $generation === ($state['generation'] ?? null))
            && in_array($mission, $state['effective_mission_ids'] ?? [], true);
    }

    /** Invalid legacy or mixed values never imply all missions. */
    private function selection(mixed $missions): array
    {
        if (! is_array($missions) || ! array_is_list($missions)
            || count(array_filter($missions, 'is_string')) !== count($missions)
            || count(array_unique($missions)) !== count($missions)
            || array_diff($missions, Platform12DailyMissionSet::IDS) !== []) {
            return [];
        }

        return $missions;
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
