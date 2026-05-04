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

    private const PILOT_SLUGS = [
        'actors' => ['soc' => '27-2011', 'onet' => '27-2011.00', 'title' => 'Actors'],
        'data-scientists' => ['soc' => '15-2051', 'onet' => '15-2051.00', 'title' => 'Data Scientists'],
        'registered-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.00', 'title' => 'Registered Nurses'],
        'accountants-and-auditors' => ['soc' => '13-2011', 'onet' => '13-2011.00', 'title' => 'Accountants and Auditors'],
        'actuaries' => ['soc' => '15-2011', 'onet' => '15-2011.00', 'title' => 'Actuaries'],
        'financial-analysts' => ['soc' => '13-2051', 'onet' => '13-2051.00', 'title' => 'Financial Analysts'],
        'high-school-teachers' => ['soc' => '25-2031', 'onet' => '25-2031.00', 'title' => 'High School Teachers'],
        'market-research-analysts' => ['soc' => '13-1161', 'onet' => '13-1161.00', 'title' => 'Market Research Analysts'],
        'architectural-and-engineering-managers' => ['soc' => '11-9041', 'onet' => '11-9041.00', 'title' => 'Architectural and Engineering Managers'],
        'civil-engineers' => ['soc' => '17-2051', 'onet' => '17-2051.00', 'title' => 'Civil Engineers'],
        'biomedical-engineers' => ['soc' => '17-2031', 'onet' => '17-2031.00', 'title' => 'Biomedical Engineers'],
        'dentists' => ['soc' => '29-1021', 'onet' => '29-1021.00', 'title' => 'Dentists'],
    ];

    private const COMPONENT_ORDER = [
        'breadcrumb',
        'hero',
        'fermat_decision_card',
        'primary_cta',
        'career_snapshot_primary_locale',
        'career_snapshot_secondary_locale',
        'fit_decision_checklist',
        'riasec_fit_block',
        'personality_fit_block',
        'definition_block',
        'responsibilities_block',
        'work_context_block',
        'market_signal_card',
        'adjacent_career_comparison_table',
        'ai_impact_table',
        'career_risk_cards',
        'contract_project_risk_block',
        'next_steps_block',
        'faq_block',
        'related_next_pages',
        'source_card',
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

    public function test_it_adds_display_surface_for_eligible_actors_asset(): void
    {
        $occupation = $this->seedCompiledOccupation('actors');
        $this->addCrosswalks($occupation, 'actors');
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
        $this->assertCount(24, $response->json('display_surface_v1.component_order'));
        $this->assertIsArray($response->json('display_surface_v1.page.content.hero'));

        $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('release_gate', $encoded);
        $this->assertStringNotContainsString('release_gates', $encoded);
        $this->assertStringNotContainsString('qa_risk', $encoded);
        $this->assertStringNotContainsString('admin_review_state', $encoded);
        $this->assertStringNotContainsString('tracking_json', $encoded);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
    }

    public function test_it_adds_display_surface_for_selected_second_pilot_assets(): void
    {
        foreach (['data-scientists', 'registered-nurses', 'accountants-and-auditors'] as $slug) {
            $occupation = $this->seedCompiledOccupation($slug);
            $this->addCrosswalks($occupation, $slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonPath('display_surface_v1.template_version', 'v4.2')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_it_adds_display_surface_for_selected_d5_assets(): void
    {
        foreach ([
            'actuaries',
            'financial-analysts',
            'high-school-teachers',
            'market-research-analysts',
            'architectural-and-engineering-managers',
            'civil-engineers',
            'biomedical-engineers',
            'dentists',
        ] as $slug) {
            $occupation = $this->seedCompiledOccupation($slug);
            $this->addCrosswalks($occupation, $slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonPath('display_surface_v1.template_version', 'v4.2')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $this->assertContains('fermat_decision_card', $response->json('display_surface_v1.component_order'));
            $this->assertCount(24, $response->json('display_surface_v1.component_order'));

            $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_it_does_not_add_display_surface_for_non_selected_slug(): void
    {
        $occupation = $this->seedCompiledOccupation('veterinary-technologists-and-technicians');
        $this->createDisplayAsset($occupation);

        $this->getJson('/api/v0.5/career/jobs/veterinary-technologists-and-technicians?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'veterinary-technologists-and-technicians')
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

    private function addCrosswalks(Occupation $occupation, string $slug): void
    {
        $meta = self::PILOT_SLUGS[$slug];

        OccupationCrosswalk::query()
            ->where('occupation_id', $occupation->id)
            ->where('source_system', 'us_soc')
            ->update([
                'source_code' => $meta['soc'],
                'source_title' => $meta['title'],
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => $meta['onet'],
            'source_title' => $meta['title'],
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);
    }

    private function createDisplayAsset(Occupation $occupation): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => self::COMPONENT_ORDER,
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'boundary_notice' => [
                        'release_gate' => ['do_not_show' => true],
                        'release_gates' => [
                            'sitemap' => false,
                            'llms' => false,
                        ],
                    ],
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
