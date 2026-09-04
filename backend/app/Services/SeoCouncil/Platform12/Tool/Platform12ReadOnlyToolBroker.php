<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Tool;

use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class Platform12ReadOnlyToolBroker
{
    /** @var list<string> */
    private const REQUEST_FIELDS = ['tool_id', 'tool_version', 'input', 'authorization'];

    /** @var list<string> */
    private const AUTHORIZATION_FIELDS = [
        'autonomy', 'allowed_tools', 'tool_manifest_hash', 'peer_delegation', 'all_team_invocation',
    ];

    /** @var list<string> */
    private const IMPERATIVE_INPUT_FIELDS = [
        'url', 'uri', 'host', 'endpoint', 'command', 'shell', 'http', 'headers',
        'cms_write', 'deploy', 'url_truth_write', 'search_submission', 'service_class', 'handler_class',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Platform12ToolManifest $manifest,
        private readonly SeoRegistryHasher $hasher,
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function invoke(array $request): array
    {
        $startedAt = hrtime(true);
        $toolId = $this->safeIdentity($request['tool_id'] ?? null, 'invalid_tool');
        $toolVersion = $this->safeIdentity($request['tool_version'] ?? null, 'invalid_version');
        $input = is_array($request['input'] ?? null) ? $request['input'] : [];
        $inputSummaryHash = $this->hasher->hash($this->structuralSummary($input));

        if (app()->environment('production') || ! (bool) config('seo_council.tool_broker_enabled', false)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_BROKER_DISABLED', $startedAt);
        }
        if (! $this->exactKeys($request, self::REQUEST_FIELDS)
            || ! is_string($request['tool_id'])
            || ! is_string($request['tool_version'])
            || ! is_array($request['input'])
            || ! is_array($request['authorization'])) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_METADATA_INJECTION_DENIED', $startedAt);
        }
        if ($this->injection->scan($request)['result'] !== 'pass') {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_METADATA_INJECTION_DENIED', $startedAt);
        }
        if ($this->containsImperativeInputField($input)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'DIRECT_EXTERNAL_OR_WRITE_INPUT_DENIED', $startedAt);
        }
        if ($this->privacy->containsPrivateData($input)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'PRIVATE_TOOL_INPUT_DENIED', $startedAt);
        }

        $authorization = $request['authorization'];
        if (! $this->validAuthorization($authorization)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_UNAUTHORIZED', $startedAt);
        }
        if (! hash_equals($this->manifest->reference()['hash'], (string) $authorization['tool_manifest_hash'])) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_MANIFEST_DRIFT', $startedAt);
        }
        if (! in_array($toolId, $authorization['allowed_tools'], true)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_UNAUTHORIZED', $startedAt);
        }

        $definition = $this->manifest->tool($toolId, $toolVersion);
        if (! is_array($definition)) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_UNKNOWN', $startedAt);
        }
        try {
            $handler = $this->container->make($definition['handler_class']);
            if (! $handler instanceof Platform12ReadOnlyTool) {
                return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_HANDLER_INVALID', $startedAt);
            }
            $output = $handler->invoke($input);
        } catch (Throwable) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_EXCEPTION_HOLD', $startedAt);
        }
        $elapsed = $this->elapsedMilliseconds($startedAt);
        if ($elapsed > $definition['timeout_ms']) {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_TIMEOUT_HOLD', $startedAt);
        }
        if ($this->privacy->containsPrivateData($output) || $this->injection->scan($output)['result'] !== 'pass') {
            return $this->hold($toolId, $toolVersion, $inputSummaryHash, 'TOOL_OUTPUT_SAFETY_HOLD', $startedAt);
        }

        return $this->result(
            'PASS',
            'TOOL_COMPLETED',
            $toolId,
            $toolVersion,
            $inputSummaryHash,
            $this->hasher->hash($output),
            $elapsed,
            $output,
        );
    }

    /** @param array<string, mixed> $authorization */
    private function validAuthorization(array $authorization): bool
    {
        return $this->exactKeys($authorization, self::AUTHORIZATION_FIELDS)
            && in_array($authorization['autonomy'] ?? null, ['L0', 'L1'], true)
            && is_array($authorization['allowed_tools'] ?? null)
            && array_is_list($authorization['allowed_tools'])
            && count($authorization['allowed_tools']) <= 2
            && array_filter(
                $authorization['allowed_tools'],
                static fn (mixed $tool): bool => ! is_string($tool)
                    || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $tool) !== 1,
            ) === []
            && count(array_unique($authorization['allowed_tools'])) === count($authorization['allowed_tools'])
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($authorization['tool_manifest_hash'] ?? '')) === 1
            && ($authorization['peer_delegation'] ?? null) === false
            && ($authorization['all_team_invocation'] ?? null) === false;
    }

    private function containsImperativeInputField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (in_array($normalized, self::IMPERATIVE_INPUT_FIELDS, true)
                || $this->containsImperativeInputField($child)) {
                return true;
            }
        }

        return false;
    }

    private function safeIdentity(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $value) === 1
            ? $value
            : $fallback;
    }

    private function structuralSummary(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }
        if (array_is_list($value)) {
            return [
                'type' => 'list',
                'count' => count($value),
                'items' => array_map(fn (mixed $item): mixed => $this->structuralSummary($item), $value),
            ];
        }
        $summary = [];
        foreach ($value as $key => $child) {
            $summary[(string) $key] = $this->structuralSummary($child);
        }
        ksort($summary, SORT_STRING);

        return ['type' => 'object', 'fields' => $summary];
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @return array<string, mixed> */
    private function hold(
        string $toolId,
        string $toolVersion,
        string $inputSummaryHash,
        string $reason,
        int $startedAt,
    ): array {
        return $this->result(
            'HOLD',
            $reason,
            $toolId,
            $toolVersion,
            $inputSummaryHash,
            $this->hasher->hash(['status' => 'HOLD', 'reason_code' => $reason]),
            $this->elapsedMilliseconds($startedAt),
            null,
        );
    }

    /** @return array<string, mixed> */
    private function result(
        string $status,
        string $reason,
        string $toolId,
        string $toolVersion,
        string $inputSummaryHash,
        string $outputHash,
        int $elapsedMilliseconds,
        ?array $output,
    ): array {
        $receipt = [
            'schema_version' => 'seo.platform12_tool_receipt.v1',
            'tool_id' => $toolId,
            'tool_version' => $toolVersion,
            'tool_manifest_hash' => $this->manifest->reference()['hash'],
            'input_summary_hash' => $inputSummaryHash,
            'output_hash' => $outputHash,
            'elapsed_ms' => $elapsedMilliseconds,
            'status' => $status,
            'reason_code' => $reason,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return [
            'status' => $status,
            'reason_code' => $reason,
            'output' => $output,
            'receipt' => $receipt,
            'peer_delegation' => false,
            'all_team_invocation' => false,
            'external_egress' => false,
            'write_allowed' => false,
        ];
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ceil((hrtime(true) - $startedAt) / 1_000_000);
    }
}
