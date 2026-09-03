<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

final class CapabilityLifecycleStateMachine
{
    /** @var list<string> */
    public const STATES = ['draft', 'offline_eval', 'shadow', 'active', 'degraded', 'hold', 'deprecated'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['offline_eval', 'hold'],
        'offline_eval' => ['shadow', 'hold'],
        'shadow' => ['active', 'hold'],
        'active' => ['degraded', 'hold', 'deprecated'],
        'degraded' => ['hold', 'deprecated'],
        'hold' => ['offline_eval', 'deprecated'],
        'deprecated' => [],
    ];

    /** @var list<string> */
    public const REEVALUATION_DIMENSIONS = ['role', 'prompt', 'model', 'tool', 'policy', 'schema', 'evidence', 'binding'];

    /** @return array<string, string> */
    public function permissionStates(): array
    {
        return [
            'L0' => 'READY',
            'L1' => 'READY',
            'L2' => 'IMPLEMENTED_WRITE_DISABLED',
            'L3' => 'IMPLEMENTED_WRITE_DISABLED',
            'L4' => 'DORMANT_NOT_AUTHORIZED',
        ];
    }

    /** @return array{status:string,state:string,reason:string,execution_allowed:bool} */
    public function transition(string $current, string $target, bool $evalReceiptValid, string $channel = 'deterministic_system'): array
    {
        if ($channel !== 'deterministic_system') {
            return $this->hold('MUTATION_CHANNEL_DENIED');
        }
        if (! in_array($current, self::STATES, true) || ! in_array($target, self::STATES, true)) {
            return $this->hold('UNKNOWN_STATE');
        }
        if (! in_array($target, self::TRANSITIONS[$current], true)) {
            return $this->hold('ILLEGAL_TRANSITION');
        }
        if (in_array($target, ['shadow', 'active'], true) && ! $evalReceiptValid) {
            return $this->hold('EVAL_RECEIPT_REQUIRED');
        }

        return ['status' => 'ACCEPTED', 'state' => $target, 'reason' => 'LEGAL_TRANSITION', 'execution_allowed' => false];
    }

    /** @param array<string, string> $expected @param array<string, string> $observed
     * @return array{status:string,state:string,reason:string,changed_dimensions:list<string>,execution_allowed:bool}
     */
    public function verifyVersionVector(array $expected, array $observed): array
    {
        $changed = [];
        foreach (self::REEVALUATION_DIMENSIONS as $dimension) {
            if (! isset($expected[$dimension], $observed[$dimension]) || ! hash_equals($expected[$dimension], $observed[$dimension])) {
                $changed[] = $dimension;
            }
        }

        return $changed === []
            ? ['status' => 'READY', 'state' => 'offline_eval', 'reason' => 'VERSION_VECTOR_VERIFIED', 'changed_dimensions' => [], 'execution_allowed' => false]
            : ['status' => 'HOLD', 'state' => 'hold', 'reason' => 'REEVALUATION_REQUIRED', 'changed_dimensions' => $changed, 'execution_allowed' => false];
    }

    /** @return array{status:string,state:string,reason:string,execution_allowed:bool} */
    private function hold(string $reason): array
    {
        return ['status' => 'HOLD', 'state' => 'hold', 'reason' => $reason, 'execution_allowed' => false];
    }
}
