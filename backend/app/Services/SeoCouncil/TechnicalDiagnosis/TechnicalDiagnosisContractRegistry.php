<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use RuntimeException;

final class TechnicalDiagnosisContractRegistry
{
    public const MANIFEST_ID = 'seo.technical_diagnosis_contract_manifest.v2';

    public const MANIFEST_VERSION = '2.0.0';

    /** @var list<string> */
    private const SCHEMAS = [
        'seo.technical_diagnosis_request.v2.schema.json',
        'seo.technical_affected_scope.v1.schema.json',
        'seo.technical_root_cause_hypothesis.v1.schema.json',
        'seo.technical_evidence_gap.v1.schema.json',
        'seo.technical_diagnosis_finding.v2.schema.json',
        'seo.technical_diagnosis_output.v2.schema.json',
        'seo.technical_diagnosis_evidence_context.v2.schema.json',
        'seo.technical_source_field_ownership.v2.schema.json',
        'seo.technical_diagnosis_receipt.v2.schema.json',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $contracts = [];
        foreach (self::SCHEMAS as $file) {
            $schema = $this->schemaFile($file);
            $contracts[] = [
                'id' => $schema['schema_id'],
                'version' => $schema['schema_version'],
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/schemas/'.$file,
                'hash' => $this->hasher->hash($schema),
            ];
        }

        $mode = $this->jsonAsset('seo.technical_diagnosis_mode.v2.json');
        $policy = $this->jsonAsset('seo.technical_diagnosis_policy.v2.json');
        $ownership = $this->ownership();
        $prompt = $this->prompt();
        $fixtures = $this->decode($this->root().'/fixtures/seo.technical_diagnosis_fixtures.v1.json');
        $manifest = [
            'manifest_id' => self::MANIFEST_ID,
            'manifest_version' => self::MANIFEST_VERSION,
            'contracts' => $contracts,
            'mode' => [
                'id' => $mode['mode_id'],
                'version' => $mode['mode_version'],
                'hash' => $this->hasher->hash($mode),
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/seo.technical_diagnosis_mode.v2.json',
            ],
            'prompt' => [
                'id' => 'seo.technical_search_diagnosis.prompt.v1',
                'version' => '1.0.0',
                'hash' => $this->hasher->promptHash($prompt),
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/seo.technical_search_diagnosis.prompt.v1.md',
            ],
            'policy' => [
                'id' => $policy['policy_id'],
                'version' => $policy['policy_version'],
                'hash' => $this->hasher->hash($policy),
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/seo.technical_diagnosis_policy.v2.json',
            ],
            'source_field_ownership' => [
                'id' => $ownership['schema_version'],
                'version' => $ownership['ownership_version'],
                'hash' => $this->hasher->hash($ownership),
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/seo.technical_source_field_ownership.v2.json',
            ],
            'fixtures' => [
                'id' => $fixtures['fixture_set_id'],
                'version' => $fixtures['fixture_set_version'],
                'hash' => $this->hasher->hash($fixtures),
                'path' => 'backend/resources/seo-agent/council/technical-diagnosis/fixtures/seo.technical_diagnosis_fixtures.v1.json',
            ],
            'negative_guarantees' => [
                'binding_v1_or_v2_changed' => false,
                'new_role' => false,
                'new_orchestrator' => false,
                'activity_is_probe_derived' => true,
            ],
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function schema(string $id): array
    {
        foreach (self::SCHEMAS as $file) {
            $schema = $this->schemaFile($file);
            if (($schema['schema_id'] ?? null) === $id) {
                return $schema;
            }
        }

        throw new RuntimeException('Unknown Technical Diagnosis schema.');
    }

    /** @return array<string, mixed> */
    public function mode(): array
    {
        return $this->jsonAsset('seo.technical_diagnosis_mode.v2.json');
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return $this->jsonAsset('seo.technical_diagnosis_policy.v2.json');
    }

    /** @return array<string, mixed> */
    public function ownership(): array
    {
        return $this->jsonAsset('seo.technical_source_field_ownership.v2.json');
    }

    public function prompt(): string
    {
        $bytes = file_get_contents($this->root().'/seo.technical_search_diagnosis.prompt.v1.md');
        if (! is_string($bytes)) {
            throw new RuntimeException('Technical Diagnosis prompt is unreadable.');
        }

        return $this->hasher->normalizePrompt($bytes);
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest): bool
    {
        return is_string($manifest['manifest_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($manifest, 'manifest_hash'), (string) $manifest['manifest_hash'])
            && hash_equals((string) $this->manifest()['manifest_hash'], (string) $manifest['manifest_hash']);
    }

    /** @return array<string, mixed> */
    private function schemaFile(string $file): array
    {
        return $this->decode($this->root().'/schemas/'.$file);
    }

    /** @return array<string, mixed> */
    private function jsonAsset(string $file): array
    {
        return $this->decode($this->root().'/'.$file);
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Technical Diagnosis asset must be a JSON object.');
        }

        return $decoded;
    }

    private function root(): string
    {
        return resource_path('seo-agent/council/technical-diagnosis');
    }
}
