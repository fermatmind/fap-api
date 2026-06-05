<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationDraftPreflightTest extends TestCase
{
    public function test_draft_preflight_blocks_draft_create_publish_and_search_submission(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-preflight.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-PREFLIGHT-01', $preflight['task_id']);
        $this->assertFalse($preflight['cms_draft_created']);
        $this->assertFalse($preflight['cms_draft_create_allowed']);
        $this->assertFalse($preflight['publish_allowed']);
        $this->assertFalse($preflight['search_submit_allowed']);
        $this->assertFalse($preflight['deploy_allowed']);
        $this->assertFalse($preflight['content_rewrite_performed']);
        $this->assertFalse($preflight['private_url_accessed']);
        $this->assertFalse($preflight['production_write_performed']);
        $this->assertStringStartsWith('NO-GO for CMS draft creation', $preflight['preflight_decision']);
    }

    public function test_preflight_preserves_unknown_collision_state_and_importer_blocker(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-preflight.v1.json');

        $this->assertSame('fail', $preflight['checks']['importer_dry_run']);
        $this->assertSame('Unknown', $preflight['checks']['hidden_cms_collision_check']);
        $this->assertSame('Unknown', $preflight['checks']['operator_permission_check']);
        $this->assertFalse($preflight['hard_gates']['draft_authorization_alone_is_sufficient_now']);
        $this->assertContains(
            'Resolve importer dry-run Array to string conversion for zh/en target packages.',
            $preflight['hard_gates']['additional_blockers_before_draft_create']
        );

        foreach ($preflight['dry_run_commands'] as $command) {
            $this->assertFalse($command['ok']);
            $this->assertSame('will_skip', $command['action']);
            $this->assertSame('unexpected_error', $command['error_code']);
            $this->assertSame('Array to string conversion', $command['error_message']);
        }

        foreach ($preflight['targets'] as $target) {
            $this->assertSame('Unknown', $target['draft_create_preflight']['hidden_cms_slug_collision']);
            $this->assertSame('Unknown', $target['draft_create_preflight']['hidden_translation_group_collision']);
            $this->assertSame('Unknown', $target['draft_create_preflight']['operator_write_permission']);
            $this->assertSame('fail', $target['draft_create_preflight']['importer_dry_run_status']);
        }
    }

    public function test_public_absence_and_draft_defaults_are_locked(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-preflight.v1.json');
        $targets = collect($preflight['targets'])->keyBy('locale');

        $this->assertSame('/zh/articles/riasec-holland-career-interest-test-explained', $targets['zh']['canonical_path']);
        $this->assertSame('/en/articles/what-is-riasec-holland-code-career-interest-test', $targets['en']['canonical_path']);
        $this->assertSame('riasec-explanation-article-2026-06-v2', $targets['zh']['translation_group_id']);
        $this->assertSame($targets['zh']['translation_group_id'], $targets['en']['translation_group_id']);

        foreach (['zh', 'en'] as $locale) {
            $target = $targets[$locale];

            $this->assertSame('draft', $target['draft_defaults']['status']);
            $this->assertFalse($target['draft_defaults']['is_public']);
            $this->assertFalse($target['draft_defaults']['is_indexable']);
            $this->assertSame('noindex,nofollow', $target['draft_defaults']['robots']);
            $this->assertNull($target['draft_defaults']['published_revision_id']);
            $this->assertFalse($target['draft_defaults']['publish_allowed']);
            $this->assertFalse($target['draft_defaults']['search_submit_allowed']);
            $this->assertFalse($target['draft_defaults']['schema_enabled']);

            $this->assertSame(404, $target['public_exposure_absence']['article_api_status']);
            $this->assertSame(404, $target['public_exposure_absence']['article_seo_api_status']);
            $this->assertSame(404, $target['public_exposure_absence']['web_route_status']);
            $this->assertSame('absent', $target['public_exposure_absence']['sitemap_xml']);
            $this->assertSame('absent', $target['public_exposure_absence']['llms_txt']);
            $this->assertSame('absent', $target['public_exposure_absence']['llms_full_txt']);
        }
    }

    public function test_preflight_contains_no_forbidden_routes_or_private_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-preflight.v1.json') ?: '';

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
