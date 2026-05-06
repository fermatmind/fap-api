<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class RolloutPolicyTest extends TestCase
{
    private const POLICY_PATH = 'content_assets/big5/result_page_v2/governance/production_rollout_policy_v0_1';

    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/production_rollout_policy/v0_1';

    private const REQUIRED_FIELDS = [
        'rollout_allowed',
        'rollout_freeze_window',
        'release_freeze_authority',
        'rollout_stage',
        'rollout_scope',
        'rollback_required',
        'incident_halt_required',
        'manual_approval_required',
    ];

    public function test_rollout_policy_package_exists_without_runtime_use(): void
    {
        $this->assertFileExists(base_path(self::POLICY_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/big5_v2_production_rollout_policy_v0_1.json'));

        $manifest = $this->jsonFile(self::POLICY_PATH, 'manifest.json');

        $this->assertSame('big5_v2_production_rollout_policy', $manifest['package'] ?? null);
        $this->assertSame('rollout_policy_schema', $manifest['mode'] ?? null);
        $this->assertProductionDisabled($manifest);
        $this->assertFalse((bool) ($manifest['rollout_allowed'] ?? true));
    }

    public function test_rollout_policy_defines_required_fields(): void
    {
        $policy = $this->policy();
        $fields = (array) ($policy['rollout_policy_fields'] ?? []);
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertSame(self::REQUIRED_FIELDS, array_keys($fields));
        $this->assertSame(self::REQUIRED_FIELDS, array_keys($defaultDecision));

        foreach (self::REQUIRED_FIELDS as $field) {
            $this->assertTrue((bool) ($fields[$field]['required'] ?? false), $field);
            $this->assertNotSame('', (string) ($fields[$field]['meaning'] ?? ''), $field);
        }
    }

    public function test_default_rollout_decision_is_frozen_and_zero_blast_radius(): void
    {
        $policy = $this->policy();
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertProductionDisabled($policy);
        $this->assertFalse((bool) ($policy['rollout_allowed'] ?? true));
        $this->assertFalse((bool) ($defaultDecision['rollout_allowed'] ?? true));
        $this->assertSame('frozen', $defaultDecision['rollout_freeze_window']['status'] ?? null);
        $this->assertSame('policy_only', $defaultDecision['rollout_stage'] ?? null);
        $this->assertSame('none', $defaultDecision['rollout_scope']['audience'] ?? null);
        $this->assertSame(0, $defaultDecision['rollout_scope']['percentage'] ?? null);
        $this->assertFalse((bool) ($defaultDecision['manual_approval_required']['human_production_approval'] ?? true));
    }

    public function test_freeze_controls_require_halt_and_reopen_authority(): void
    {
        $controls = (array) ($this->policy()['release_freeze_controls'] ?? []);

        $this->assertSame('off', $controls['rollout_default'] ?? null);
        $this->assertSame('active', $controls['freeze_default'] ?? null);
        $this->assertContains('metadata_leak_detected', $controls['halt_required_when'] ?? []);
        $this->assertContains('rollout_default_on_detected', $controls['halt_required_when'] ?? []);
        $this->assertContains('explicit_human_production_rollout_approval', $controls['reopen_requires'] ?? []);
        $this->assertContains('rollback_drill_evidence', $controls['reopen_requires'] ?? []);
    }

    public function test_qa_package_validates_rollout_policy_without_enablement(): void
    {
        $manifest = $this->jsonFile(self::QA_PATH, 'manifest.json');
        $validation = $this->jsonFile(self::QA_PATH, 'big5_v2_production_rollout_policy_validation_v0_1.json');
        $summary = $this->jsonFile(self::QA_PATH, 'big5_v2_production_rollout_policy_summary_v0_1.json');

        $this->assertSame('big5_v2_production_rollout_policy_qa', $manifest['package'] ?? null);
        $this->assertSame(self::REQUIRED_FIELDS, $validation['validated_fields'] ?? null);

        foreach ((array) ($validation['checks'] ?? []) as $check => $status) {
            $this->assertSame('pass', $status, (string) $check);
        }

        foreach ([$manifest, $validation, $summary] as $document) {
            $this->assertProductionDisabled($document);
            $this->assertFalse((bool) ($document['rollout_allowed'] ?? true));
        }

        $this->assertSame('NO-GO', $validation['production_decision']['status'] ?? null);
        $this->assertSame('NO-GO', $summary['final_decision'] ?? null);
        $this->assertSame(0, $summary['rollout_scope']['percentage'] ?? null);
        $this->assertFalse((bool) ($summary['scoring_changed'] ?? true));
        $this->assertFalse((bool) ($summary['content_bodies_changed'] ?? true));
        $this->assertFalse((bool) ($summary['cms_connected'] ?? true));
        $this->assertFalse((bool) ($summary['dynamic_norms_connected'] ?? true));
    }

    public function test_rollout_policy_files_do_not_enable_production_or_rollout(): void
    {
        foreach ([
            self::POLICY_PATH.'/big5_v2_production_rollout_policy_v0_1.json',
            self::POLICY_PATH.'/manifest.json',
            self::QA_PATH.'/big5_v2_production_rollout_policy_validation_v0_1.json',
            self::QA_PATH.'/big5_v2_production_rollout_policy_summary_v0_1.json',
            self::QA_PATH.'/manifest.json',
        ] as $relativePath) {
            $normalized = $this->normalizedFile($relativePath);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"rollout_allowed":true', $normalized, $relativePath);
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
    private function assertProductionDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        return $this->jsonFile(self::POLICY_PATH, 'big5_v2_production_rollout_policy_v0_1.json');
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
