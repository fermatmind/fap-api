<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhCareerAssetBatch01Test extends TestCase
{
    #[Test]
    public function generated_career_asset_batch_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-en-zh-career-asset-batch-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-CAREER-ASSET-BATCH-01', $payload['task'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['mass_english_generation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));
    }

    #[Test]
    public function career_guides_are_import_ready_but_career_jobs_remain_explicitly_deferred(): void
    {
        $payload = $this->payload();

        $this->assertSame(36, data_get($payload, 'career_guide_inventory.english_authority_count'));
        $this->assertSame(36, data_get($payload, 'career_guide_inventory.chinese_authority_count'));
        $this->assertSame(0, data_get($payload, 'career_guide_inventory.missing_english_counterparts_count'));
        $this->assertSame(0, data_get($payload, 'career_guide_inventory.missing_chinese_counterparts_count'));
        $this->assertSame('guide_code', data_get($payload, 'career_guide_inventory.counterpart_key'));

        $this->assertSame(36, data_get($payload, 'career_job_inventory.english_authority_count'));
        $this->assertSame(342, data_get($payload, 'career_job_inventory.chinese_authority_count'));
        $this->assertSame(0, data_get($payload, 'career_job_inventory.shared_job_code_counterparts_count'));
        $this->assertSame(342, data_get($payload, 'career_job_inventory.missing_english_counterparts_count'));
        $this->assertSame(36, data_get($payload, 'career_job_inventory.missing_chinese_counterparts_count'));
        $this->assertSame(
            'mismatched_authority_sets_require_translation_group_or_import_review',
            data_get($payload, 'career_job_inventory.publication_state'),
        );
    }

    #[Test]
    public function exposure_and_claim_gates_remain_closed_for_draft_fallback_or_overclaim_career_urls(): void
    {
        $payload = $this->payload();

        $this->assertFalse((bool) ($payload['draft_or_fallback_career_urls_exposed_in_sitemap_llms'] ?? true));
        $this->assertTrue((bool) data_get($payload, 'exposure_gate.career_detail_url_must_have_backend_authority'));
        $this->assertTrue((bool) data_get($payload, 'exposure_gate.career_detail_url_must_have_runtime_200_verification'));
        $this->assertTrue((bool) data_get($payload, 'exposure_gate.career_detail_url_must_not_be_frontend_fallback_only'));
        $this->assertTrue((bool) data_get($payload, 'exposure_gate.career_detail_url_must_not_return_404_or_soft_404'));
        $this->assertFalse((bool) data_get($payload, 'exposure_gate.draft_exposure_allowed_in_sitemap_llms'));

        $this->assertTrue((bool) data_get($payload, 'claim_boundary.forbidden_terms_absent_from_batch_artifact'));
        $this->assertContains('precise career recommendation', data_get($payload, 'claim_boundary.forbidden_claims'));
        $this->assertContains('decision support', data_get($payload, 'claim_boundary.allowed_framing'));
        $this->assertSame(
            'GLOBAL-EN-ZH-PARITY-RESULT-MEDIA-ASSET-BATCH-01',
            $payload['next_task'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-en-zh-career-asset-batch-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
