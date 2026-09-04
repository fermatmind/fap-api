<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use Throwable;

final class Platform12BoundedModelRuntime
{
    /** @var list<string> */
    private const CONTEXT_FIELDS = [
        'evidence_refs', 'facts', 'metrics', 'conflicts', 'freshness',
        'deterministic_status', 'private_data_present', 'injection_scan_result',
    ];

    /** @var list<string> */
    private const BUDGET_FIELDS = [
        'model', 'max_calls', 'max_input_tokens', 'max_output_tokens',
        'max_cost_microusd', 'deadline_ms', 'max_response_bytes',
    ];

    /** @var list<string> */
    private const AUTHORITY_FIELDS = [
        'role', 'roles', 'requested_role', 'tool', 'tools', 'tool_scope', 'tool_allowlist',
        'permission', 'permissions', 'write_permissions', 'action_scope', 'execution_allowed',
        'write_allowed', 'egress_scope', 'authority', 'authority_ceiling', 'capability', 'capabilities',
    ];

    public function __construct(
        private readonly SeoCouncilModelClient $client,
        private readonly Platform12BoundedModelContract $contract,
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $evidenceContext @return array<string, mixed> */
    public function run(string $missionType, array $evidenceContext): array
    {
        $budget = config('seo_council.model_missions.'.$missionType);
        if (! is_array($budget) || ! $this->validBudget($budget)) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_BUDGET_INVALID');
        }
        if (! $this->validContextEnvelope($evidenceContext)) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'EVIDENCE_CONTEXT_SCOPE_INVALID');
        }
        if ($this->privacy->containsPrivateData($evidenceContext)) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'PRIVATE_EVIDENCE_DENIED');
        }
        if ($this->injection->scan($evidenceContext)['result'] !== 'pass') {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'EVIDENCE_PROMPT_INJECTION_DENIED');
        }

        $encodedContext = json_encode($evidenceContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $estimatedInputTokens = is_string($encodedContext) ? (int) ceil(strlen($encodedContext) / 4) : PHP_INT_MAX;
        if ($budget['max_calls'] < 1 || $estimatedInputTokens > $budget['max_input_tokens']) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_BUDGET_EXHAUSTED');
        }

        $complete = $this->deterministicEvidenceIsComplete($evidenceContext);
        $startedAt = hrtime(true);
        try {
            $response = $this->client->complete(new SeoCouncilModelRequest(
                $budget['model'],
                $evidenceContext,
                $this->contract->prompt(),
                $this->contract->outputSchema(),
                $budget['max_calls'],
                $budget['max_output_tokens'],
                $budget['deadline_ms'],
                $budget['max_response_bytes'],
            ));
        } catch (SeoCouncilModelFailure $failure) {
            return $this->providerFailure($failure->failureCode, $failure->transportAttempts, $complete);
        } catch (Throwable) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_CLIENT_FAILURE');
        }

        $elapsedMilliseconds = (int) ceil((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMilliseconds > $budget['deadline_ms']) {
            return $this->providerFailure('MODEL_DEADLINE_EXHAUSTED', $response->transportAttempts, $complete);
        }
        if (! $this->usageWithinBudget($response->usage, $budget)) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_BUDGET_EXHAUSTED', $response->transportAttempts);
        }
        $encodedOutput = json_encode($response->output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encodedOutput) || strlen($encodedOutput) > $budget['max_response_bytes']) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_RESPONSE_BUDGET_EXHAUSTED', $response->transportAttempts);
        }
        if ($this->containsAuthorityField($response->output)) {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_OUTPUT_AUTHORITY_EXPANSION', $response->transportAttempts);
        }
        if ($this->injection->scan($response->output)['result'] !== 'pass') {
            return $this->terminal('MODEL_UNAVAILABLE_HOLD', 'MODEL_OUTPUT_PROMPT_INJECTION', $response->transportAttempts);
        }
        if (! $this->validOutput($response->output)) {
            return $this->providerFailure('MODEL_RESPONSE_SCHEMA_INVALID', $response->transportAttempts, $complete);
        }

        return [
            'status' => 'READY',
            'reason_code' => 'MODEL_COMPLETED',
            'artifact' => $response->output,
            'prompt_ref' => array_diff_key($this->contract->prompt(), ['instructions' => true]),
            'output_schema_ref' => $this->contract->outputSchemaRef(),
            'model' => $budget['model'],
            'model_calls' => $response->transportAttempts,
            'transport_attempts' => $response->transportAttempts,
            'usage' => $response->usage,
            'action_scope' => [],
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $budget */
    private function validBudget(array $budget): bool
    {
        if (array_keys($budget) !== self::BUDGET_FIELDS
            || ! is_string($budget['model'] ?? null)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $budget['model']) !== 1) {
            return false;
        }
        $limits = [
            'max_calls' => [0, 8],
            'max_input_tokens' => [1, 100000],
            'max_output_tokens' => [1, 20000],
            'max_cost_microusd' => [0, 10000000],
            'deadline_ms' => [100, 900000],
            'max_response_bytes' => [128, 1048576],
        ];
        foreach ($limits as $field => [$minimum, $maximum]) {
            if (! is_int($budget[$field] ?? null) || $budget[$field] < $minimum || $budget[$field] > $maximum) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    private function validContextEnvelope(array $context): bool
    {
        if (array_diff(array_keys($context), self::CONTEXT_FIELDS) !== []) {
            return false;
        }
        foreach (['evidence_refs', 'facts', 'metrics', 'conflicts', 'freshness', 'deterministic_status', 'private_data_present', 'injection_scan_result'] as $required) {
            if (! array_key_exists($required, $context)) {
                return false;
            }
        }

        return is_array($context['evidence_refs'])
            && array_is_list($context['evidence_refs'])
            && is_array($context['facts'])
            && array_is_list($context['facts'])
            && is_array($context['metrics'])
            && is_array($context['conflicts'])
            && array_is_list($context['conflicts'])
            && is_array($context['freshness'])
            && in_array($context['deterministic_status'], ['COMPLETE', 'INCOMPLETE'], true)
            && $context['private_data_present'] === false
            && $context['injection_scan_result'] === 'pass';
    }

    /** @param array<string, mixed> $context */
    private function deterministicEvidenceIsComplete(array $context): bool
    {
        if ($context['deterministic_status'] !== 'COMPLETE'
            || $context['evidence_refs'] === []
            || $context['facts'] === []
            || $context['conflicts'] !== []) {
            return false;
        }
        foreach ($context['evidence_refs'] as $reference) {
            if (! is_array($reference)
                || ! $this->exactKeys($reference, ['id', 'hash', 'status'])
                || ! is_string($reference['id'])
                || trim($reference['id']) === ''
                || preg_match('/^[a-f0-9]{64}$/D', (string) $reference['hash']) !== 1
                || $reference['status'] !== 'READY') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $usage @param array<string, mixed> $budget */
    private function usageWithinBudget(array $usage, array $budget): bool
    {
        if (! $this->exactKeys($usage, ['input_tokens', 'output_tokens', 'cost_microusd'])) {
            return false;
        }
        foreach ($usage as $value) {
            if (! is_int($value) || $value < 0) {
                return false;
            }
        }

        return $usage['input_tokens'] <= $budget['max_input_tokens']
            && $usage['output_tokens'] <= $budget['max_output_tokens']
            && $usage['cost_microusd'] <= $budget['max_cost_microusd'];
    }

    /** @param array<string, mixed> $output */
    private function validOutput(array $output): bool
    {
        if (! $this->exactKeys($output, ['summary', 'findings', 'uncertainties'])
            || ! is_string($output['summary'])
            || trim($output['summary']) === ''
            || mb_strlen($output['summary']) > 2000
            || ! is_array($output['findings'])
            || ! array_is_list($output['findings'])
            || count($output['findings']) > 12
            || ! is_array($output['uncertainties'])
            || ! array_is_list($output['uncertainties'])
            || count($output['uncertainties']) > 12) {
            return false;
        }
        foreach ($output['findings'] as $finding) {
            if (! is_array($finding)
                || ! $this->exactKeys($finding, ['claim', 'confidence', 'evidence_refs'])
                || ! is_string($finding['claim'])
                || trim($finding['claim']) === ''
                || mb_strlen($finding['claim']) > 1000
                || ! in_array($finding['confidence'], ['low', 'medium', 'high'], true)
                || ! $this->validStringList($finding['evidence_refs'], 16, 160, true)) {
                return false;
            }
        }

        return $this->validStringList($output['uncertainties'], 12, 500, false);
    }

    private function validStringList(mixed $value, int $maximum, int $maxLength, bool $required): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum || ($required && $value === [])) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }

    private function containsAuthorityField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (in_array($normalized, self::AUTHORITY_FIELDS, true) || $this->containsAuthorityField($child)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function providerFailure(string $reason, int $attempts, bool $deterministicComplete): array
    {
        if ($deterministicComplete) {
            return $this->terminal('DEGRADED_DETERMINISTIC_ONLY', $reason, $attempts);
        }

        return $this->terminal('MODEL_UNAVAILABLE_HOLD', $reason, $attempts);
    }

    /** @return array<string, mixed> */
    private function terminal(string $status, string $reason, int $attempts = 0): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'artifact' => null,
            'model_calls' => $attempts,
            'transport_attempts' => $attempts,
            'action_scope' => [],
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
