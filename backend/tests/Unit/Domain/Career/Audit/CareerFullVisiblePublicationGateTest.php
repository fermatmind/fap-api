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
}
