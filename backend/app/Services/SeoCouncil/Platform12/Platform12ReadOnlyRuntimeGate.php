<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

final class Platform12ReadOnlyRuntimeGate
{
    /** @var list<string> */
    public const STATES = ['OFFLINE_EVAL', 'SHADOW', 'ACTIVE_READ_ONLY', 'DEGRADED', 'HOLD'];

    /** @var list<string> */
    public const VERSION_DIMENSIONS = ['role', 'prompt', 'model', 'tool', 'policy', 'schema', 'evidence', 'binding'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'OFFLINE_EVAL' => ['SHADOW', 'HOLD'],
        'SHADOW' => ['ACTIVE_READ_ONLY', 'HOLD'],
        'ACTIVE_READ_ONLY' => ['DEGRADED', 'HOLD'],
        'DEGRADED' => ['HOLD'],
        'HOLD' => ['OFFLINE_EVAL'],
    ];

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $observed
     * @return array{state:string,reason:string,changed_dimensions:list<string>,read_only_runtime_enabled:bool}
     */
    public function evaluate(
        string $configuredState,
        bool $testSwitch,
        string $environment,
        array $expected,
        array $observed,
    ): array {
        $changed = $this->changedDimensions($expected, $observed);
        if ($environment === 'production') {
            return $this->hold('PRODUCTION_DISABLED', $changed);
        }
        if (! $testSwitch) {
            return $this->hold('TEST_SWITCH_DISABLED', $changed);
        }
        if ($changed !== []) {
            return $this->hold('CAPABILITY_VERSION_DRIFT', $changed);
        }
        if (! in_array($configuredState, self::STATES, true)) {
            return $this->hold('UNKNOWN_LIFECYCLE_STATE', []);
        }

        return [
            'state' => $configuredState,
            'reason' => $configuredState === 'ACTIVE_READ_ONLY' ? 'READ_ONLY_TEST_WINDOW_ACTIVE' : 'LIFECYCLE_NOT_ACTIVE',
            'changed_dimensions' => [],
            'read_only_runtime_enabled' => $configuredState === 'ACTIVE_READ_ONLY',
        ];
    }

    /** @return array{status:string,state:string,reason:string,execution_allowed:bool,write_allowed:bool} */
    public function transition(string $current, string $target): array
    {
        if (! in_array($current, self::STATES, true) || ! in_array($target, self::STATES, true)) {
            return $this->transitionHold('UNKNOWN_LIFECYCLE_STATE');
        }
        if (! in_array($target, self::TRANSITIONS[$current], true)) {
            return $this->transitionHold('ILLEGAL_LIFECYCLE_TRANSITION');
        }

        return [
            'status' => 'ACCEPTED',
            'state' => $target,
            'reason' => 'LEGAL_LIFECYCLE_TRANSITION',
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $snapshot */
    public function admits(array $request, array $snapshot): bool
    {
        return ($snapshot['read_only_runtime_enabled'] ?? null) === true
            && ($snapshot['read_only_runtime_state'] ?? null) === 'ACTIVE_READ_ONLY'
            && ($snapshot['execution_allowed'] ?? null) === false
            && ($snapshot['write_allowed'] ?? null) === false
            && in_array($request['autonomy'] ?? null, ['L0', 'L1'], true)
            && ($request['tool_scope'] ?? null) === []
            && ($request['egress_scope'] ?? null) === [];
    }

    /** @param array<string, string> $expected @param array<string, string> $observed @return list<string> */
    private function changedDimensions(array $expected, array $observed): array
    {
        $changed = [];
        foreach (self::VERSION_DIMENSIONS as $dimension) {
            if (! isset($expected[$dimension], $observed[$dimension])
                || preg_match('/^[a-f0-9]{64}$/D', $expected[$dimension]) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $observed[$dimension]) !== 1
                || ! hash_equals($expected[$dimension], $observed[$dimension])) {
                $changed[] = $dimension;
            }
        }

        return $changed;
    }

    /** @param list<string> $changed @return array{state:string,reason:string,changed_dimensions:list<string>,read_only_runtime_enabled:bool} */
    private function hold(string $reason, array $changed): array
    {
        return [
            'state' => 'HOLD',
            'reason' => $reason,
            'changed_dimensions' => $changed,
            'read_only_runtime_enabled' => false,
        ];
    }

    /** @return array{status:string,state:string,reason:string,execution_allowed:bool,write_allowed:bool} */
    private function transitionHold(string $reason): array
    {
        return [
            'status' => 'HOLD',
            'state' => 'HOLD',
            'reason' => $reason,
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
    }
}
