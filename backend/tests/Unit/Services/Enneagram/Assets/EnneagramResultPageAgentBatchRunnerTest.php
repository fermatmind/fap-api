<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentBatchRunner;
use Tests\TestCase;

final class EnneagramResultPageAgentBatchRunnerTest extends TestCase
{
    public function test_runner_returns_deterministic_payload_mapping_safety_diff_and_rollback_reports(): void
    {
        $runner = new EnneagramResultPageAgentBatchRunner;
        $input = $this->safeInput();

        $first = $runner->run($input);
        $second = $runner->run($input);

        $this->assertTrue((bool) ($first['ok'] ?? false));
        $this->assertSame('success', $first['status'] ?? null);
        $this->assertSame($first['payload_hash_sha256'], $second['payload_hash_sha256']);
        $this->assertSame($first['payload'], $second['payload']);
        $this->assertSame(EnneagramResultPageAgentBatchRunner::PAYLOAD_SCHEMA_VERSION, data_get($first, 'payload.schema_version'));
        $this->assertSame('not_runtime', data_get($first, 'payload.runtime_use'));
        $this->assertFalse((bool) data_get($first, 'payload.production_use_allowed', true));
        $this->assertSame(0, (int) data_get($first, 'source_mapping_report.source_mapping_failure_count', -1));
        $this->assertSame(0, (int) data_get($first, 'safety_report.metadata_leakage_hit_count', -1));
        $this->assertSame(0, (int) data_get($first, 'safety_report.forbidden_claim_hit_count', -1));
        $this->assertSame(0, (int) data_get($first, 'safety_report.fc144_boundary_violation_count', -1));
        $this->assertSame(['payload_created'], data_get($first, 'diff_report.changed_fields'));
        $this->assertFalse((bool) data_get($first, 'rollback_report.requires_database_rollback', true));
        $this->assertFalse((bool) data_get($first, 'rollback_report.requires_runtime_rollback', true));
    }

    public function test_runner_fails_closed_on_metadata_leakage(): void
    {
        $runner = new EnneagramResultPageAgentBatchRunner;
        $input = $this->safeInput();
        $input['public_payload']['attempt_id'] = 'blocked';

        $result = $runner->run($input);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertContains('safety_scan_failed', $result['errors']);
        $this->assertSame(1, (int) data_get($result, 'safety_report.metadata_leakage_hit_count'));
    }

    public function test_runner_fails_closed_on_forbidden_claim_and_fc144_boundary(): void
    {
        $runner = new EnneagramResultPageAgentBatchRunner;
        $input = $this->safeInput();
        $input['target_module']['module_key'] = 'fc144_second_lens';
        $input['public_payload']['body'] = 'FC144 is more accurate and replaces the current result.';

        $result = $runner->run($input);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertContains('safety_scan_failed', $result['errors']);
        $this->assertGreaterThan(0, (int) data_get($result, 'safety_report.forbidden_claim_hit_count'));
        $this->assertGreaterThan(0, (int) data_get($result, 'safety_report.fc144_boundary_violation_count'));
    }

    public function test_runner_requires_source_ledger_row_and_target_module(): void
    {
        $runner = new EnneagramResultPageAgentBatchRunner;

        $result = $runner->run([
            'source_ledger_row' => [],
            'target_module' => [],
            'public_payload' => [],
            'forbidden_claim_policy' => [],
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertContains('missing_source_ledger_row', $result['errors']);
        $this->assertContains('missing_target_module_key', $result['errors']);
        $this->assertContains('missing_public_payload', $result['errors']);
    }

    public function test_pilot_fixture_payload_can_be_evaluated_through_runner(): void
    {
        $runner = new EnneagramResultPageAgentBatchRunner;
        $payload = $this->readJson(base_path('content_assets/enneagram/result_page/pilot_asset_batch/v0_1/payloads/pilot_t1_baseline_reflection.json'));

        $result = $runner->run([
            'source_ledger_row' => $this->sourceLedgerRow('batch_1r_a_asset_stream'),
            'target_module' => [
                'module_key' => (string) ($payload['module_key'] ?? ''),
                'result_type' => (string) ($payload['result_type'] ?? ''),
                'scope' => 'pilot',
                'additional_source_ids' => (array) data_get($payload, 'source_trace.source_ids', []),
            ],
            'public_payload' => (array) ($payload['public_payload'] ?? []),
            'forbidden_claim_policy' => [
                'families' => [
                    'diagnosis_clinical_treatment',
                    'hiring_employment_screening',
                    'final_typing_you_are_this_type',
                    'fixed_type_certainty',
                    'e105_fc144_score_comparison',
                    'fc144_more_accurate_or_replacement_result',
                ],
            ],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertContains('batch_1r_a_asset_stream', data_get($result, 'payload.source_trace.source_ids'));
    }

    /**
     * @return array<string,mixed>
     */
    private function safeInput(): array
    {
        return [
            'source_ledger_row' => $this->sourceLedgerRow('batch_1r_a_asset_stream'),
            'target_module' => [
                'module_key' => 'baseline_reflection',
                'result_type' => 'type_1',
                'scope' => 'pilot',
                'additional_source_ids' => [
                    'phase8b_candidate_baseline_a9fd',
                    'forbidden_claim_policy',
                ],
            ],
            'public_payload' => [
                'heading' => '把观察转成一个下一步动作',
                'body' => '先选择一处最需要整理的情境，再写下可以在今天完成的小动作。',
            ],
            'forbidden_claim_policy' => [
                'families' => [
                    'diagnosis_clinical_treatment',
                    'hiring_employment_screening',
                    'final_typing_you_are_this_type',
                    'fixed_type_certainty',
                    'e105_fc144_score_comparison',
                    'fc144_more_accurate_or_replacement_result',
                ],
            ],
        ];
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
}
