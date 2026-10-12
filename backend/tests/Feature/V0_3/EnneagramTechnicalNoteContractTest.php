<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnneagramTechnicalNoteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_note_route_returns_required_sections_and_registry_hash(): void
    {
        (new ScaleRegistrySeeder)->run();

        $response = $this->getJson('/api/v0.3/scales/ENNEAGRAM/technical-note');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'ENNEAGRAM');
        $response->assertJsonPath('technical_note_v1.schema_version', 'enneagram.technical_note.v1');
        $response->assertJsonPath('technical_note_v1.technical_note_version', 'enneagram_technical_note.v0.1');
        $response->assertJsonPath('technical_note_v1.sections.0.data_status', 'currently_operational');
        $response->assertJsonPath('technical_note_v1.metric_definitions.0.data_status', 'currently_operational');
        $response->assertJsonPath('technical_note_v1.metric_definitions.0.data_status_source', 'operational');
        $this->assertStringStartsWith('sha256:', (string) $response->json('technical_note_v1.registry_release_hash'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $response->json('technical_note_v1.canonical_source_hash'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $response->json('technical_note_v1.canonical_compiled_hash'));
        $this->assertContains(
            'close_call_rate',
            (array) $response->json('technical_note_v1.data_status_summary.metrics.currently_operational')
        );

        $sectionKeys = collect((array) $response->json('technical_note_v1.sections'))
            ->map(static fn (array $entry): string => (string) ($entry['section_key'] ?? ''))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'close_call',
            'confidence_band',
            'diffuse',
            'dominance_gap',
            'e105_fc144_agreement',
            'e105_fc144_forms',
            'low_quality',
            'method_boundaries',
            'privacy',
            'resonance_feedback',
            'retake_stability',
            'score_space_boundary',
            'test_goal',
        ], $sectionKeys);
    }

    public function test_english_projection_is_complete_and_unsupported_locale_fails_closed(): void
    {
        (new ScaleRegistrySeeder)->run();
        $zh = $this->getJson('/api/v0.3/scales/ENNEAGRAM/technical-note?locale=zh-CN')->assertOk()->json('technical_note_v1');
        $en = $this->getJson('/api/v0.3/scales/ENNEAGRAM/technical-note?locale=en')->assertOk()->json('technical_note_v1');
        self::assertSame('en', $en['canonical_locale']);
        $sections = array_column($en['sections'], null, 'section_key');
        self::assertSame('Interpretation stability', $sections['confidence_band']['title']);
        self::assertStringContainsString('samples, time intervals, and response quality', $sections['retake_stability']['body']);
        self::assertStringContainsString('personality development', $en['method_boundaries']['same_model_not_same_score_space']['copy']);
        self::assertStringContainsString('top three candidates', $en['method_boundaries']['non_diagnostic_boundary']['copy']);
        self::assertSame(array_column($zh['sections'], 'section_key'), array_column($en['sections'], 'section_key'));
        self::assertSame(array_column($zh['sections'], 'data_status'), array_column($en['sections'], 'data_status'));
        self::assertSame(array_keys($zh['method_boundaries']), array_keys($en['method_boundaries']));
        self::assertSame(array_column($zh['disclaimers'], 'key'), array_column($en['disclaimers'], 'key'));
        $visible = [$en['sections'], $en['method_boundaries'], $en['disclaimers'], $en['metric_definitions'] ?? []];
        self::assertSame(0, preg_match('/\p{Han}/u', json_encode($visible, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        self::assertSame($zh['data_status_summary'], $en['data_status_summary']);
        $this->getJson('/api/v0.3/scales/ENNEAGRAM/technical-note?locale=fr')->assertStatus(422);
    }
}
