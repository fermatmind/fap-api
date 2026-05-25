<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResultMediaAssetBatch01Test extends TestCase
{
    #[Test]
    public function generated_result_media_batch_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-en-zh-result-media-asset-batch-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-RESULT-MEDIA-ASSET-BATCH-01', $payload['task'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['mass_report_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));
        $this->assertFalse((bool) ($payload['production_user_data_accessed'] ?? true));
    }

    #[Test]
    public function result_and_media_gaps_remain_explicit_without_runtime_activation(): void
    {
        $payload = $this->payload();

        $this->assertSame(14, data_get($payload, 'result_report_asset_state.riasec.deferred_human_review_assets'));
        $this->assertSame(8, data_get($payload, 'result_report_asset_state.mbti.missing_en_asset_keys_count'));
        $this->assertSame(6, data_get($payload, 'result_report_asset_state.mbti.deferred_assets_count'));
        $this->assertSame(0, data_get($payload, 'result_report_asset_state.bigfive_v2.remaining_missing_en_asset_keys_count'));
        $this->assertSame(7, data_get($payload, 'result_report_asset_state.bigfive_v2.remaining_unreviewed_en_asset_keys_count'));

        $this->assertSame(3, data_get($payload, 'media_asset_state.media_library_assets_count'));
        $this->assertSame(0, data_get($payload, 'media_asset_state.article_en_missing_cover_alt_count'));
        $this->assertSame(19, data_get($payload, 'media_asset_state.article_shared_cover_pair_count'));
        $this->assertSame(72, data_get($payload, 'media_asset_state.career_guides_missing_og_image_count'));
        $this->assertSame(3, data_get($payload, 'media_asset_state.sidecar_media_review_count'));
    }

    #[Test]
    public function fail_closed_and_claim_boundaries_remain_enforced(): void
    {
        $payload = $this->payload();

        $this->assertFalse((bool) ($payload['zh_fallback_relaxed'] ?? true));
        $this->assertFalse((bool) ($payload['draft_assets_runtime_activated'] ?? true));
        $this->assertTrue((bool) data_get($payload, 'fail_closed_controls.no_zh_fallback_relaxation'));
        $this->assertTrue((bool) data_get($payload, 'fail_closed_controls.frontend_clone_content_not_authority'));
        $this->assertTrue((bool) data_get($payload, 'fail_closed_controls.draft_assets_not_runtime_active'));
        $this->assertTrue((bool) data_get($payload, 'claim_boundary.forbidden_terms_absent_from_batch_artifact'));
        $this->assertContains('clinical diagnosis', data_get($payload, 'claim_boundary.forbidden_claims'));
        $this->assertContains('non-diagnostic', data_get($payload, 'claim_boundary.allowed_framing'));
        $this->assertSame('GLOBAL-EN-ZH-PARITY-FINAL-VERIFY-01', $payload['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-en-zh-result-media-asset-batch-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
