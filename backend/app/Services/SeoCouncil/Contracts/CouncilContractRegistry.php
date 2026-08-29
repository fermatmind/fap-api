<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Contracts;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use JsonException;
use RuntimeException;

final class CouncilContractRegistry
{
    public const MANIFEST_ID = 'seo.council_contract_manifest.v1';

    public const MANIFEST_VERSION = '1.0.0';

    private const SCHEMAS = [
        'seo.mission_request.v1.schema.json',
        'seo.council_run.v1.schema.json',
        'seo.run_step.v1.schema.json',
        'seo.handoff_envelope.v1.schema.json',
        'seo.mode_output.v1.schema.json',
        'seo.run_receipt.v1.schema.json',
        'seo.conflict_record.v1.schema.json',
        'seo.operator_time_entry.v1.schema.json',
        'seo.decision_history_projection.v1.schema.json',
        'seo.runtime_capability_snapshot.v1.schema.json',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $contracts = [];
        foreach (self::SCHEMAS as $file) {
            $payload = $this->load('schemas/'.$file);
            $contracts[] = [
                'id' => $payload['schema_id'],
                'version' => $payload['schema_version'],
                'path' => 'backend/resources/seo-agent/council/schemas/'.$file,
                'hash' => $this->hasher->hash($payload),
            ];
        }

        $actionManifest = $this->load('../policy-gateway/schemas/seo.action_scoped_manifest.v1.schema.json');
        $manifest = [
            'manifest_id' => self::MANIFEST_ID,
            'manifest_version' => self::MANIFEST_VERSION,
            'contracts' => $contracts,
            'reused_action_manifest' => [
                'id' => $actionManifest['schema_id'],
                'version' => $actionManifest['schema_version'],
                'path' => 'backend/resources/seo-agent/policy-gateway/schemas/seo.action_scoped_manifest.v1.schema.json',
                'hash' => $this->hasher->hash($actionManifest),
            ],
            'negative_guarantees' => [
                'second_action_manifest' => false,
                'external_trace_export' => false,
                'shared_agent_memory' => false,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
            ],
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $artifact */
    public function verify(array $artifact): bool
    {
        return is_string($artifact['manifest_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($artifact, 'manifest_hash'), $artifact['manifest_hash'])
            && hash_equals($this->manifest()['manifest_hash'], $artifact['manifest_hash']);
    }

    /** @return array<string, mixed> */
    private function load(string $relativePath): array
    {
        $path = resource_path('seo-agent/council/'.$relativePath);
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('SEO Council contract is unreadable.');
        }

        try {
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SEO Council contract JSON is invalid.', previous: $exception);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('SEO Council contract root is invalid.');
        }

        return $payload;
    }
}
