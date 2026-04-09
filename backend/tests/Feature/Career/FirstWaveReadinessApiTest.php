<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstWaveReadinessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_machine_readable_first_wave_readiness_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/readiness');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_readiness')
            ->assertJsonPath('summary_version', 'career.release.first_wave_readiness.v1')
            ->assertJsonPath('counts.total', 10)
            ->assertJsonPath('counts.publish_ready', 6)
            ->assertJsonPath('counts.blocked_override_eligible', 2)
            ->assertJsonPath('counts.blocked_not_safely_remediable', 2)
            ->assertJsonPath('counts.blocked_total', 4)
            ->assertJsonPath('counts.partial_raw', 0)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'wave_name',
                'counts' => [
                    'total',
                    'publish_ready',
                    'blocked_override_eligible',
                    'blocked_not_safely_remediable',
                    'blocked_total',
                    'partial_raw',
                ],
                'occupations' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'status',
                    'blocker_type',
                    'remediation_class',
                    'authority_override_supplied',
                    'review_required',
                    'crosswalk_mode',
                    'reviewer_status',
                    'index_state',
                    'index_eligible',
                    'reason_codes',
                ]],
            ]);

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');

        $this->assertSame('blocked_override_eligible', $occupations['software-developers']['status']);
        $this->assertSame('blocked_override_eligible', $occupations['financial-analysts']['status']);
        $this->assertSame('blocked_not_safely_remediable', $occupations['marketing-managers']['status']);
        $this->assertSame(
            'blocked_not_safely_remediable',
            $occupations['elementary-school-teachers-except-special-education']['status']
        );
        $this->assertSame('publish_ready', $occupations['registered-nurses']['status']);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
