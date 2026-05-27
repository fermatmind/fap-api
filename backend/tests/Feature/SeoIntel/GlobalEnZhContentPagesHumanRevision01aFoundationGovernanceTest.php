<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesHumanRevision01aFoundationGovernanceTest extends TestCase
{
    #[Test]
    public function foundation_governance_addendum_exists_with_bounded_public_benefit_direction(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-human-revision-01a-foundation-governance.v1.json');
        $addendumPath = base_path('docs/seo/import-packages/global-en-zh-content-pages-human-revision-01a-foundation-governance.addendum.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-human-revision-01a-foundation-governance.md'));
        $this->assertFileExists($generatedPath);
        $this->assertFileExists($addendumPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $addendum = json_decode((string) file_get_contents($addendumPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01A-FOUNDATION-GOVERNANCE-FACT-RECONCILE', $generated['task'] ?? null);
        $this->assertNotEmpty($generated['foundation_fact_state'] ?? null);

        $foundationGuidance = json_encode($generated['revised_foundation_guidance'] ?? [], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('public-benefit', $foundationGuidance);
        $this->assertStringContainsString('shareholding', $foundationGuidance);

        $foundationItem = collect($addendum['items'] ?? [])->firstWhere('page_key', 'foundation');

        $this->assertIsArray($foundationItem);
        $this->assertSame('planned_public_benefit_shareholding', $foundationItem['fact_state'] ?? null);
        $this->assertStringContainsString('public-benefit shareholding', $foundationItem['foundation_governance_language'] ?? '');
        $this->assertFalse($foundationItem['sitemap_eligible'] ?? true);
        $this->assertFalse($foundationItem['llms_eligible'] ?? true);
        $this->assertFalse($foundationItem['footer_eligible'] ?? true);
        $this->assertFalse($foundationItem['search_channel_eligible'] ?? true);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);
    }
}
