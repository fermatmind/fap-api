<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentAuthorityPublishReadiness01Test extends TestCase
{
    #[Test]
    public function readiness_report_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-en-zh-content-authority-publish-readiness-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-AUTHORITY-PUBLISH-READINESS-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_cms_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_publish'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($payload['no_url_submission'] ?? false));
        $this->assertTrue((bool) ($payload['no_frontend_fallback_authority'] ?? false));
        $this->assertTrue((bool) ($payload['no_footer_link_expansion'] ?? false));
        $this->assertTrue((bool) ($payload['draft_import_not_exposed'] ?? false));
    }

    #[Test]
    public function target_pages_and_eligibility_matrices_are_present(): void
    {
        $payload = $this->payload();
        $targetPages = ['brand', 'charter', 'foundation', 'careers', 'policies'];

        $this->assertEqualsCanonicalizing($targetPages, $payload['target_pages'] ?? []);
        $this->assertIsArray($payload['per_page_status'] ?? null);
        $this->assertIsArray($payload['footer_eligibility'] ?? null);
        $this->assertIsArray($payload['sitemap_eligibility'] ?? null);
        $this->assertIsArray($payload['llms_eligibility'] ?? null);

        foreach ($targetPages as $pageKey) {
            $this->assertArrayHasKey($pageKey, $payload['per_page_status']);
            $this->assertSame('ready_for_human_review', $payload['per_page_status'][$pageKey]['recommended_status'] ?? null);
            $this->assertTrue((bool) ($payload['per_page_status'][$pageKey]['zh_counterpart_exists'] ?? false));
            $this->assertFalse((bool) ($payload['per_page_status'][$pageKey]['en_counterpart_exists'] ?? true));
            $this->assertFalse((bool) ($payload['footer_eligibility'][$pageKey] ?? true));
            $this->assertFalse((bool) ($payload['sitemap_eligibility'][$pageKey] ?? true));
            $this->assertFalse((bool) ($payload['llms_eligibility'][$pageKey] ?? true));
        }
    }

    #[Test]
    public function import_package_is_non_runtime_and_non_published(): void
    {
        $path = dirname(__DIR__, 3).'/docs/seo/import-packages/global-en-zh-content-authority-publish-readiness-01.import.v1.json';

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-authority-publish-readiness-01.import.v1', $payload['schema_version'] ?? null);
        $this->assertTrue((bool) ($payload['non_runtime'] ?? false));
        $this->assertSame('draft_review_only', $payload['publish_state'] ?? null);
        $this->assertFalse((bool) ($payload['runtime_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['sitemap_llms_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['footer_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertCount(5, $payload['pages'] ?? []);
    }

    #[Test]
    public function final_decision_and_next_task_are_explicit(): void
    {
        $payload = $this->payload();

        $this->assertTrue((bool) ($payload['future_human_review_required'] ?? false));
        $this->assertTrue((bool) ($payload['future_cms_import_required'] ?? false));
        $this->assertTrue((bool) ($payload['future_publish_required'] ?? false));
        $this->assertSame([], $payload['claim_boundary_status']['forbidden_claim_hits'] ?? null);
        $this->assertSame(
            'content_authority_publish_readiness_completed_ready_for_human_review_import',
            $payload['final_decision'] ?? null,
        );
        $this->assertSame(
            'GLOBAL-EN-ZH-CONTENT-AUTHORITY-HUMAN-REVIEW-IMPORT-01',
            $payload['next_task'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-en-zh-content-authority-publish-readiness-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
