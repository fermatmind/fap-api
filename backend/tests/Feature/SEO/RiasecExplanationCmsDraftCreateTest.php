<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationCmsDraftCreateTest extends TestCase
{
    public function test_draft_create_artifact_records_draft_only_cms_creation(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-create.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-CREATE-01', $artifact['task_id']);
        $this->assertTrue($artifact['hard_boundaries']['cms_draft_created']);
        $this->assertTrue($artifact['hard_boundaries']['production_write_performed']);
        $this->assertTrue($artifact['hard_boundaries']['database_mutation_performed']);
        $this->assertTrue($artifact['hard_boundaries']['cms_mutation_performed']);
        $this->assertFalse($artifact['hard_boundaries']['publish_performed']);
        $this->assertFalse($artifact['hard_boundaries']['publish_allowed']);
        $this->assertFalse($artifact['hard_boundaries']['search_submission_performed']);
        $this->assertFalse($artifact['hard_boundaries']['search_submit_allowed']);
        $this->assertFalse($artifact['hard_boundaries']['content_rewrite_performed']);
        $this->assertFalse($artifact['hard_boundaries']['private_url_accessed']);
        $this->assertFalse($artifact['hard_boundaries']['result_order_share_pay_payment_history_private_tokenized_url_accessed']);
        $this->assertFalse($artifact['hard_boundaries']['frontend_deploy_performed']);
    }

    public function test_importer_dry_run_and_create_results_are_locked(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-create.v1.json');

        foreach (['zh', 'en'] as $locale) {
            $this->assertTrue($artifact['importer_dry_run'][$locale]['ok']);
            $this->assertSame('will_create', $artifact['importer_dry_run'][$locale]['action']);
            $this->assertTrue($artifact['importer_dry_run'][$locale]['would_write']);
            $this->assertSame(0, $artifact['importer_dry_run'][$locale]['errors_count']);

            $this->assertTrue($artifact['draft_create'][$locale]['ok']);
            $this->assertSame('will_create', $artifact['draft_create'][$locale]['action']);
            $this->assertTrue($artifact['draft_create'][$locale]['would_write']);
            $this->assertSame(0, $artifact['draft_create'][$locale]['errors_count']);
            $this->assertSame('machine_draft', $artifact['draft_create'][$locale]['working_revision_status']);
            $this->assertNull($artifact['draft_create'][$locale]['published_revision_id']);
            $this->assertSame(0, $artifact['draft_create'][$locale]['references_count']);
        }

        $this->assertSame(40, $artifact['draft_create']['zh']['article_id']);
        $this->assertSame(45, $artifact['draft_create']['zh']['working_revision_id']);
        $this->assertSame(41, $artifact['draft_create']['en']['article_id']);
        $this->assertSame(46, $artifact['draft_create']['en']['working_revision_id']);
    }

    public function test_postcheck_confirms_non_public_noindex_unpublished_state(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-create.v1.json');
        $articles = collect($artifact['production_postcheck']['articles'])->keyBy('locale');
        $seo = collect($artifact['production_postcheck']['seo_meta'])->keyBy('locale');

        $this->assertSame(2, $artifact['production_postcheck']['translation_group_count']);
        $this->assertSame(1, $artifact['production_postcheck']['duplicate_counts']['riasec-holland-career-interest-test-explained']);
        $this->assertSame(1, $artifact['production_postcheck']['duplicate_counts']['what-is-riasec-holland-code-career-interest-test']);
        $this->assertSame(0, $artifact['production_postcheck']['public_indexable_published_count']);

        foreach (['zh', 'en'] as $locale) {
            $this->assertSame('draft', $articles[$locale]['status']);
            $this->assertFalse($articles[$locale]['is_public']);
            $this->assertFalse($articles[$locale]['is_indexable']);
            $this->assertNull($articles[$locale]['published_revision_id']);
            $this->assertNull($articles[$locale]['published_at']);
            $this->assertNull($articles[$locale]['deleted_at']);
            $this->assertSame('riasec-explanation-article-2026-06-v2', $articles[$locale]['translation_group_id']);

            $this->assertSame('noindex,nofollow', $seo[$locale]['robots']);
            $this->assertFalse($seo[$locale]['is_indexable']);
        }
    }

    public function test_public_pages_apis_sitemap_and_llms_do_not_expose_drafts(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-create.v1.json');

        foreach ($artifact['public_exposure_postcheck']['article_pages'] as $page) {
            $this->assertSame(404, $page['status']);
            $this->assertFalse($page['article_schema_present']);
            $this->assertFalse($page['faq_schema_present']);
            $this->assertFalse($page['target_canonical_present']);
        }

        foreach ($artifact['public_exposure_postcheck']['article_apis'] as $api) {
            $this->assertSame(404, $api['status']);
            $this->assertFalse($api['target_slug_present']);
        }

        foreach ($artifact['public_exposure_postcheck']['enumeration'] as $surface) {
            $this->assertSame(200, $surface['status']);
            $this->assertFalse($surface['contains_zh_slug']);
            $this->assertFalse($surface['contains_en_slug']);
        }
    }

    public function test_artifact_contains_no_forbidden_private_routes_or_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-create.v1.json') ?: '';

        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
