<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scale;

use Tests\Support\Scale\MentalHealthLaunchAuthority;
use Tests\TestCase;

final class MentalHealthLaunchAuthorityTest extends TestCase
{
    public function test_it_defines_the_gate_driven_launch_states(): void
    {
        $authority = app(MentalHealthLaunchAuthority::class);

        $this->assertSame([
            MentalHealthLaunchAuthority::STATE_DRAFT,
            MentalHealthLaunchAuthority::STATE_STAGED_NOINDEX,
            MentalHealthLaunchAuthority::STATE_SAFETY_REVIEWED,
            MentalHealthLaunchAuthority::STATE_INDEXABLE_CANARY,
            MentalHealthLaunchAuthority::STATE_INDEXABLE_PUBLIC,
            MentalHealthLaunchAuthority::STATE_RETIRED,
        ], $authority->states());
    }

    public function test_state_machine_blocks_direct_public_launch_from_draft(): void
    {
        $authority = app(MentalHealthLaunchAuthority::class);

        $this->assertTrue($authority->canTransition(
            MentalHealthLaunchAuthority::STATE_DRAFT,
            MentalHealthLaunchAuthority::STATE_STAGED_NOINDEX,
        ));
        $this->assertTrue($authority->canTransition(
            MentalHealthLaunchAuthority::STATE_SAFETY_REVIEWED,
            MentalHealthLaunchAuthority::STATE_INDEXABLE_CANARY,
        ));
        $this->assertTrue($authority->canTransition(
            MentalHealthLaunchAuthority::STATE_INDEXABLE_CANARY,
            MentalHealthLaunchAuthority::STATE_INDEXABLE_PUBLIC,
        ));
        $this->assertFalse($authority->canTransition(
            MentalHealthLaunchAuthority::STATE_DRAFT,
            MentalHealthLaunchAuthority::STATE_INDEXABLE_PUBLIC,
        ));
    }

    public function test_sds_defaults_to_indexable_canary_without_clinical_combo_exposure(): void
    {
        $authority = app(MentalHealthLaunchAuthority::class);

        $sds = $authority->launchProfile(MentalHealthLaunchAuthority::SCALE_SDS_20);
        $clinical = $authority->launchProfile(MentalHealthLaunchAuthority::SCALE_CLINICAL_COMBO_68);

        $this->assertSame(MentalHealthLaunchAuthority::STATE_INDEXABLE_CANARY, $sds['launch_state']);
        $this->assertSame('index,follow', $sds['robots']);
        $this->assertTrue($sds['allows_public_indexing']);

        $this->assertSame(MentalHealthLaunchAuthority::STATE_STAGED_NOINDEX, $clinical['launch_state']);
        $this->assertSame('noindex,follow', $clinical['robots']);
        $this->assertFalse($clinical['allows_public_indexing']);
    }

    public function test_noindex_contract_does_not_depend_on_nocache_or_noarchive(): void
    {
        $authority = app(MentalHealthLaunchAuthority::class);

        $robots = $authority->robotsPolicy(
            MentalHealthLaunchAuthority::SCALE_CLINICAL_COMBO_68,
            MentalHealthLaunchAuthority::STATE_STAGED_NOINDEX,
        );

        $this->assertSame('noindex,follow', $robots);
        $this->assertStringNotContainsString('nocache', $robots);
        $this->assertStringNotContainsString('noarchive', $robots);
    }

    public function test_p0_gate_checklist_captures_sensitive_health_launch_constraints(): void
    {
        $authority = app(MentalHealthLaunchAuthority::class);
        $checklist = $authority->gateChecklist(MentalHealthLaunchAuthority::SCALE_SDS_20);

        $this->assertTrue($checklist['claim_boundary_required']);
        $this->assertTrue($checklist['public_naming_review_required']);
        $this->assertTrue($checklist['diagnostic_disclaimer_required']);
        $this->assertTrue($checklist['scale_source_audit_required']);
        $this->assertSame(
            ['source', 'authorization', 'translation', 'score_range', 'age_suitability'],
            $checklist['source_audit_scope'],
        );
        $this->assertSame(['SDS item 19'], $checklist['crisis_sentinel_scope']);
        $this->assertTrue($checklist['locale_aware_crisis_resources_required']);
        $this->assertTrue($checklist['hardcoded_global_988_disallowed']);
        $this->assertTrue($checklist['sensitive_health_data_consent_required']);
        $this->assertTrue($checklist['minor_policy_required']);
        $this->assertTrue($checklist['data_retention_policy_required']);
        $this->assertTrue($checklist['ad_targeting_disallowed']);
        $this->assertTrue($checklist['paid_report_noindex_required']);
        $this->assertTrue($checklist['free_safety_vs_paid_personalized_boundary_required']);
        $this->assertSame([
            'total' => 9,
            'tool' => 3,
            'safety' => 3,
            'growth' => 3,
        ], $checklist['related_articles_required']);
        $this->assertSame('noindex,follow', $checklist['robots_contract']['staged_policy']);
        $this->assertSame(['nocache', 'noarchive'], $checklist['robots_contract']['disallowed_directives']);
        $this->assertSame('launch_state', $checklist['robots_contract']['authority']);
    }
}
