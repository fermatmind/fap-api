<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesHumanRevisionR2Test extends TestCase
{
    #[Test]
    public function revision_r2_package_removes_foundation_guarded_phrases_without_erasing_governance_direction(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-human-revision-r2.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-content-pages-human-revision-r2.foundation.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-human-revision-r2.md'));
        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-R2', $generated['task'] ?? null);
        $this->assertSame('planned_public_benefit_shareholding', $generated['foundation_fact_state'] ?? null);
        $this->assertTrue($generated['forbidden_foundation_phrases_removed'] ?? false);
        $this->assertSame(0, $generated['forbidden_foundation_phrase_hits_after_revision'] ?? null);
        $this->assertTrue($generated['public_benefit_governance_preserved'] ?? false);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);

        $this->assertSame('foundation', $package['page_key'] ?? null);
        $this->assertSame('planned_public_benefit_shareholding', $package['foundation_fact_state'] ?? null);
        $this->assertTrue($package['cms_update_required'] ?? false);
        $this->assertSame('draft_revision_only', $package['publish_state'] ?? null);
        $this->assertFalse($package['sitemap_eligible'] ?? true);
        $this->assertFalse($package['llms_eligible'] ?? true);
        $this->assertFalse($package['footer_eligible'] ?? true);
        $this->assertFalse($package['search_channel_eligible'] ?? true);

        $revisionText = strtolower(json_encode([
            $package['revised_title_en'] ?? '',
            $package['revised_description_en'] ?? '',
            $package['revised_h1_en'] ?? '',
            $package['revised_body_blocks_en'] ?? [],
        ], JSON_THROW_ON_ERROR));

        foreach ($this->guardedPhrases() as $phrase) {
            $this->assertStringNotContainsString($phrase, $revisionText);
        }

        $this->assertStringContainsString('public-benefit', $revisionText);
        $this->assertStringContainsString('planned public-benefit shareholding arrangement', $revisionText);
        $this->assertStringContainsString('youth', $revisionText);
        $this->assertStringContainsString('data boundaries', $revisionText);
    }

    /**
     * @return list<string>
     */
    private function guardedPhrases(): array
    {
        return [
            'registered foundation',
            'nonprofit legal status',
            'charity registration',
            'donation program',
            'grant program',
            'formal board governance',
            'legal fiduciary duty',
            'exact ownership percentage',
            'completed equity transfer',
            'completed foundation holding',
            'formal board',
            'board governance',
            'equity transfer',
            'ownership percentage',
            'completed holding',
            'registered charity',
            'nonprofit status',
            'fiduciary',
        ];
    }
}
