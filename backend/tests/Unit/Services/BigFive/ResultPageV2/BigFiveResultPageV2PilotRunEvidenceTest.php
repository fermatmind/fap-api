<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2PilotRunEvidenceTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/pilot_run_evidence/v0_1';

    public function test_pilot_run_evidence_is_allowlist_only_and_reviewed(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $evidence = $this->jsonFile('big5_v2_pilot_run_evidence_v0_1.json');
        $validation = $this->jsonFile('big5_v2_pilot_run_validation_v0_1.json');
        $rollback = $this->jsonFile('big5_v2_pilot_run_rollback_v0_1.json');

        foreach ([$manifest, $evidence, $validation, $rollback] as $document) {
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_pilot'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
            $this->assertTrue((bool) ($document['pilot_run_reviewed'] ?? false));
            $this->assertSame('GO_ALLOWLIST_REVIEWED', $document['pilot_run_decision'] ?? null);
            $this->assertSame('NO_GO', $document['production_decision'] ?? null);
        }

        $this->assertSame('public_pilot_allowlist_only', data_get($evidence, 'allowlist_configuration_shape.pilot_gate'));
        $this->assertSame('result_page_only', data_get($evidence, 'allowlist_configuration_shape.surface_scope'));
        $this->assertSame(0, data_get($evidence, 'allowlist_configuration_shape.rollout_percentage'));
        $this->assertFalse((bool) data_get($evidence, 'allowlist_configuration_shape.production_percentage_enabled', true));
        $this->assertSame(0, data_get($evidence, 'allowlist_configuration_shape.production_max_percentage'));
    }

    public function test_pilot_run_evidence_records_redacted_sample_scope_and_no_production_decision(): void
    {
        $evidence = $this->jsonFile('big5_v2_pilot_run_evidence_v0_1.json');
        $validation = $this->jsonFile('big5_v2_pilot_run_validation_v0_1.json');

        $this->assertSame('fresh_anonymous_big5_live_result', data_get($evidence, 'sample_scope.sample_kind'));
        $this->assertSame('redacted_result_only', data_get($evidence, 'sample_scope.sample_boundary'));
        $this->assertFalse((bool) data_get($evidence, 'sample_scope.contains_real_user_data', true));
        $this->assertFalse((bool) data_get($evidence, 'sample_scope.contains_private_links', true));
        $this->assertFalse((bool) data_get($evidence, 'sample_scope.contains_raw_measurements', true));
        $this->assertFalse((bool) data_get($evidence, 'sample_scope.contains_public_rank_values', true));
        $this->assertFalse((bool) data_get($evidence, 'sample_scope.contains_unreviewed_content', true));

        $this->assertSame('pass', data_get($validation, 'checks.sample_scope_redacted'));
        $this->assertSame('pass', data_get($validation, 'checks.frontend_copy_not_added'));
        $this->assertSame('pass', data_get($validation, 'checks.runtime_defaults_not_changed'));
        $this->assertSame('pass', data_get($validation, 'checks.m8_ops_deferred'));
        $this->assertContains('production_ops_metrics_not_connected', $validation['hard_blockers_before_production'] ?? []);
    }

    public function test_rollback_evidence_keeps_pilot_reversible_without_new_runtime_writes(): void
    {
        $rollback = $this->jsonFile('big5_v2_pilot_run_rollback_v0_1.json');

        foreach ([
            'disable_public_pilot',
            'clear_allowlist',
            'set_rollout_percentage_to_zero',
            'keep_production_percentage_disabled',
            'keep_production_max_percentage_zero',
            'keep_result_page_scope_only',
        ] as $control) {
            $this->assertTrue((bool) data_get($rollback, "rollback_controls.{$control}"), $control);
        }

        foreach ((array) ($rollback['kill_switch_evidence'] ?? []) as $decision) {
            $this->assertSame('deny', $decision);
        }

        $this->assertFalse((bool) data_get($rollback, 'rollback_validation.requires_database_write', true));
        $this->assertFalse((bool) data_get($rollback, 'rollback_validation.requires_content_import', true));
        $this->assertFalse((bool) data_get($rollback, 'rollback_validation.requires_frontend_change', true));
        $this->assertFalse((bool) data_get($rollback, 'rollback_validation.requires_ops_panel_change', true));
        $this->assertTrue((bool) data_get($rollback, 'rollback_validation.preserves_legacy_fallback'));
    }

    public function test_pilot_run_evidence_does_not_include_sensitive_live_sample_tokens(): void
    {
        $forbidden = [
            'private url',
            'attempt id',
            'big five report engine',
            'pr3b',
            'attemptreadcontroller',
            'payload',
            'registry',
            'type_code',
            'fixed_type',
            'user_confirmed_type',
            'raw scores',
            'shareable percentiles',
            'internal metadata',
            '[object object]',
        ];

        foreach (glob(base_path(self::BASE_PATH.'/*')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $contents = strtolower((string) file_get_contents($file));

            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString($token, $contents, "{$token} leaked in {$file}");
            }
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertCount(5, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::BASE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
