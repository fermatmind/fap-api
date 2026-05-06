<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class NormEligibilityTest extends TestCase
{
    private const POLICY_PATH = 'content_assets/big5/result_page_v2/governance/norm_eligibility_policy_v0_1';

    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/norm_eligibility_policy/v0_1';

    private const REQUIRED_FIELDS = [
        'norm_eligible',
        'norm_excluded',
        'exclusion_reason',
        'quality_based_exclusion',
        'replay_exclusion',
        'fixture_staging_exclusion',
        'deleted_purged_exclusion',
        'unsupported_schema_exclusion',
        'future_consent_requirement',
    ];

    public function test_norm_eligibility_policy_package_exists_without_runtime_use(): void
    {
        $this->assertFileExists(base_path(self::POLICY_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/big5_v2_norm_eligibility_policy_v0_1.json'));

        $manifest = $this->jsonFile(self::POLICY_PATH, 'manifest.json');

        $this->assertSame('big5_v2_norm_eligibility_policy', $manifest['package'] ?? null);
        $this->assertSame('norm_eligibility_policy_schema', $manifest['mode'] ?? null);
        $this->assertSafetyDefaults($manifest);
        $this->assertFalse((bool) ($manifest['norm_eligible'] ?? true));
        $this->assertTrue((bool) ($manifest['norm_excluded'] ?? false));
    }

    public function test_norm_eligibility_policy_defines_required_fields(): void
    {
        $policy = $this->policy();
        $fields = (array) ($policy['eligibility_policy_fields'] ?? []);
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertSame(self::REQUIRED_FIELDS, array_keys($fields));
        $this->assertSame(self::REQUIRED_FIELDS, array_keys($defaultDecision));

        foreach (self::REQUIRED_FIELDS as $field) {
            $this->assertTrue((bool) ($fields[$field]['required'] ?? false), $field);
            $this->assertNotSame('', (string) ($fields[$field]['meaning'] ?? ''), $field);
        }
    }

    public function test_default_decision_is_fail_closed_and_excluded(): void
    {
        $policy = $this->policy();
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertSafetyDefaults($policy);
        $this->assertFalse((bool) ($policy['norm_eligible'] ?? true));
        $this->assertTrue((bool) ($policy['norm_excluded'] ?? false));
        $this->assertFalse((bool) ($defaultDecision['norm_eligible'] ?? true));
        $this->assertTrue((bool) ($defaultDecision['norm_excluded'] ?? false));
        $this->assertContains('policy_default_excluded', $defaultDecision['exclusion_reason'] ?? []);
        $this->assertContains('append_only_observation_layer_missing', $defaultDecision['exclusion_reason'] ?? []);
        $this->assertContains('future_consent_boundary_missing', $defaultDecision['exclusion_reason'] ?? []);
    }

    public function test_quality_and_replay_exclusions_are_defined(): void
    {
        $defaultDecision = (array) ($this->policy()['default_decision'] ?? []);
        $quality = (array) ($defaultDecision['quality_based_exclusion'] ?? []);
        $replay = (array) ($defaultDecision['replay_exclusion'] ?? []);

        $this->assertSame(['A', 'B'], $quality['include_quality_levels'] ?? null);
        $this->assertSame(['C', 'D'], $quality['exclude_quality_levels'] ?? null);
        $this->assertContains('ATTENTION_CHECK_FAILED', $quality['exclude_flags'] ?? []);
        $this->assertContains('SPEEDING', $quality['exclude_flags'] ?? []);
        $this->assertContains('STRAIGHTLINING', $quality['exclude_flags'] ?? []);
        $this->assertSame('fail_closed_exclude', $quality['missing_quality_behavior'] ?? null);

        $this->assertTrue((bool) ($replay['requires_observation_idempotency_key'] ?? false));
        $this->assertSame('exclude_duplicate', $replay['duplicate_behavior'] ?? null);
        $this->assertSame('fail_closed_exclude', $replay['replay_behavior'] ?? null);
        $this->assertSame('hard_blocker', $replay['mutable_observation_behavior'] ?? null);
    }

    public function test_source_lifecycle_schema_and_consent_exclusions_are_defined(): void
    {
        $defaultDecision = (array) ($this->policy()['default_decision'] ?? []);
        $source = (array) ($defaultDecision['fixture_staging_exclusion'] ?? []);
        $lifecycle = (array) ($defaultDecision['deleted_purged_exclusion'] ?? []);
        $schema = (array) ($defaultDecision['unsupported_schema_exclusion'] ?? []);
        $consent = (array) ($defaultDecision['future_consent_requirement'] ?? []);

        foreach (['exclude_fixtures', 'exclude_staging_only', 'exclude_internal_qa', 'exclude_synthetic_samples'] as $key) {
            $this->assertTrue((bool) ($source[$key] ?? false), $key);
        }

        foreach (['exclude_deleted', 'exclude_purged', 'exclude_lifecycle_revoked', 'exclude_retention_expired'] as $key) {
            $this->assertTrue((bool) ($lifecycle[$key] ?? false), $key);
        }

        foreach ([
            'requires_observation_schema_version',
            'requires_score_version',
            'requires_content_version',
            'requires_score_trace_hash',
        ] as $key) {
            $this->assertTrue((bool) ($schema[$key] ?? false), $key);
        }

        foreach ([
            'required_before_real_user_capture',
            'requires_norm_capture_scope',
            'requires_revoke_handling',
            'requires_retention_policy',
            'requires_small_cell_suppression',
        ] as $key) {
            $this->assertTrue((bool) ($consent[$key] ?? false), $key);
        }
    }

    public function test_qa_package_validates_policy_without_enablement(): void
    {
        $manifest = $this->jsonFile(self::QA_PATH, 'manifest.json');
        $validation = $this->jsonFile(self::QA_PATH, 'big5_v2_norm_eligibility_policy_validation_v0_1.json');
        $summary = $this->jsonFile(self::QA_PATH, 'big5_v2_norm_eligibility_policy_summary_v0_1.json');

        $this->assertSame('big5_v2_norm_eligibility_policy_qa', $manifest['package'] ?? null);
        $this->assertSame(self::REQUIRED_FIELDS, $validation['validated_fields'] ?? null);

        foreach ((array) ($validation['checks'] ?? []) as $check => $status) {
            $this->assertSame('pass', $status, (string) $check);
        }

        foreach ([$manifest, $validation, $summary] as $document) {
            $this->assertSafetyDefaults($document);
            $this->assertFalse((bool) ($document['norm_eligible'] ?? true));
            $this->assertTrue((bool) ($document['norm_excluded'] ?? false));
        }

        $this->assertSame('NO-GO', $validation['norm_decision']['status'] ?? null);
        $this->assertSame('NO-GO', $summary['final_decision'] ?? null);
        $this->assertFalse((bool) ($summary['scoring_changed'] ?? true));
        $this->assertFalse((bool) ($summary['runtime_behavior_changed'] ?? true));
        $this->assertFalse((bool) ($summary['content_bodies_changed'] ?? true));
        $this->assertFalse((bool) ($summary['content_packs_changed'] ?? true));
        $this->assertFalse((bool) ($summary['cms_connected'] ?? true));
        $this->assertFalse((bool) ($summary['production_rollout_enabled_by_package'] ?? true));
    }

    public function test_policy_files_do_not_enable_public_or_runtime_norms(): void
    {
        foreach ([
            self::POLICY_PATH.'/big5_v2_norm_eligibility_policy_v0_1.json',
            self::POLICY_PATH.'/manifest.json',
            self::QA_PATH.'/big5_v2_norm_eligibility_policy_validation_v0_1.json',
            self::QA_PATH.'/big5_v2_norm_eligibility_policy_summary_v0_1.json',
            self::QA_PATH.'/manifest.json',
        ] as $relativePath) {
            $normalized = $this->normalizedFile($relativePath);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"dynamic_norm_engine_attached":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"public_percentile_display_enabled":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"norm_eligible":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"norm_excluded":false', $normalized, $relativePath);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        foreach ([self::POLICY_PATH, self::QA_PATH] as $basePath) {
            $entries = file(base_path($basePath.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($entries, $basePath);
            $this->assertNotSame([], $entries, $basePath);

            foreach ($entries as $entry) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
                [$expectedHash, $fileName] = explode('  ', $entry, 2);
                $path = base_path($basePath.'/'.$fileName);

                $this->assertFileExists($path);
                $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertSafetyDefaults(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['dynamic_norm_engine_attached'] ?? true));
        $this->assertFalse((bool) ($document['public_percentile_display_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        return $this->jsonFile(self::POLICY_PATH, 'big5_v2_norm_eligibility_policy_v0_1.json');
    }

    private function normalizedFile(string $relativePath): string
    {
        $contents = file_get_contents(base_path($relativePath));
        $this->assertIsString($contents, $relativePath);
        $normalized = preg_replace('/\s+/', '', $contents);
        $this->assertIsString($normalized, $relativePath);

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $basePath, string $fileName): array
    {
        $json = file_get_contents(base_path($basePath.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
