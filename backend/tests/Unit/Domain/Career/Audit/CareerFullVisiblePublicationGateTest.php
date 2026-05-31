<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerFullVisiblePublicationGate;
use Tests\TestCase;

final class CareerFullVisiblePublicationGateTest extends TestCase
{
    public function test_2786_gate_requires_explicit_public_detail_indexable_evidence(): void
    {
        $gate = new CareerFullVisiblePublicationGate;
        $liveAcceptance = [
            'product_surface' => [
                'directory_member_count' => 2786,
                'career_jobs_item_count' => 2786,
                'detail_ready_count' => 2786,
                'canonical_public_slug_count' => 2786,
                'sitemap_noindex_url_count' => 0,
                'llms_noindex_url_count' => 0,
                'llms_full_noindex_url_count' => 0,
            ],
            'found_published' => 5572,
            'release_gate' => [
                'pass_count' => 5572,
            ],
        ];

        $summary = $gate->summary($liveAcceptance, 2786, 2);
        $blockers = $gate->blockers($liveAcceptance, 2786, 2);

        $this->assertNull($summary['public_detail_indexable_count']);
        $this->assertFalse($summary['product_claim']['visible_detail_claim_allowed']);
        $this->assertContains('product_public_detail_indexable_count_missing', array_column($blockers, 'reason'));
    }

    public function test_2786_gate_passes_when_product_publication_counts_are_explicit(): void
    {
        $gate = new CareerFullVisiblePublicationGate;
        $liveAcceptance = [
            'product_surface' => [
                'directory_member_count' => 2786,
                'career_jobs_item_count' => 2786,
                'detail_ready_count' => 2786,
                'public_detail_indexable_count' => 2786,
                'canonical_public_slug_count' => 2786,
                'sitemap_noindex_url_count' => 0,
                'llms_noindex_url_count' => 0,
                'llms_full_noindex_url_count' => 0,
            ],
            'found_published' => 5572,
            'release_gate' => [
                'pass_count' => 5572,
            ],
        ];

        $summary = $gate->summary($liveAcceptance, 2786, 2);

        $this->assertSame([], $gate->blockers($liveAcceptance, 2786, 2));
        $this->assertSame(2786, $summary['public_detail_indexable_count']);
        $this->assertTrue($summary['product_claim']['visible_detail_claim_allowed']);
    }

    public function test_1048_gate_requires_product_visible_detail_counts(): void
    {
        $gate = new CareerFullVisiblePublicationGate;
        $liveAcceptance = [
            'product_surface' => [
                'directory_member_count' => 1048,
                'career_jobs_item_count' => 1048,
                'detail_ready_count' => 1048,
                'public_detail_indexable_count' => 1048,
                'canonical_public_slug_count' => 1048,
                'sitemap_noindex_url_count' => 0,
                'llms_noindex_url_count' => 0,
                'llms_full_noindex_url_count' => 0,
            ],
            'found_published' => 2096,
            'release_gate' => [
                'pass_count' => 2096,
            ],
        ];

        $summary = $gate->summary($liveAcceptance, 1048, 2);

        $this->assertSame([], $gate->blockers($liveAcceptance, 1048, 2));
        $this->assertTrue($summary['required']);
        $this->assertSame(1048, $summary['directory_member_count']);
        $this->assertTrue($summary['product_claim']['visible_detail_claim_allowed']);
        $this->assertSame('product_visible_detail_publication', $summary['product_claim']['safe_claim_scope']);
    }

    public function test_1048_gate_blocks_sitemap_and_llms_forbidden_url_exposure(): void
    {
        $gate = new CareerFullVisiblePublicationGate;
        $liveAcceptance = [
            'product_surface' => [
                'directory_member_count' => 1048,
                'career_jobs_item_count' => 1048,
                'detail_ready_count' => 1048,
                'public_detail_indexable_count' => 1048,
                'canonical_public_slug_count' => 1048,
                'sitemap_noindex_url_count' => 1,
                'llms_404_url_count' => 2,
                'llms_full_redirect_source_url_count' => 3,
            ],
            'found_published' => 2096,
            'release_gate' => [
                'pass_count' => 2096,
            ],
        ];

        $summary = $gate->summary($liveAcceptance, 1048, 2);
        $reasons = array_column($gate->blockers($liveAcceptance, 1048, 2), 'reason');

        $this->assertSame(1, $summary['forbidden_exposure_counts']['sitemap_noindex_urls']);
        $this->assertContains('product_forbidden_sitemap_noindex_urls_present', $reasons);
        $this->assertContains('product_forbidden_llms_404_urls_present', $reasons);
        $this->assertContains('product_forbidden_llms_full_redirect_source_urls_present', $reasons);
    }

    public function test_1048_gate_counts_forbidden_url_lists_and_requires_evidence(): void
    {
        $gate = new CareerFullVisiblePublicationGate;
        $liveAcceptance = [
            'product_surface' => [
                'directory_member_count' => 1048,
                'career_jobs_item_count' => 1048,
                'detail_ready_count' => 1048,
                'public_detail_indexable_count' => 1048,
                'canonical_public_slug_count' => 1048,
                'sitemap_noindex_urls' => [
                    'https://example.test/noindex',
                    'https://example.test/noindex-2',
                ],
            ],
            'found_published' => 2096,
            'release_gate' => [
                'pass_count' => 2096,
            ],
        ];

        $summary = $gate->summary($liveAcceptance, 1048, 2);
        $reasons = array_column($gate->blockers($liveAcceptance, 1048, 2), 'reason');

        $this->assertSame(2, $summary['forbidden_exposure_counts']['sitemap_noindex_urls']);
        $this->assertContains('product_forbidden_sitemap_noindex_urls_present', $reasons);
        $this->assertContains('product_forbidden_llms_evidence_missing', $reasons);
        $this->assertContains('product_forbidden_llms_full_evidence_missing', $reasons);
        $this->assertFalse($summary['forbidden_exposure_evidence_present']['llms']);
        $this->assertFalse($summary['product_claim']['visible_detail_claim_allowed']);
    }
}
