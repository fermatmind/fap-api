<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerAuditDetailReady1048Candidates;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerAuditDetailReady1048CandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DISPLAY_COMPONENT_ORDER = [
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
        'methodology_note',
        'trust_footer',
        'schema_anchor',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerAuditDetailReady1048Candidates::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:audit-detail-ready-1048-candidates', Artisan::all());
    }

    public function test_it_scans_detail_ready_candidates_without_mutation(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                items: [
                    [
                        'slug' => 'currently-public-actuary',
                        'locale' => 'en',
                        'detail_route_enabled' => true,
                        'dataset_visible' => true,
                        'search_visible' => true,
                        'robots_indexable' => true,
                        'release_gate_pass' => true,
                        'runtime_publish_state' => 'published',
                    ],
                ],
            ),
        );

        $this->createDocxCareerJob('currently-public-actuary');
        $this->createDocxCareerJob('accountants-and-auditors');
        $this->createDocxCareerJob('software-developers');
        $this->createDisplayAssetReadyOccupation('actors');

        $output = sys_get_temp_dir().'/career-detail-ready-1048-test-'.bin2hex(random_bytes(4)).'.json';

        $exitCode = Artisan::call('career:audit-detail-ready-1048-candidates', [
            '--json' => true,
            '--output' => $output,
        ]);
        $payload = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_detail_ready_publication_candidates.v1', $payload['schema_version']);
        $this->assertSame('detail_ready_1048', $payload['target_key']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertFalse($payload['deploy_allowed']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertSame(1048, $payload['target_authority']['target_public_total']);
        $this->assertFalse($payload['target_authority']['partition_boundary']['is_2786_partition_accounting']);
        $this->assertTrue($payload['target_authority']['manual_hold_policy']['must_not_force_enable']);
        $this->assertTrue($payload['product_visible_claim_boundary']['not_a_2786_partition_accounting_claim']);
        $this->assertSame(1, $payload['counts']['current_public_detail']);
        $this->assertSame(3, $payload['counts']['docx_ready']);
        $this->assertSame(1, $payload['counts']['display_asset_ready']);
        $this->assertSame(4, $payload['counts']['union_detail_ready']);
        $this->assertSame(3, $payload['counts']['ready_not_currently_public']);
        $this->assertContains('accountants-and-auditors', $payload['ready_not_public_1018']['slugs']);
        $this->assertContains('actors', $payload['ready_not_public_1018']['slugs']);
        $this->assertContains('software-developers', $payload['manual_hold']['ready_slugs']);
        $this->assertFileExists($output);

        @unlink($output);
    }

    private function createDocxCareerJob(string $slug): CareerJob
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'subtitle' => ucfirst(str_replace('-', ' ', $slug)),
            'excerpt' => 'Read-only fixture.',
            'market_demand_json' => [
                'source_refs' => [
                    ['url' => 'https://example.test/source'],
                ],
            ],
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
        ]);
        $job->seoMeta()->create([
            'jsonld_overrides_json' => [
                'source_docx' => 'fixture.docx',
            ],
        ]);

        return $job->refresh();
    }

    private function createDisplayAssetReadyOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'family-'.$slug,
            'title_en' => 'Fixture Family',
            'title_zh' => '测试职业族',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => ucfirst(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => '测试职业',
            'search_h1_zh' => '测试职业',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'display_asset_crosswalks',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'display-asset-crosswalks-'.$slug,
            'scope_mode' => 'display_asset_backed_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        foreach ([['us_soc', '27-2011'], ['onet_soc_2019', '27-2011.00']] as [$system, $code]) {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => $system,
                'source_code' => $code,
                'source_title' => (string) $occupation->canonical_title_en,
                'mapping_type' => 'direct_match',
                'confidence_score' => 1,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => hash('sha256', $system.'-'.$slug),
            ]);
        }

        CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => self::DISPLAY_COMPONENT_ORDER,
            'page_payload_json' => [
                'zh' => ['hero' => ['title' => '测试职业']],
                'en' => ['hero' => ['title' => 'Fixture Job']],
            ],
            'seo_payload_json' => [],
            'sources_json' => [],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
        ]);

        return $occupation->refresh();
    }
}
