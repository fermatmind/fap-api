<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use Tests\TestCase;

final class EnneagramResultPagePilotAssetBatchTest extends TestCase
{
    public function test_pilot_batch_has_schema_source_trace_safety_and_rollback_shape(): void
    {
        $root = base_path('content_assets/enneagram/result_page/pilot_asset_batch/v0_1');

        $manifest = $this->readJson($root.'/pilot_batch_manifest.json');
        $this->assertSame('fap.enneagram.result_page.pilot_asset_batch.v0.1', $manifest['schema_version'] ?? null);
        $this->assertSame('not_runtime', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['candidate_export_happened'] ?? true));
        $this->assertFalse((bool) ($manifest['inactive_import_happened'] ?? true));
        $this->assertFalse((bool) ($manifest['activation_happened'] ?? true));
        $this->assertSame(2, (int) data_get($manifest, 'candidate_contract_reference.pilot_payload_count'));

        $payloads = (array) ($manifest['payloads'] ?? []);
        $this->assertCount(2, $payloads);

        $sourceLedger = $this->readJson(base_path('content_assets/enneagram/result_page/source_ledger/source_ledger.json'));
        $sourceIds = array_map(
            static fn (array $source): string => (string) ($source['source_id'] ?? ''),
            (array) ($sourceLedger['sources'] ?? [])
        );

        foreach ($payloads as $payloadRef) {
            $this->assertIsArray($payloadRef);
            $payload = $this->readJson($root.'/'.(string) $payloadRef['relative_path']);
            $this->assertSame('fap.enneagram.result_page.pilot_payload.v0.1', $payload['schema_version'] ?? null);
            $this->assertSame('not_runtime', $payload['runtime_use'] ?? null);
            $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
            $this->assertIsArray($payload['public_payload'] ?? null);
            $this->assertIsArray(data_get($payload, 'source_trace.source_ids'));
            $this->assertNotEmpty(data_get($payload, 'source_trace.claim_trace'));

            foreach ((array) data_get($payload, 'source_trace.source_ids', []) as $sourceId) {
                $this->assertContains($sourceId, $sourceIds);
            }

            $this->assertPayloadHasNoBlockedMaterial((array) ($payload['public_payload'] ?? []));
        }

        $sourceMapping = $this->readJson($root.'/source_mapping_report.json');
        $this->assertSame(0, (int) ($sourceMapping['source_mapping_failure_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['fallback_source_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['blocked_source_count'] ?? -1));
        $this->assertSame(2, (int) ($sourceMapping['payload_count'] ?? 0));
        $this->assertCount(2, (array) ($sourceMapping['mappings'] ?? []));

        $safety = $this->readJson($root.'/safety_report.json');
        $this->assertSame(0, (int) ($safety['metadata_leakage_hit_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['forbidden_claim_hit_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['fc144_boundary_violation_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['legacy_residual_count'] ?? -1));

        $rollback = (string) file_get_contents($root.'/rollback_plan.md');
        $this->assertStringContainsString('No database rollback', $rollback);
        $this->assertStringContainsString('runtime activation rollback', $rollback);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path.' must decode to an array');

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertPayloadHasNoBlockedMaterial(array $payload): void
    {
        $encoded = mb_strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        foreach ([
            'attempt_id',
            'private_url',
            'private_path',
            'raw_score',
            'domain_vector',
            'facet_vector',
            '/users/',
            '/private/tmp/',
            'diagnosis',
            'treatment',
            'hiring',
            'you are this type',
            'fixed type',
            'fc144 is more accurate',
            'fc144 replaces',
            'salary prediction',
            'performance prediction',
            '诊断',
            '治疗',
            '招聘',
            '你就是这个类型',
            '固定类型',
            'fc144 更准确',
            '成功预测',
            '薪资预测',
        ] as $blocked) {
            $this->assertStringNotContainsString($blocked, $encoded);
        }
    }
}
