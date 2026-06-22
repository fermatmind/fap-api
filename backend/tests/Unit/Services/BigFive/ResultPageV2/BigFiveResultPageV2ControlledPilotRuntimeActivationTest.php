<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2ControlledPilotRuntimeActivationTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/controlled_pilot_runtime_activation/v0_1';

    public function test_runtime_activation_package_is_allowlist_only_and_not_production_ready(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_runtime_activation_v0_1.json');
        $summary = $this->jsonFile('big5_controlled_pilot_runtime_activation_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('controlled_pilot_runtime_activation', $document['mode'] ?? null);
            $this->assertSame('staging_only', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_controlled_pilot_runtime'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertSame('allowlist_only', $document['allowed_mode'] ?? null);
        }

        $this->assertTrue((bool) data_get($report, 'required_runtime_controls.production_percentage_bucket_blocked'));
        $this->assertTrue((bool) data_get($report, 'required_runtime_controls.fail_closed_on_missing_or_denied_allowlist'));
        $this->assertTrue((bool) data_get($report, 'required_runtime_controls.legacy_fallback_retained'));
    }

    public function test_allowlist_scope_is_explicit_but_live_values_are_not_committed(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_runtime_activation_v0_1.json');

        $this->assertSame([
            'attempt',
            'user',
            'anonymous_session',
            'organization',
            'tenant',
            'form',
            'locale',
            'scale',
        ], data_get($report, 'allowlist_scope_shape.dimensions'));
        $this->assertFalse((bool) data_get($report, 'allowlist_scope_shape.live_values_committed', true));
        $this->assertSame('BIG5_OCEAN', data_get($report, 'allowlist_scope_shape.required_scale'));
    }

    public function test_activation_package_defers_production_cms_seo_deploy_and_live_smoke(): void
    {
        $report = $this->jsonFile('big5_controlled_pilot_runtime_activation_v0_1.json');

        foreach ((array) ($report['runtime_paths'] ?? []) as $key => $value) {
            if ($key === 'backend_authority') {
                $this->assertTrue((bool) $value, $key);

                continue;
            }

            $this->assertFalse((bool) $value, $key);
        }

        foreach ([
            'live_allowlist_values',
            'post_activation_live_smoke',
            'production_import_activation',
            'production_rollout',
            'cms_publish',
            'seo_runtime_or_search_submission',
            'manual_or_production_deploy',
        ] as $deferred) {
            $this->assertContains($deferred, $report['deferred_until_separate_authorization'] ?? []);
        }
    }

    public function test_artifacts_are_redacted_and_do_not_store_private_or_internal_terms(): void
    {
        $serialized = json_encode([
            $this->jsonFile('big5_controlled_pilot_runtime_activation_v0_1.json'),
            $this->jsonFile('big5_controlled_pilot_runtime_activation_summary_v0_1.json'),
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
