<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerPublicVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_governance_endpoint_exposes_summary_without_member_evidence(): void
    {
        $this->getJson('/api/v0.5/career/launch-governance-closure')
            ->assertOk()
            ->assertJsonPath('governance_kind', 'career_launch_governance_closure')
            ->assertJsonStructure([
                'governance_kind',
                'governance_version',
                'scope',
                'counts',
                'public_statement',
            ])
            ->assertJsonMissingPath('members')
            ->assertJsonMissingPath('members.0.evidence_refs')
            ->assertJsonMissingPath('members.0.blocking_reasons');
    }

    public function test_public_operational_summary_endpoint_exposes_counts_without_members(): void
    {
        $this->getJson('/api/v0.5/career/lifecycle/operational-summary')
            ->assertOk()
            ->assertJsonPath('summary_kind', 'career_lifecycle_operational_summary')
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'counts',
            ])
            ->assertJsonMissingPath('members')
            ->assertJsonMissingPath('members.0.timeline_entry_count')
            ->assertJsonMissingPath('members.0.closure_state');
    }
}
