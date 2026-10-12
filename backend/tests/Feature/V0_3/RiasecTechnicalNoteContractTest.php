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

    public function test_english_projection_is_complete_and_unsupported_locale_fails_closed(): void
    {
        (new ScaleRegistrySeeder)->run();
        $zh = $this->getJson('/api/v0.3/scales/RIASEC/technical-note?locale=zh-CN')->assertOk()->json('technical_note_v1');
        $en = $this->getJson('/api/v0.3/scales/RIASEC/technical-note?locale=en')->assertOk()->json('technical_note_v1');
        self::assertSame('en', $en['canonical_locale']);
        self::assertSame(array_column($zh['sections'], 'section_key'), array_column($en['sections'], 'section_key'));
        $sections = array_column($en['sections'], null, 'section_key');
        self::assertStringContainsString('exploration paths only', $sections['feedback_boundary']['body']);
        self::assertStringNotContainsString('aspiration', $sections['feedback_boundary']['body']);
        self::assertStringContainsString('measured_holland_code', $en['method_boundaries']['feedback_no_score_mutation']['copy']);
        self::assertSame(array_column($zh['sections'], 'data_status'), array_column($en['sections'], 'data_status'));
        self::assertSame(array_keys($zh['method_boundaries']), array_keys($en['method_boundaries']));
        self::assertSame(array_column($zh['disclaimers'], 'key'), array_column($en['disclaimers'], 'key'));
        $visible = [$en['sections'], $en['method_boundaries'], $en['disclaimers'], $en['metric_definitions'] ?? []];
        self::assertSame(0, preg_match('/\p{Han}/u', json_encode($visible, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        self::assertSame($zh['data_status_summary'], $en['data_status_summary']);
        $this->getJson('/api/v0.3/scales/RIASEC/technical-note?locale=fr')->assertStatus(422);
    }
}
