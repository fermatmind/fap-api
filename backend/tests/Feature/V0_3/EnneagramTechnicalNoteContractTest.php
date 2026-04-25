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
}
