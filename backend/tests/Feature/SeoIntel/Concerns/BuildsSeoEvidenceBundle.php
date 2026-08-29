<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel\Concerns;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;

trait BuildsSeoEvidenceBundle
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function evidenceBundle(array $overrides = []): array
    {
        return app(SeoEvidenceBundleFactory::class)->create(array_replace([
            'bundle_id' => 'bundle:test',
            'bundle_version' => 1,
            'mission_id' => 'mission:test',
            'source_type' => 'gsc_aggregate',
            'source_ref' => str_repeat('a', 64),
            'authority_type' => 'gsc_measurement',
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => 'verified',
            'freshness_state' => 'fresh',
            'source_capability_state' => 'available',
            'retention_class' => 'first_party_aggregate',
            'page_family' => 'tests',
            'locale' => 'zh-CN',
            'authority_revision' => 'revision:test',
            'injection_scan_result' => 'pass',
            'source_license_class' => 'first_party',
            'data_usage_purpose' => 'search_measurement',
            'egress_decision' => 'not_required',
            'lineage_refs' => [],
            'payload' => ['query_hmac' => str_repeat('b', 64), 'query_hmac_key_version' => 'k1', 'clicks' => 10, 'impressions' => 100],
        ], $overrides));
    }
}
