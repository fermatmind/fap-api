<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecResultPageProductionRuntimeTest extends TestCase
{
    private const POLICY_PATH = 'content_assets/riasec/result_page_v2/governance/production_rollout_gate_v0_1';

    private const QA_PATH = 'content_assets/riasec/result_page_v2/qa/production_rollout_gate/v0_1';

    public function test_production_runtime_and_rollout_defaults_are_disabled(): void
    {
        $this->assertFalse((bool) config('riasec_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('riasec_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('riasec_result_page_v2.production_rollout_configured'));
        $this->assertFalse((bool) config('riasec_result_page_v2.production_rollout_manual_approval_granted'));
        $this->assertFalse((bool) config('riasec_result_page_v2.production_import_gate_passed'));
        $this->assertSame('disabled', config('riasec_result_page_v2.production_rollout_mode'));
    }

    public function test_rollout_gate_policy_is_manual_only_and_no_go(): void
    {
        $policy = $this->jsonFile(base_path(self::POLICY_PATH.'/riasec_result_page_v2_production_rollout_gate_policy_v0_1.json'));
        $validation = $this->jsonFile(base_path(self::QA_PATH.'/riasec_result_page_v2_production_rollout_gate_validation_v0_1.json'));

        $this->assertFalse((bool) ($policy['automatic_rollout_allowed'] ?? true));
        $this->assertTrue((bool) ($policy['manual_approval_required'] ?? false));
        $this->assertContains('rollback_kill_switch', $policy['required_before_rollout'] ?? []);
        $this->assertContains('post_deploy_smoke_procedure', $policy['required_before_rollout'] ?? []);
        $this->assertSame('NO_GO', $validation['production_decision'] ?? null);
        $this->assertSame('pass', $validation['validation_status'] ?? null);
    }

    public function test_rollout_gate_assets_do_not_enable_production(): void
    {
        foreach ([self::POLICY_PATH, self::QA_PATH] as $relativePath) {
            foreach (glob(base_path($relativePath.'/*')) ?: [] as $file) {
                if (! is_file($file)) {
                    continue;
                }

                $normalized = preg_replace('/\s+/', '', (string) file_get_contents($file));
                $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $file);
                $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $file);
                $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $file);
                $this->assertStringNotContainsString('"automatic_rollout_allowed":true', $normalized, $file);
                $this->assertStringNotContainsString('"cms_write_performed":true', $normalized, $file);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
