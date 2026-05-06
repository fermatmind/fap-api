<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\Cms;

use Tests\TestCase;

final class BigFiveV2CmsGitSyncPolicyTest extends TestCase
{
    private const PACKAGE_DIR = 'content_assets/big5/result_page_v2/governance/cms_git_sync_policy_v0_1';

    public function test_git_sync_policy_declares_git_backed_runtime_source_of_truth(): void
    {
        $manifest = $this->jsonFile(self::PACKAGE_DIR.'/manifest.json');
        $policy = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_policy_v0_1.json');

        $this->assertSame('editorial_governance', $manifest['mode'] ?? null);
        $this->assertTrue((bool) ($manifest['git_backed_source_of_truth'] ?? false));
        $this->assertTrue((bool) ($manifest['cms_draft_only'] ?? false));
        $this->assertFalse((bool) ($manifest['direct_runtime_publish_allowed'] ?? true));
        $this->assertTrue((bool) ($policy['git_backed_source_of_truth'] ?? false));
        $this->assertTrue((bool) ($policy['cms_principles']['draft_only'] ?? false));
        $this->assertTrue((bool) ($policy['cms_principles']['export_only'] ?? false));
        $this->assertFalse((bool) ($policy['cms_principles']['runtime_owner'] ?? true));
        $this->assertFalse((bool) ($policy['cms_principles']['direct_runtime_publish_allowed'] ?? true));
    }

    public function test_policy_requires_release_import_runtime_preview_and_rollback_linkage(): void
    {
        $policy = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_policy_v0_1.json');

        $this->assertSame('required_before_import', $policy['required_linkage']['release_snapshot'] ?? null);
        $this->assertSame('required_before_runtime_gate', $policy['required_linkage']['import_gate'] ?? null);
        $this->assertSame('required_before_exposure', $policy['required_linkage']['runtime_gate'] ?? null);
        $this->assertSame('version_pinned_and_isolated', $policy['required_linkage']['preview'] ?? null);
        $this->assertSame('release_snapshot_revert', $policy['required_linkage']['rollback'] ?? null);
        $this->assertStringContainsString(
            'cms_release_linkage_v0_1',
            (string) ($policy['linked_packages']['release_linkage'] ?? '')
        );
        $this->assertStringContainsString(
            'cms_rollback_audit_v0_1',
            (string) ($policy['linked_packages']['rollback_audit'] ?? '')
        );
    }

    public function test_policy_defines_no_go_conditions_and_final_verdicts(): void
    {
        $policy = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_policy_v0_1.json');
        $goNoGo = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_go_no_go_v0_1.json');

        foreach ([
            'cms_direct_runtime_publish',
            'cms_runtime_payload_mutation',
            'release_snapshot_bypass',
            'import_gate_bypass',
            'runtime_gate_bypass',
            'preview_runtime_mismatch',
            'runtime_payload_drift',
            'production_rollout_enablement',
            'dynamic_norm_engine_attachment',
            'content_pack_modification',
        ] as $condition) {
            $this->assertContains($condition, $policy['no_go_conditions'] ?? []);
        }

        $this->assertSame('go', $policy['final_verdict']['editorial_governance_layer'] ?? null);
        $this->assertSame('no_go', $policy['final_verdict']['cms_runtime_ownership'] ?? null);
        $this->assertSame('no_go', $policy['final_verdict']['production_rollout'] ?? null);
        $this->assertSame('go_for_editorial_governance_only', $goNoGo['cms_rollout_verdict'] ?? null);
        $this->assertFalse((bool) ($goNoGo['cms_runtime_ownership'] ?? true));
        $this->assertFalse((bool) ($goNoGo['direct_runtime_publish_allowed'] ?? true));
        $this->assertFalse((bool) ($goNoGo['dynamic_norm_engine_attachment'] ?? true));
    }

    public function test_policy_does_not_expose_forbidden_metadata_or_enable_runtime(): void
    {
        $payload = json_encode([
            $this->jsonFile(self::PACKAGE_DIR.'/manifest.json'),
            $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_policy_v0_1.json'),
            $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_git_sync_go_no_go_v0_1.json'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'runtime_use',
            'production_use_allowed',
            'review_status',
            'qa_notes',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $payload, $forbiddenKey);
        }

        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    public function test_sha256sums_are_reproducible(): void
    {
        foreach (explode("\n", trim((string) file_get_contents(base_path(self::PACKAGE_DIR.'/SHA256SUMS')))) as $line) {
            [$expectedHash, $fileName] = preg_split('/\s+/', trim($line), 2);
            $this->assertSame(
                $expectedHash,
                hash_file('sha256', base_path(self::PACKAGE_DIR.'/'.$fileName)),
                $fileName
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true);

        return is_array($decoded) ? $decoded : [];
    }
}
