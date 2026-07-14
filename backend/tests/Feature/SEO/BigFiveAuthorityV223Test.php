<?php

namespace Tests\Feature\SEO;

use App\Models\ContentPage;
use Tests\TestCase;

class BigFiveAuthorityV223Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-technical-trust-23');
    }

    public function test_package_reuses_existing_content_page_authority_for_two_bilingual_identities(): void
    {
        $package = $this->readJson('content-page-draft-package.json');

        $this->assertCount(4, $package['candidates']);
        $this->assertSame(['big-five-methodology', 'big-five-source-review-policy'], array_values(array_unique(array_column($package['candidates'], 'slug'))));
        $this->assertSame(['en', 'zh-CN'], array_values(array_unique(array_column($package['candidates'], 'locale'))));
        $this->assertContains('methodology', ContentPage::PAGE_TYPES);
        $this->assertContains('trust', ContentPage::PAGE_TYPES);
        foreach ($package['candidates'] as $candidate) {
            $this->assertSame('App\\Models\\ContentPage', $candidate['authority_model']);
        }
    }

    public function test_all_product_specific_numeric_evidence_is_unknown(): void
    {
        $package = $this->readJson('content-page-draft-package.json');
        $expectedKeys = ['product_reliability_coefficients', 'product_validity_coefficients', 'normative_population', 'norm_sample_size', 'percentile_calibration', 'standard_error_of_measurement', 'subgroup_equivalence', 'predictive_accuracy'];

        foreach ($package['candidates'] as $candidate) {
            $this->assertSame($expectedKeys, array_keys($candidate['product_evidence_unknowns']));
            $this->assertSame(['Unknown'], array_values(array_unique($candidate['product_evidence_unknowns'])));
        }
    }

    public function test_every_draft_is_non_public_non_indexable_and_review_gated(): void
    {
        $package = $this->readJson('content-page-draft-package.json');

        foreach ($package['candidates'] as $candidate) {
            $this->assertSame('draft', $candidate['status']);
            $this->assertSame('science_review', $candidate['review_state']);
            $this->assertSame('not_reviewed', $candidate['claim_gate_status']);
            $this->assertFalse($candidate['is_public']);
            $this->assertFalse($candidate['is_indexable']);
            $this->assertFalse($candidate['publish_allowed']);
            $this->assertFalse($candidate['schema_enabled']);
            $this->assertTrue($candidate['science_review_required']);
            $this->assertTrue($candidate['operator_approval_required']);
            $this->assertNull($candidate['owner']);
            $this->assertNull($candidate['reviewer']);
            $this->assertNull($candidate['published_at']);
            $this->assertFalse($candidate['cms_write_executed']);
        }
    }

    public function test_content_covers_privacy_change_history_and_visible_evidence(): void
    {
        $package = $this->readJson('content-page-draft-package.json');

        foreach ($package['candidates'] as $candidate) {
            $keys = array_column($candidate['headings_json'], 'key');
            $this->assertContains('privacy', $keys);
            $this->assertContains('change_history', $keys);
            $this->assertContains('evidence', $keys);
            $this->assertStringContainsString('Unknown', $candidate['content_md']);
            $this->assertStringContainsString('https://doi.org/', $candidate['content_md']);
        }
    }

    public function test_qa_proves_existing_authority_and_zero_writes(): void
    {
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('PASS_PENDING_SCIENCE_REVIEW', $qa['status']);
        $this->assertTrue($qa['checks']['uses_existing_content_page_model']);
        $this->assertFalse($qa['checks']['parallel_cms_stack_created']);
        $this->assertTrue($qa['checks']['all_numeric_product_evidence_unknown']);
        $this->assertTrue($qa['checks']['all_non_public_non_indexable_drafts']);
        $this->assertSame(0, $qa['checks']['attribution_synthesized']);
        $this->assertSame(0, $qa['checks']['cms_writes']);
        $this->assertSame(0, $qa['checks']['production_changes']);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
