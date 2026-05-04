<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Governance\CareerTrustFactReviewPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerTrustFactReviewPolicyTest extends TestCase
{
    #[Test]
    public function it_blocks_sitemap_and_llms_when_fact_review_ledger_is_missing(): void
    {
        $policy = new CareerTrustFactReviewPolicy;

        $result = $policy->evaluateRow([
            'salary' => ['url' => 'https://www.bls.gov/example', 'claim' => 'salary'],
            'onet' => ['url' => 'https://www.onetonline.org/link/details/15-2011.00'],
            'fermatmind_interpretation' => ['label' => 'FermatMind interpretation'],
        ], false);

        $this->assertSame('career.trust_fact_review_policy.v1', $result['policy_version']);
        $this->assertTrue($result['official_source_trace_present']);
        $this->assertTrue($result['fermat_interpretation_labeled']);
        $this->assertFalse($result['fact_review_ledger_present']);
        $this->assertFalse($result['sitemap_llms_release_ready']);
        $this->assertContains('missing_fact_review_ledger', $result['blockers']);
    }

    #[Test]
    public function it_reports_workbook_ledger_requirements_without_writes(): void
    {
        $summary = app(CareerTrustFactReviewPolicy::class)->workbookSummary(false);

        $this->assertSame('missing', $summary['fact_review_ledger_status']);
        $this->assertContains('Claim_Type', $summary['required_ledger_columns']);
        $this->assertContains('AI exposure', $summary['required_claim_types']);
        $this->assertTrue($summary['sitemap_llms_blocked_until_fact_review_passes']);
        $this->assertFalse($summary['writes_database']);
    }
}
