<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentBatchRunner;
use Tests\TestCase;

final class EnneagramResultPage1RCAssetBatchTest extends TestCase
{
    public function test_1r_c_batch_assets_are_runner_safe_and_not_runtime(): void
    {
        $root = base_path('content_assets/enneagram/result_page/batch_1r_c/v0_1');
        $manifest = $this->readJson($root.'/asset_batch_manifest.json');
        $assetsDoc = $this->readJson($root.'/assets.json');

        $this->assertSame('fap.enneagram.result_page.batch_1r_c.manifest.v0.1', $manifest['schema_version'] ?? null);
        $this->assertSame('not_runtime', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['candidate_export_happened'] ?? true));
        $this->assertFalse((bool) ($manifest['inactive_import_happened'] ?? true));
        $this->assertFalse((bool) ($manifest['activation_happened'] ?? true));
        $this->assertSame(108, (int) data_get($manifest, 'candidate_contract_reference.this_batch_asset_count'));
        $this->assertSame(108, (int) ($assetsDoc['asset_count'] ?? 0));
        $this->assertSame(108, count((array) ($assetsDoc['assets'] ?? [])));
        $this->assertSame('778ed2540c1e3e619ea0812eda7828c4ea5f01fc709a7b5c30f0073eec6f8804', $manifest['source_asset_sha256'] ?? null);

        $this->assertSame([
            '1' => 12,
            '2' => 12,
            '3' => 12,
            '4' => 12,
            '5' => 12,
            '6' => 12,
            '7' => 12,
            '8' => 12,
            '9' => 12,
        ], data_get($manifest, 'coverage_counts.by_type_id'));
        $this->assertCount(12, (array) data_get($manifest, 'coverage_counts.by_objection_axis', []));

        $sourceMapping = $this->readJson($root.'/source_mapping_report.json');
        $this->assertSame(0, (int) ($sourceMapping['source_mapping_failure_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['fallback_source_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['blocked_source_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['duplicate_selection_count'] ?? -1));
        $this->assertSame(0, (int) ($sourceMapping['branch_provenance_mismatch_count'] ?? -1));

        $safety = $this->readJson($root.'/safety_report.json');
        $this->assertSame(0, (int) ($safety['metadata_leakage_hit_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['forbidden_claim_hit_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['fc144_boundary_violation_count'] ?? -1));
        $this->assertSame(0, (int) ($safety['legacy_residual_count'] ?? -1));

        $runnerReport = $this->readJson($root.'/runner_eval_report.json');
        $this->assertSame(108, (int) ($runnerReport['payload_count'] ?? 0));
        $this->assertSame(0, (int) ($runnerReport['expected_runner_failure_count'] ?? -1));
        $this->assertCount(108, (array) ($runnerReport['payload_hashes'] ?? []));

        $runner = new EnneagramResultPageAgentBatchRunner;
        $sourceLedgerRow = $this->sourceLedgerRow('batch_1r_c_asset_stream');
        $assetKeys = [];

        foreach ((array) ($assetsDoc['assets'] ?? []) as $asset) {
            $this->assertIsArray($asset);
            $assetKeys[] = (string) ($asset['asset_key'] ?? '');
            $this->assertSame('fap.enneagram.result_page.batch_asset.v0.1', $asset['schema_version'] ?? null);
            $this->assertSame('not_runtime', $asset['runtime_use'] ?? null);
            $this->assertFalse((bool) ($asset['production_use_allowed'] ?? true));
            $this->assertSame('1R-C', $asset['batch_scope'] ?? null);
            $this->assertSame('low_resonance_response', $asset['category'] ?? null);
            $this->assertNotSame('', (string) data_get($asset, 'routing_metadata.branch_metadata.objection_axis'));
            $this->assertSame('batch_1r_c_asset_stream', data_get($asset, 'source_trace.primary_source_id'));
            $this->assertAssetHasNoBlockedMaterial($asset);

            $result = $runner->run([
                'source_ledger_row' => $sourceLedgerRow,
                'target_module' => [
                    'module_key' => (string) ($asset['module_key'] ?? ''),
                    'result_type' => (string) ($asset['result_type'] ?? ''),
                    'scope' => '1R-C',
                    'additional_source_ids' => (array) data_get($asset, 'source_trace.source_ids', []),
                ],
                'public_payload' => (array) ($asset['public_payload'] ?? []),
                'forbidden_claim_policy' => [
                    'families' => [
                        'diagnosis_clinical_treatment',
                        'hiring_employment_screening',
                        'final_typing_you_are_this_type',
                        'fixed_type_certainty',
                        'e105_fc144_score_comparison',
                        'fc144_more_accurate_or_replacement_result',
                        'success_salary_performance_prediction',
                        'private_result_identifier_path_or_vector_leakage',
                    ],
                ],
            ]);

            $this->assertTrue((bool) ($result['ok'] ?? false), 'Runner failed for '.(string) ($asset['asset_key'] ?? 'unknown'));
            $this->assertSame(0, (int) data_get($result, 'source_mapping_report.source_mapping_failure_count', -1));
            $this->assertSame(0, (int) data_get($result, 'safety_report.metadata_leakage_hit_count', -1));
            $this->assertSame(0, (int) data_get($result, 'safety_report.forbidden_claim_hit_count', -1));
            $this->assertSame(0, (int) data_get($result, 'safety_report.fc144_boundary_violation_count', -1));
        }

        $this->assertCount(108, array_unique($assetKeys));
        $rollback = (string) file_get_contents($root.'/rollback_plan.md');
        $this->assertStringContainsString('No database rollback', $rollback);
        $this->assertStringContainsString('runtime activation rollback', $rollback);
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLedgerRow(string $sourceId): array
    {
        $ledger = $this->readJson(base_path('content_assets/enneagram/result_page/source_ledger/source_ledger.json'));
        foreach ((array) ($ledger['sources'] ?? []) as $source) {
            if (is_array($source) && ($source['source_id'] ?? null) === $sourceId) {
                return $source;
            }
        }

        $this->fail('Missing source ledger row: '.$sourceId);
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
     * @param  array<string,mixed>  $asset
     */
    private function assertAssetHasNoBlockedMaterial(array $asset): void
    {
        $encoded = mb_strtolower(json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        foreach ([
            'attempt_id',
            'attempt_uuid',
            'private_url',
            'private_path',
            'raw_score',
            'raw_scores',
            'raw_score_vector',
            'domain_vector',
            'facet_vector',
            'editor_note',
            'qa_note',
            'selection_guidance',
            'internal_metadata',
            '/users/',
            '/private/tmp/',
            'diagnosis',
            'clinical',
            'therapy',
            'treatment',
            'hiring',
            'employment screening',
            'you are this type',
            'fixed type',
            'fc144 is more accurate',
            'fc144 replaces',
            'salary prediction',
            'performance prediction',
            '诊断',
            '治疗',
            '招聘',
            '雇佣筛选',
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
