<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2ControlledPilotGateTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/controlled_pilot_gate/v0_1';

    public function test_controlled_pilot_gate_is_advisory_only(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_gate_v0_1.json');
        $summary = $this->jsonFile('big5_controlled_pilot_gate_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('controlled_pilot_gate_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_controlled_pilot_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_gate_requires_readiness_runtime_analytics_seo_and_rollback_evidence(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_gate_v0_1.json');
        $summary = $this->jsonFile('big5_controlled_pilot_gate_summary_v0_1.json');
        $evidence = (array) ($report['required_evidence'] ?? []);

        $expectedKeys = [
            'readiness_artifact',
            'free_full_report_runtime_qa',
            'analytics_handoff',
            'seo_geo_control_handoff',
            'm7_pilot_gate',
            'rollback_kill_switch',
        ];

        $this->assertSame($expectedKeys, array_keys($evidence));
        $this->assertSame(6, $summary['required_evidence_count'] ?? null);

        foreach ($evidence as $key => $item) {
            $this->assertSame('pass', $item['status'] ?? null, $key);
            $this->assertNotSame('', $item['evidence'] ?? '', $key);
        }
    }

    public function test_allowlist_scope_is_explicit_and_percentage_rollout_is_blocked(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_gate_v0_1.json');
        $summary = $this->jsonFile('big5_controlled_pilot_gate_summary_v0_1.json');

        $expectedDimensions = [
            'attempt',
            'user',
            'anonymous_session',
            'organization',
            'tenant',
            'form',
            'locale',
            'scale',
        ];

        $this->assertSame('allowlist_only', data_get($report, 'allowlist_scope_contract.mode'));
        $this->assertSame($expectedDimensions, data_get($report, 'allowlist_scope_contract.scope_dimensions'));
        $this->assertSame($expectedDimensions, $summary['scope_dimensions'] ?? null);
        $this->assertSame(8, $summary['scope_dimension_count'] ?? null);
        $this->assertFalse((bool) data_get($report, 'allowlist_scope_contract.percentage_rollout_allowed'));
        $this->assertFalse((bool) data_get($report, 'allowlist_scope_contract.public_rollout_allowed'));
        $this->assertContains('full_production_rollout', $report['blocked_modes'] ?? []);
    }

    public function test_rollback_and_kill_switch_controls_fail_closed(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_gate_v0_1.json');

        foreach (($report['rollback_controls'] ?? []) as $key => $value) {
            $this->assertTrue((bool) $value, $key);
        }

        foreach (($report['kill_switch_evidence'] ?? []) as $key => $decision) {
            $this->assertSame('deny', $decision, $key);
        }

        $this->assertContains('separate_runtime_activation_request_required', $report['activation_blockers'] ?? []);
        $this->assertContains('no_production_rollout_in_this_package', $report['activation_blockers'] ?? []);
    }

    public function test_handoff_keeps_backend_authority_and_no_runtime_or_production_mutation(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_gate_v0_1.json');
        $summary = $this->jsonFile('big5_controlled_pilot_gate_summary_v0_1.json');

        foreach (($report['handoff_boundaries'] ?? []) as $key => $value) {
            $this->assertFalse((bool) $value, $key);
        }

        foreach (($summary['negative_guarantees'] ?? []) as $key => $value) {
            $this->assertFalse((bool) $value, $key);
        }

        $this->assertSame(0, $summary['share_safety_missing_count'] ?? null);
        $this->assertSame(0, $summary['validation_error_count'] ?? null);
        $this->assertSame(0, $summary['leak_hit_count'] ?? null);
        $this->assertTrue((bool) ($summary['backend_authority'] ?? false));
        $this->assertFalse((bool) ($summary['frontend_copy_added'] ?? true));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
    }

    public function test_artifacts_are_redacted_and_do_not_store_private_or_internal_tokens(): void
    {
        $serialized = json_encode([
            $this->jsonFile('big5_controlled_pilot_gate_v0_1.json'),
            $this->jsonFile('big5_controlled_pilot_gate_summary_v0_1.json'),
            (string) file_get_contents(base_path(self::BASE_PATH.'/README.md')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'attempt_id',
            'private_url',
            'report_json',
            'report_full_json',
            'report_free_json',
            'Big Five Report Engine',
            'PR3B',
            'AttemptReadController',
            'payload',
            'registry',
            'raw_score',
            'raw_scores',
            'score_vector',
            'percentile',
            'percentiles',
            'internal_metadata',
            '[object Object]',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $serialized, $forbiddenTerm);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
