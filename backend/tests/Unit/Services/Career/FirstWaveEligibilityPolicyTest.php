<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\FirstWaveEligibilityPolicy;
use App\Domain\Career\Import\ImportScopeMode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FirstWaveEligibilityPolicyTest extends TestCase
{
    #[Test]
    public function it_accepts_exact_and_trust_inheritance_rows_with_required_truth_fields(): void
    {
        $policy = app(FirstWaveEligibilityPolicy::class);
        $base = [
            'canonical_title_en' => 'Accountants and auditors',
            'canonical_slug' => 'accountants-and-auditors',
            'bls_url' => 'https://example.test/bls',
            'ai_exposure' => 8.0,
            'median_pay_usd_annual' => 81680,
            'jobs_2024' => 1579800,
            'projected_jobs_2034' => 1652600,
            'employment_change' => 72800,
            'outlook_pct_2024_2034' => 5.0,
            'crosswalk_source_code' => '13-2011',
        ];

        $exact = $policy->evaluate($base + ['mapping_mode' => ImportScopeMode::EXACT], [
            ImportScopeMode::EXACT,
            ImportScopeMode::TRUST_INHERITANCE,
        ]);
        $inherited = $policy->evaluate($base + ['mapping_mode' => ImportScopeMode::TRUST_INHERITANCE], [
            ImportScopeMode::EXACT,
            ImportScopeMode::TRUST_INHERITANCE,
        ]);

        $this->assertTrue($exact['accepted']);
        $this->assertTrue($inherited['accepted']);
    }

    #[Test]
    public function it_rejects_rows_with_unsupported_mapping_modes_or_missing_truth_fields(): void
    {
        $policy = app(FirstWaveEligibilityPolicy::class);

        $result = $policy->evaluate([
            'canonical_title_en' => 'Actors',
            'canonical_slug' => 'actors',
            'mapping_mode' => 'functional_equivalent',
            'bls_url' => '',
            'ai_exposure' => null,
            'median_pay_usd_annual' => null,
            'jobs_2024' => 57000,
            'projected_jobs_2034' => 57100,
            'employment_change' => 200,
            'outlook_pct_2024_2034' => 0.0,
            'crosswalk_source_code' => '',
        ], [ImportScopeMode::EXACT, ImportScopeMode::TRUST_INHERITANCE]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('unsupported_mapping_mode', $result['reasons']);
        $this->assertContains('missing_bls_url', $result['reasons']);
        $this->assertContains('missing_ai_exposure', $result['reasons']);
    }
}
