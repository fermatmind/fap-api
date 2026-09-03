<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel\Concerns;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceAnalyzer;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;

trait BuildsCompetitiveEvidenceBundle
{
    /** @return array<string, mixed> */
    protected function competitiveBundleInput(string $environment = 'staging', string $releaseSha = 'f33d4b34caa65c88c8c6bbc61587ebe2a87dbee9'): array
    {
        $hasher = app(SeoEvidenceCanonicalHasher::class);
        $relation = [[
            'entity_id' => 'entity.big-five',
            'relation' => 'measures',
            'target_id' => 'schema.quiz',
            'relation_hash' => $hasher->hash(['entity.big-five', 'measures', 'schema.quiz']),
        ]];
        $projections = [
            $this->competitiveProjection('fermatmind-big-five-en', 'fermatmind_public', 'en', ['hero', 'definition', 'faq'], $relation),
            $this->competitiveProjection('fermatmind-big-five-zh', 'fermatmind_public', 'zh-CN', ['hero', 'definition', 'faq'], $relation),
            $this->competitiveProjection('bigfive-test', 'competitor_public', 'en', ['assessment_entry', 'dimensions'], $relation),
            $this->competitiveProjection('jobcannon', 'competitor_public', 'en', ['assessment_entry', 'dimensions', 'faq', 'methodology'], $relation),
        ];
        $output = app(CompetitiveEvidenceAnalyzer::class)->analyze([
            'page_family' => 'tests',
            'locale' => 'en',
            'projections' => $projections,
            'source_policies' => array_map(static fn (array $projection): array => [
                'source_id' => $projection['source_id'],
                'freshness_state' => 'fresh',
                'policy_hash' => $projection['source_policy_ref']['policy_hash'],
            ], $projections),
            'authority' => [
                'source_hash' => $hasher->hash(['fermatmind-authority']),
                'freshness_state' => 'fresh',
                'conflict' => false,
                'owner_gap_confirmed' => false,
                'modules' => ['hero', 'definition', 'faq'],
                'entity_relations' => [],
                'information_ids' => [],
            ],
            'measurement' => [
                'source_hash' => $hasher->hash(['search-measurement']),
                'freshness_state' => 'fresh',
                'conflict' => false,
                'demand_windows' => [true, true, true],
            ],
            'dependency_ingestion' => ['external_reads' => 12],
        ]);
        $releaseRef = app(CompetitiveReleaseIdentity::class)->reference($environment, $releaseSha);

        return [
            'bundle_id' => 'competitive:'.$environment.':'.$releaseRef,
            'bundle_version' => 1,
            'mission_id' => 'competitive:ingestion:'.$releaseRef,
            'source_type' => 'external_gateway',
            'source_ref' => $hasher->hash([$environment, $releaseSha, 'competitive.big-five.live.v2']),
            'authority_type' => 'competitive_structural_projection',
            'captured_at' => '2026-09-03T00:00:00Z',
            'evidence_state' => 'verified',
            'freshness_state' => 'fresh',
            'source_capability_state' => 'available',
            'retention_class' => 'external_structured_fact',
            'page_family' => 'tests',
            'locale' => 'en',
            'authority_revision' => $hasher->hash(['fermatmind-authority']),
            'source_license_class' => 'public_fact_permitted',
            'data_usage_purpose' => 'competitive_evidence',
            'egress_decision' => 'allowed_by_gateway',
            'lineage_refs' => [$hasher->hash(['search']), $hasher->hash(['cro'])],
            'payload' => [
                'environment' => $environment,
                'release_ref' => $releaseRef,
                'cohort_id' => 'competitive.big-five.live.v2',
                'source_policy_set_hash' => 'f1b0ed4903667883cabe128b5c2ecc1e90bc91ef4c1b6381a1b0ed0450be9499',
                'measurement_bundle_set_hash' => $hasher->hash(['measurement-set']),
                'projections' => $projections,
                'competitive_output' => $output,
                '11i_handoff' => $output['11i_handoff'],
                'dependency_ingestion' => [
                    'external_reads' => 12,
                    'logical_requests' => 10,
                    'transport_attempts' => 12,
                    'retry_count' => 2,
                ],
            ],
        ];
    }

    /** @param list<string> $modules @param list<array<string, mixed>> $relations @return array<string, mixed> */
    private function competitiveProjection(string $sourceId, string $sourceClass, string $locale, array $modules, array $relations): array
    {
        $hasher = app(SeoEvidenceCanonicalHasher::class);
        $moduleRecords = [];
        foreach ($modules as $ordinal => $module) {
            $moduleRecords[] = [
                'module_type' => $module,
                'ordinal' => $ordinal,
                'module_hash' => $hasher->hash([$module, $ordinal]),
            ];
        }
        $pattern = [
            'from_family' => 'tests',
            'relation' => 'related',
            'to_family' => 'personality',
            'count_bucket' => '2-3',
        ];
        $pattern['pattern_hash'] = $hasher->hash($pattern);
        $structure = [
            'headings' => [],
            'modules' => $moduleRecords,
            'schema_types' => ['WebPage'],
            'entity_ids' => ['entity.big-five'],
            'entity_relations' => $relations,
            'claim_signals' => [],
            'canonical_hash' => $hasher->hash([$sourceId, 'canonical']),
            'hreflang' => [],
            'internal_link_patterns' => [$pattern],
        ];
        $structure['structure_fingerprint'] = $hasher->hash($structure);
        $projection = [
            'version' => 'seo.competitive_page_projection.v2',
            'source_id' => $sourceId,
            'cohort_id' => 'competitive.big-five.live.v2',
            'source_class' => $sourceClass,
            'page_family' => 'tests',
            'locale' => $locale,
            'public_url_hash' => $hasher->hash([$sourceId, 'url']),
            'source_policy_ref' => [
                'policy_id' => 'competitive.source.'.$sourceId.'.v3',
                'policy_version' => 3,
                'policy_hash' => $hasher->hash([$sourceId, 'policy']),
                'status' => 'approved',
                'expires_at' => '2026-10-02T00:00:00Z',
            ],
            'capture' => [
                'captured_at' => '2026-09-03T00:00:00Z',
                'response_hash' => str_repeat('a', 16).'4111111111111111'.str_repeat('b', 32),
                'content_type' => 'text/html',
                'response_bytes' => 1024,
                'http_status' => 200,
                'robots_decision' => 'allowed',
                'terms_decision' => 'approved',
                'license_decision' => 'public_structure_permitted',
            ],
            'structure' => $structure,
            'redaction' => [
                'raw_html_retained' => false,
                'competitor_snippets_retained' => false,
                'private_data_present' => false,
                'login_or_paywall_detected' => false,
                'injection_scan_result' => 'pass',
            ],
        ];

        return $this->sealCompetitiveValue($projection, 'projection_hash');
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    protected function sealCompetitiveValue(array $value, string $field): array
    {
        unset($value[$field]);
        $value[$field] = app(SeoEvidenceCanonicalHasher::class)->hash($value);

        return $value;
    }
}
