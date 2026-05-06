<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class ProductionGovernanceTest extends TestCase
{
    private const POLICY_PATH = 'content_assets/big5/result_page_v2/governance/production_policy_v0_1';

    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/production_governance_policy/v0_1';

    private const REQUIRED_FIELDS = [
        'approved_for_production',
        'rejected_for_production',
        'release_candidate',
        'required_rendered_qa',
        'required_release_evidence',
        'required_snapshot',
        'required_all_surface_pass',
        'rollback_required',
        'release_go_no_go_required',
    ];

    public function test_production_governance_policy_package_exists(): void
    {
        $this->assertFileExists(base_path(self::POLICY_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::POLICY_PATH.'/big5_v2_production_governance_policy_v0_1.json'));

        $manifest = $this->jsonFile(self::POLICY_PATH, 'manifest.json');

        $this->assertSame('big5_v2_production_governance_policy', $manifest['package'] ?? null);
        $this->assertSame('schema_only', $manifest['mode'] ?? null);
        $this->assertSame('not_runtime', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($manifest['production_rollout_enabled'] ?? true));
    }

    public function test_policy_schema_defines_required_governance_fields(): void
    {
        $policy = $this->policy();
        $governanceFields = (array) ($policy['governance_fields'] ?? []);
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertSame(self::REQUIRED_FIELDS, array_keys($governanceFields));
        $this->assertSame(self::REQUIRED_FIELDS, array_keys($defaultDecision));

        foreach (self::REQUIRED_FIELDS as $field) {
            $this->assertTrue((bool) ($governanceFields[$field]['required'] ?? false), $field);
            $this->assertNotSame('', (string) ($governanceFields[$field]['meaning'] ?? ''), $field);
        }
    }

    public function test_default_decision_blocks_production(): void
    {
        $policy = $this->policy();
        $defaultDecision = (array) ($policy['default_decision'] ?? []);

        $this->assertSame('production_governance_policy_schema', $policy['mode'] ?? null);
        $this->assertSame('not_runtime', $policy['runtime_use'] ?? null);
        $this->assertFalse((bool) ($policy['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($policy['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($policy['production_rollout_enabled'] ?? true));

        $this->assertFalse((bool) ($defaultDecision['approved_for_production'] ?? true));
        $this->assertTrue((bool) ($defaultDecision['rejected_for_production'] ?? false));
        $this->assertFalse((bool) ($defaultDecision['release_candidate'] ?? true));
        $this->assertSame('NO-GO', $defaultDecision['release_go_no_go_required']['explicit_decision'] ?? null);
        $this->assertTrue((bool) ($defaultDecision['release_go_no_go_required']['human_approval_required'] ?? false));
        $this->assertSame('disabled', $defaultDecision['release_go_no_go_required']['production_enablement_status'] ?? null);
    }

    public function test_required_evidence_sections_are_fail_closed(): void
    {
        $defaultDecision = (array) ($this->policy()['default_decision'] ?? []);

        foreach ([
            'required_rendered_qa',
            'required_release_evidence',
            'required_snapshot',
            'required_all_surface_pass',
            'rollback_required',
        ] as $section) {
            $this->assertNotSame([], $defaultDecision[$section] ?? [], $section);
            $this->assertStringContainsString('required_not_provided', json_encode($defaultDecision[$section], JSON_THROW_ON_ERROR), $section);
        }
    }

    public function test_qa_package_validates_schema_without_production_enablement(): void
    {
        $validation = $this->jsonFile(self::QA_PATH, 'big5_v2_production_governance_policy_validation_v0_1.json');
        $summary = $this->jsonFile(self::QA_PATH, 'big5_v2_production_governance_policy_summary_v0_1.json');

        $this->assertSame(self::REQUIRED_FIELDS, $validation['validated_fields'] ?? null);

        foreach ((array) ($validation['checks'] ?? []) as $check => $status) {
            $this->assertSame('pass', $status, (string) $check);
        }

        foreach ([$validation, $summary] as $document) {
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }

        $this->assertSame('NO-GO', $validation['production_decision']['status'] ?? null);
        $this->assertSame('NO-GO', $summary['final_decision'] ?? null);
        $this->assertFalse((bool) ($summary['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($summary['scoring_changed'] ?? true));
        $this->assertFalse((bool) ($summary['content_bodies_changed'] ?? true));
    }

    public function test_policy_and_qa_files_do_not_enable_production(): void
    {
        foreach ([
            self::POLICY_PATH.'/big5_v2_production_governance_policy_v0_1.json',
            self::POLICY_PATH.'/manifest.json',
            self::QA_PATH.'/big5_v2_production_governance_policy_validation_v0_1.json',
            self::QA_PATH.'/big5_v2_production_governance_policy_summary_v0_1.json',
        ] as $relativePath) {
            $json = file_get_contents(base_path($relativePath));
            $this->assertIsString($json, $relativePath);
            $normalized = preg_replace('/\s+/', '', $json);
            $this->assertIsString($normalized);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"approved_for_production":true', $normalized, $relativePath);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $relativePath);
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
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        return $this->jsonFile(self::POLICY_PATH, 'big5_v2_production_governance_policy_v0_1.json');
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
