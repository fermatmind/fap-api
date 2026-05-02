<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerJobDisplaySurfaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_display_surface_for_eligible_actors_asset(): void
    {
        $occupation = $this->seedCompiledOccupation('actors');
        $this->addActorsCrosswalks($occupation);
        $this->createDisplayAsset($occupation);

        $response = $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'actors')
            ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
            ->assertJsonPath('display_surface_v1.template_version', 'v4.2')
            ->assertJsonPath('display_surface_v1.subject.canonical_slug', 'actors')
            ->assertJsonPath('display_surface_v1.subject.soc_code', '27-2011')
            ->assertJsonPath('display_surface_v1.subject.onet_code', '27-2011.00')
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.page.content.hero.title', '演员职业判断');

        $this->assertContains('fermat_decision_card', $response->json('display_surface_v1.component_order'));
        $this->assertIsArray($response->json('display_surface_v1.page.content.hero'));

        $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('release_gate', $encoded);
        $this->assertStringNotContainsString('qa_risk', $encoded);
        $this->assertStringNotContainsString('admin_review_state', $encoded);
        $this->assertStringNotContainsString('tracking_json', $encoded);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
    }

    public function test_it_does_not_add_display_surface_for_non_actors(): void
    {
        $this->seedCompiledOccupation('accountants-and-auditors');

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('display_surface_v1');
    }

    private function seedCompiledOccupation(string $slug): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-display-surface-'.$slug,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);
        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                ['materialization' => 'career_first_wave']
            ),
        ]);
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        return $chain['occupation'];
    }

    private function addActorsCrosswalks(Occupation $occupation): void
    {
        OccupationCrosswalk::query()
            ->where('occupation_id', $occupation->id)
            ->where('source_system', 'us_soc')
            ->update([
                'source_code' => '27-2011',
                'source_title' => 'Actors',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => '27-2011.00',
            'source_title' => 'Actors',
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);
    }

    private function createDisplayAsset(Occupation $occupation): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => 'actors',
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => ['hero', 'fermat_decision_card', 'market_signal_card', 'evidence_container'],
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'release_gate' => ['do_not_show' => true],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                ],
            ],
            'sources_json' => [
                'primary' => [
                    ['label' => 'BLS Occupational Outlook Handbook', 'url' => 'https://example.test/bls'],
                ],
            ],
            'structured_data_json' => [
                '@type' => 'Occupation',
                'name' => 'Actors',
                'raw_ai_exposure_score' => 8.2,
            ],
            'implementation_contract_json' => [
                'structured_data_policy' => 'visible_content_only',
                'tracking_json' => ['do_not_show' => true],
            ],
            'metadata_json' => [
                'validator_version' => 'career_asset_import_validator_v0.1',
            ],
        ]);
    }
}
