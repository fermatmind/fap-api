<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use RuntimeException;

final class CompetitiveEvidenceContractRegistry
{
    public const MANIFEST_ID = 'seo.evidence_contract_manifest.v3';

    public const MANIFEST_VERSION = '3.0.0';

    /** @var list<string> */
    private const SCHEMAS = [
        'seo.competitive_page_projection.v1.schema.json',
        'seo.competitive_evidence_request.v1.schema.json',
        'seo.competitive_evidence_context.v1.schema.json',
        'seo.competitive_evidence_finding.v1.schema.json',
        'seo.competitive_evidence_output.v1.schema.json',
        'seo.competitive_11i_handoff.v1.schema.json',
        'seo.competitive_evidence_closeout.v1.schema.json',
        'seo.competitive_source_field_ownership.v1.schema.json',
        'seo.competitive_source_policy.v1.schema.json',
    ];

    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoEvidenceContractRegistry $baseContracts,
    ) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $base = $this->baseContracts->manifest();
        $contracts = [];
        foreach (self::SCHEMAS as $file) {
            $schema = $this->schemaFile($file);
            $contracts[] = [
                'id' => $schema['schema_id'],
                'version' => $schema['schema_version'],
                'path' => 'backend/resources/seo-agent/evidence/competitive/schemas/'.$file,
                'hash' => $this->hasher->hash($schema),
            ];
        }

        $ownership = $this->ownership();
        $manifest = [
            'manifest_id' => self::MANIFEST_ID,
            'manifest_version' => self::MANIFEST_VERSION,
            'append_only_base' => [
                'id' => $base['schema_version'],
                'version' => $base['manifest_version'],
                'path' => 'backend/docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json',
                'hash' => $base['manifest_hash'],
            ],
            'contracts' => $contracts,
            'source_field_ownership' => [
                'id' => $ownership['schema_version'],
                'version' => $ownership['ownership_version'],
                'path' => 'backend/resources/seo-agent/evidence/competitive/seo.competitive_source_field_ownership.v1.json',
                'hash' => $this->hasher->hash($ownership),
            ],
            'historical_manifests' => [
                [
                    'id' => 'seo.evidence_contract_manifest.v1',
                    'path' => 'backend/docs/seo/generated/seo-agent-evidence-contract-manifest.v1.json',
                    'current_authority' => false,
                ],
                [
                    'id' => 'seo.evidence_contract_manifest.v2',
                    'path' => 'backend/docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json',
                    'current_authority' => false,
                ],
            ],
            'negative_guarantees' => [
                'gateway_live_adapter_enabled' => false,
                'external_ingestion_enabled' => false,
                'raw_html_retained' => false,
                'competitor_snippets_retained' => false,
                'production_permissions' => 0,
                'execution_allowed' => false,
                'outreach_actions' => 0,
                'digital_pr_scope' => 'deferred_p2_manual',
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

        throw new RuntimeException('Unknown Competitive Evidence schema.');
    }

    /** @return array<string, mixed> */
    public function ownership(): array
    {
        return $this->decode($this->root().'/seo.competitive_source_field_ownership.v1.json');
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
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Competitive Evidence asset must be a JSON object.');
        }

        return $decoded;
    }

    private function root(): string
    {
        return resource_path('seo-agent/evidence/competitive');
    }
}
