<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\FirstWaveBlockedGovernancePolicy;
use App\Domain\Career\Publish\FirstWaveBlockedRegistryReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirstWaveBlockedGovernancePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_current_first_wave_blocked_registry(): void
    {
        $registry = app(FirstWaveBlockedRegistryReader::class)->read();
        $itemsBySlug = collect($registry['items'])->keyBy('canonical_slug');

        $this->assertSame(4, $registry['count_actual']);
        $this->assertSame('missing_crosswalk_source_code', $itemsBySlug['software-developers']['blocker_type']);
        $this->assertTrue($itemsBySlug['software-developers']['override_eligible']);
        $this->assertSame('authority_override_possible', $itemsBySlug['software-developers']['remediation_class']);
        $this->assertSame('source_row_missing', $itemsBySlug['marketing-managers']['blocker_type']);
        $this->assertFalse($itemsBySlug['marketing-managers']['override_eligible']);
        $this->assertSame('not_safely_remediable', $itemsBySlug['marketing-managers']['remediation_class']);
    }

    public function test_it_classifies_blocked_slugs_into_override_eligible_and_non_remediable_groups(): void
    {
        $policy = app(FirstWaveBlockedGovernancePolicy::class);

        $software = $policy->classify('software-developers', 'blocked');
        $marketing = $policy->classify('marketing-managers', 'blocked');
        $ready = $policy->classify('data-scientists', 'publish_ready');

        $this->assertSame('blocked_override_eligible', $software['blocked_governance_status']);
        $this->assertTrue($software['override_eligible']);
        $this->assertFalse($software['authority_override_supplied']);
        $this->assertContains('authority_override_not_supplied', $software['notes']);

        $this->assertSame('blocked_not_safely_remediable', $marketing['blocked_governance_status']);
        $this->assertFalse($marketing['override_eligible']);

        $this->assertNull($ready['blocked_governance_status']);
        $this->assertNull($ready['blocker_type']);
    }
}
