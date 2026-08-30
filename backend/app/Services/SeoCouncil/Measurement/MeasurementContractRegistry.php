<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use RuntimeException;

final class MeasurementContractRegistry
{
    public const MANIFEST_ID = 'seo.measurement_contract_manifest.v1';

    public const MANIFEST_VERSION = '1.0.0';

    private const SCHEMAS = [
        'seo.measurement_evidence_context.v1.schema.json',
        'seo.source_capability_decision.v1.schema.json',
        'seo.measurement_state_decision.v1.schema.json',
        'seo.measurement_evidence_gap.v1.schema.json',
        'seo.search_measurement_request.v1.schema.json',
        'seo.search_measurement_finding.v1.schema.json',
        'seo.search_measurement_output.v1.schema.json',
        'seo.commercial_funnel_cro_request.v1.schema.json',
        'seo.commercial_funnel_cro_finding.v1.schema.json',
        'seo.cro_experiment_candidate.v1.schema.json',
        'seo.commercial_funnel_cro_output.v1.schema.json',
        'seo.measurement_closeout_receipt.v1.schema.json',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $contracts = array_map(function (string $file): array {
            $schema = $this->decode($this->root().'/schemas/'.$file);

            return [
                'id' => $schema['schema_id'],
                'version' => $schema['schema_version'],
                'path' => 'backend/resources/seo-agent/council/measurement/schemas/'.$file,
                'hash' => $this->hasher->hash($schema),
            ];
        }, self::SCHEMAS);

        $assets = [];
        foreach ([
            'search_mode' => 'seo.search_measurement_mode.v1.json',
            'search_policy' => 'seo.search_measurement_policy.v1.json',
            'cro_mode' => 'seo.commercial_funnel_cro_mode.v1.json',
            'cro_policy' => 'seo.commercial_funnel_cro_policy.v1.json',
            'fixtures' => 'fixtures/seo.measurement_fixtures.v1.json',
        ] as $key => $file) {
            $asset = $this->decode($this->root().'/'.$file);
            $assets[$key] = [
                'id' => (string) ($asset['mode_id'] ?? $asset['policy_id'] ?? $asset['fixture_set_id']),
                'version' => (string) ($asset['mode_version'] ?? $asset['policy_version'] ?? $asset['fixture_set_version']),
                'path' => 'backend/resources/seo-agent/council/measurement/'.$file,
                'hash' => $this->hasher->hash($asset),
            ];
        }
        foreach ([
            'search_prompt' => 'seo.search_measurement.prompt.v1.md',
            'cro_prompt' => 'seo.commercial_funnel_cro.prompt.v1.md',
        ] as $key => $file) {
            $prompt = file_get_contents($this->root().'/'.$file);
            if (! is_string($prompt)) {
                throw new RuntimeException('Measurement prompt is unreadable.');
            }
            $assets[$key] = [
                'id' => str_replace('.md', '', $file),
                'version' => '1.0.0',
                'path' => 'backend/resources/seo-agent/council/measurement/'.$file,
                'hash' => $this->hasher->promptHash($this->hasher->normalizePrompt($prompt)),
            ];
        }

        $manifest = [
            'manifest_id' => self::MANIFEST_ID,
            'manifest_version' => self::MANIFEST_VERSION,
            'contracts' => $contracts,
            ...$assets,
            'negative_guarantees' => [
                'new_role' => false,
                'registry_changed' => false,
                'binding_v2_changed' => false,
                'new_orchestrator' => false,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'execution_allowed' => false,
            ],
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function fixtureSet(): array
    {
        return $this->decode($this->root().'/fixtures/seo.measurement_fixtures.v1.json');
    }

    /** @return array<string, mixed> */
    public function searchPolicy(): array
    {
        return $this->decode($this->root().'/seo.search_measurement_policy.v1.json');
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest): bool
    {
        return is_string($manifest['manifest_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($manifest, 'manifest_hash'), (string) $manifest['manifest_hash'])
            && hash_equals((string) $this->manifest()['manifest_hash'], (string) $manifest['manifest_hash']);
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Measurement asset must be a JSON object.');
        }

        return $decoded;
    }

    private function root(): string
    {
        return resource_path('seo-agent/council/measurement');
    }
}
