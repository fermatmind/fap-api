<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RiasecTechnicalNoteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_note_route_returns_method_boundary_contract(): void
    {
        (new ScaleRegistrySeeder)->run();

        $response = $this->getJson('/api/v0.3/scales/RIASEC/technical-note');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'RIASEC');
        $response->assertJsonPath('technical_note_v1.schema_version', 'riasec.technical_note.v1');
        $response->assertJsonPath('technical_note_v1.technical_note_version', 'riasec_technical_note.v0.1');
        $response->assertJsonPath('technical_note_v1.measurement_contract_version', 'riasec.measurement_contract.v1');
        $response->assertJsonPath('technical_note_v1.method_boundary_version', 'riasec.method_boundary.v0.1');
        $response->assertJsonPath('technical_note_v1.form_contracts.riasec_60.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $response->assertJsonPath('technical_note_v1.form_contracts.riasec_60.quality_rule_status', 'minimal_answer_completion_only');
        $response->assertJsonPath('technical_note_v1.form_contracts.riasec_140.score_space_version', 'riasec_140_likert5_activity_context_space.v1');
        $response->assertJsonPath('technical_note_v1.form_contracts.riasec_140.cross_form_comparable', false);
        $response->assertJsonPath('technical_note_v1.form_contracts.riasec_140.raw_score_delta_allowed', false);
        $response->assertJsonPath('technical_note_v1.method_boundaries.same_scale_not_same_score_space.evidence_level', 'measurement_contract');
        $response->assertJsonPath('technical_note_v1.method_boundaries.content_examples_not_registry_match.content_maturity', 'v0.1');
        $response->assertJsonPath('technical_note_v1.lifecycle_copy_v1.schema_version', 'riasec.lifecycle_copy.v1');
        $response->assertJsonPath('technical_note_v1.lifecycle_copy_v1.frontend_fallback_allowed', false);
        $response->assertJsonPath('technical_note_v1.lifecycle_copy_v1.raw_feedback_public_exposure_allowed', false);
        $response->assertJsonPath('technical_note_v1.lifecycle_copy_v1.surfaces.0.surface', 'share_safe_card');
        $response->assertJsonPath('technical_note_v1.lifecycle_copy_v1.faq_items.0.q', 'IAS 是什么意思？');

        $sectionKeys = collect((array) $response->json('technical_note_v1.sections'))
            ->map(static fn (array $entry): string => (string) ($entry['section_key'] ?? ''))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'career_examples_boundary',
            'feedback_boundary',
            'measurement_boundary',
            'quality_boundary',
            'riasec_140_context',
            'score_space_boundary',
            'snapshot_boundary',
            'test_goal',
        ], $sectionKeys);

        $this->assertContains(
            'cross_form_raw_score_delta',
            (array) $response->json('technical_note_v1.data_status_summary.not_claimed')
        );
    }
}
