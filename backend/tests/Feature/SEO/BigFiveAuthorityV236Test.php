<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV236Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = dirname(__DIR__, 4).'/generated/big-five-authority-v2/big5-authority-v2-seo-geo-authority-36';
    }

    public function test_exact_inventory_passes_deterministic_authority_validator(): void
    {
        $package = $this->readJson('candidate-eligibility.json');
        $this->assertCount(231, $package['candidates']);
        $this->assertCount(231, array_unique(array_column($package['candidates'], 'route')));

        $output = [];
        $exitCode = 1;
        exec('node '.escapeshellarg($this->packagePath.'/validate-package.mjs').' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('231 individually gated / 231 withheld', implode("\n", $output));
    }

    public function test_every_candidate_is_individually_fail_closed_by_review_and_media(): void
    {
        $candidates = $this->readJson('candidate-eligibility.json')['candidates'];

        foreach ($candidates as $candidate) {
            $this->assertFalse($candidate['gates']['author_reviewer_date'], $candidate['route']);
            $this->assertFalse($candidate['gates']['media_authority'], $candidate['route']);
            $this->assertContains('author_reviewer_date', $candidate['blocking_gates'], $candidate['route']);
            $this->assertContains('media_authority', $candidate['blocking_gates'], $candidate['route']);
            $this->assertFalse($candidate['release_eligible'], $candidate['route']);
            $this->assertSame('WITHHOLD_FAIL_CLOSED', $candidate['eligibility_status'], $candidate['route']);
        }
    }

    public function test_metadata_canonical_hreflang_robots_and_schema_boundaries_are_explicit(): void
    {
        $candidates = $this->readJson('candidate-eligibility.json')['candidates'];

        foreach ($candidates as $candidate) {
            $this->assertSame($candidate['route'], $candidate['metadata_candidate']['canonical_path']);
            $this->assertTrue($candidate['gates']['canonical_consistent']);
            $this->assertTrue($candidate['gates']['hreflang_real_and_consistent']);
            $this->assertContains($candidate['metadata_candidate']['hreflang']['status'], ['real_reciprocal_pair', 'not_applicable_no_real_counterpart']);
            $this->assertSame('noindex,nofollow', $candidate['projections']['robots']);
            $this->assertFalse($candidate['projections']['metadata_publish_eligible']);
            $this->assertFalse($candidate['projections']['schema_eligible']);
            $this->assertNull($candidate['projections']['schema_payload']);
            $this->assertFalse($candidate['projections']['sitemap_eligible']);
            $this->assertFalse($candidate['projections']['llms_eligible']);
            $this->assertFalse($candidate['projections']['llms_full_eligible']);
            $this->assertFalse($candidate['projections']['public_release_executed']);
        }
    }

    public function test_contract_and_qa_prove_zero_public_mutation(): void
    {
        $contract = $this->readJson('authority-contract.json');
        $summary = $this->readJson('eligibility-summary.json');
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('CMS/backend only', $contract['authority']);
        $this->assertSame('implementation_and_validation_only', $contract['release_mode']);
        $this->assertTrue($contract['rules']['per_candidate_decision_required']);
        $this->assertTrue($contract['rules']['batch_auto_index_forbidden']);
        $this->assertTrue($contract['rules']['schema_requires_matching_visible_content']);
        $this->assertTrue($contract['rules']['json_ld_is_not_graph_or_citation_proof']);
        foreach ($contract['mutations'] as $mutation => $count) {
            $this->assertSame(0, $count, $mutation);
        }
        $this->assertSame(0, $summary['release_eligible']);
        $this->assertSame(231, $summary['withheld']);
        $this->assertSame(231, $summary['robots_noindex_nofollow']);
        $this->assertSame('PASS_FAIL_CLOSED_ZERO_RELEASE', $qa['status']);
        foreach ($qa['checks'] as $check => $passed) {
            $this->assertTrue($passed, $check);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
