<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Authority\CareerCrosswalkModePolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerCrosswalkModePolicyTest extends TestCase
{
    #[Test]
    public function it_classifies_direct_us_soc_and_onet_rows_as_exact_and_import_safe(): void
    {
        $policy = app(CareerCrosswalkModePolicy::class)->classifyWorkbookRow([
            'SOC_Code' => '15-2011',
            'O_NET_Code' => '15-2011.00',
        ]);

        $this->assertSame(CareerCrosswalkModePolicy::EXACT, $policy['mode']);
        $this->assertSame('auto_safe', $policy['release_bucket']);
        $this->assertTrue($policy['authority_write_allowed']);
        $this->assertTrue($policy['display_import_allowed']);
        $this->assertSame([], $policy['blockers']);
    }

    #[Test]
    public function it_normalizes_legacy_direct_match_without_rewriting_source_rows(): void
    {
        $policy = app(CareerCrosswalkModePolicy::class)->classifyWorkbookRow([
            'SOC_Code' => '15-2011',
            'O_NET_Code' => '15-2011.00',
            'crosswalk_mode' => 'direct_match',
        ]);

        $this->assertSame(CareerCrosswalkModePolicy::EXACT, $policy['mode']);
        $this->assertContains('legacy_mode_normalized_from_direct_match', $policy['notes']);
    }

    #[Test]
    public function it_blocks_cn_proxy_rows_from_us_track_display_import(): void
    {
        $policy = app(CareerCrosswalkModePolicy::class)->classifyWorkbookRow([
            'SOC_Code' => 'CN-INDUSTRY-PROXY',
            'O_NET_Code' => 'not_applicable_cn_occupation',
        ]);

        $this->assertSame(CareerCrosswalkModePolicy::LOCAL_HEAVY_INTERPRETATION, $policy['mode']);
        $this->assertSame('blocked', $policy['release_bucket']);
        $this->assertFalse($policy['authority_write_allowed']);
        $this->assertFalse($policy['display_import_allowed']);
        $this->assertContains('cn_proxy_not_us_track', $policy['blockers']);
    }

    #[Test]
    public function it_blocks_broad_group_and_multiple_onet_rows_until_manual_resolution(): void
    {
        $policy = app(CareerCrosswalkModePolicy::class)->classifyWorkbookRow([
            'SOC_Code' => 'BLS_BROAD_GROUP',
            'O_NET_Code' => 'multiple_onet_occupations',
        ]);

        $this->assertSame(CareerCrosswalkModePolicy::FAMILY_PROXY, $policy['mode']);
        $this->assertContains('broad_group_requires_manual_resolution', $policy['blockers']);
        $this->assertContains('multiple_onet_requires_manual_resolution', $policy['blockers']);
    }

    #[Test]
    public function it_classifies_functional_proxy_as_manual_review_not_direct_authority(): void
    {
        $policy = app(CareerCrosswalkModePolicy::class)->classifyWorkbookRow([
            'SOC_Code' => '15-2011',
            'O_NET_Code' => 'functional_proxy',
        ]);

        $this->assertSame(CareerCrosswalkModePolicy::FUNCTIONAL_EQUIVALENT, $policy['mode']);
        $this->assertSame('manual_review', $policy['release_bucket']);
        $this->assertContains('proxy_mapping_requires_manual_review', $policy['blockers']);
        $this->assertFalse($policy['display_import_allowed']);
    }
}
