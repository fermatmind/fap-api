<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhFinalVerify01Test extends TestCase
{
    #[Test]
    public function final_verify_report_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-en-zh-final-verify-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-FINAL-VERIFY-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_user_data_accessed'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));
    }

    #[Test]
    public function final_verify_keeps_remaining_gaps_explicit(): void
    {
        $payload = $this->payload();

        $this->assertIsArray($payload['p0_findings_remaining'] ?? null);
        $this->assertSame([], $payload['p0_findings_remaining']);
        $this->assertGreaterThanOrEqual(5, count($payload['p1_findings_remaining'] ?? []));
        $this->assertGreaterThanOrEqual(1, count($payload['p2_findings_remaining'] ?? []));
        $this->assertTrue((bool) data_get($payload, 'content_asset_state.remaining_human_review'));
        $this->assertSame(10, data_get($payload, 'article_asset_state.import_ready_review_required_count'));
        $this->assertSame(6, data_get($payload, 'article_asset_state.deferred_human_review_count'));
        $this->assertTrue((bool) data_get($payload, 'career_asset_state.career_job_translation_group_deferred'));
        $this->assertSame(72, data_get($payload, 'media_asset_state.career_guides_missing_og_image_count'));
    }

    #[Test]
    public function final_verify_sets_no_go_until_review_import_and_smoke_complete(): void
    {
        $payload = $this->payload();

        $this->assertTrue((bool) data_get($payload, 'result_report_asset_state.fail_closed_no_zh_fallback_preserved'));
        $this->assertFalse((bool) data_get($payload, 'result_report_asset_state.draft_assets_runtime_activated'));
        $this->assertTrue((bool) data_get($payload, 'deployment_requirement.deploy_required_for_runtime_effects'));
        $this->assertSame(
            'NO_GO_for_claiming_full_english_chinese_parity_until_human_review_imports_and_post_deploy_smoke_complete',
            $payload['final_go_no_go'] ?? null,
        );
        $this->assertSame(
            'global_en_zh_remaining_train_completed_with_deferred_human_review_assets',
            $payload['final_decision'] ?? null,
        );
        $this->assertSame('DEPLOY-READINESS｜Deploy GLOBAL EN/ZH remaining parity fixes', $payload['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-en-zh-final-verify-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
