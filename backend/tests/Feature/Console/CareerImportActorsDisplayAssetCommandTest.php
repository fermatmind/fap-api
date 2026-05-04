<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerImportActorsDisplayAssetCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_run_without_force_writes_zero_rows(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['would_write']);
        $this->assertFalse($report['did_write']);
        $this->assertSame('pass', $report['decision']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function explicit_dry_run_writes_zero_rows(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file, ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['would_write']);
        $this->assertFalse($report['did_write']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function force_writes_exactly_one_actors_display_asset_row(): void
    {
        $occupation = $this->createOccupation();
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertTrue($report['did_write']);
        $this->assertNotEmpty($report['row_id']);
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertDatabaseHas('career_job_display_assets', [
            'occupation_id' => $occupation->id,
            'canonical_slug' => 'actors',
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
        ]);
    }

    #[Test]
    public function repeated_force_updates_the_same_slug_and_asset_version_row(): void
    {
        $this->createOccupation();
        $first = $this->writeAsset();
        $second = $this->writeAsset(function (array &$payload): void {
            $payload['page']['zh']['hero']['title'] = '演员职业判断 updated';
        });

        $this->runImport($first, ['--force' => true]);
        [$exitCode, $report] = $this->runImport($second, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($report['did_write']);
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $asset = CareerJobDisplayAsset::query()->firstOrFail();
        $this->assertSame('演员职业判断 updated', $asset->page_payload_json['zh']['hero']['title']);
    }

    #[Test]
    public function bad_slug_is_rejected(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset(function (array &$payload): void {
            $payload['asset']['slug'] = 'directors';
        });

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertFalse($report['would_write']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function missing_soc_is_rejected(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset(function (array &$payload): void {
            unset($payload['asset']['soc_code']);
        });

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('asset.soc_code must be 27-2011.', implode(' ', $report['errors']));
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function missing_onet_is_rejected(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset(function (array &$payload): void {
            unset($payload['asset']['onet_code']);
        });

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('asset.onet_code must be 27-2011.00.', implode(' ', $report['errors']));
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function missing_occupation_is_rejected(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['occupation_found']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function mismatched_crosswalk_is_rejected(): void
    {
        $this->createOccupation(withCrosswalks: false);
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['soc_crosswalk_valid']);
        $this->assertFalse($report['onet_crosswalk_valid']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function forbidden_public_payload_key_is_rejected(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset(function (array &$payload): void {
            $payload['page']['zh']['release_gate'] = ['hidden' => true];
            $payload['page']['zh']['boundary_notice']['release_gates'] = ['sitemap' => false];
        });

        [$exitCode, $report] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertEqualsCanonicalizing([
            'page_payload_json.zh.release_gate',
            'page_payload_json.zh.boundary_notice.release_gates',
        ], $report['public_payload_forbidden_keys_found']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function dry_run_and_force_together_are_rejected(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runImport($file, [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('invalid', $report['mode']);
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function output_option_writes_the_json_report_file(): void
    {
        $this->createOccupation();
        $file = $this->writeAsset();
        $output = $this->tempDir().'/report.json';

        [$exitCode, $report] = $this->runImport($file, [
            '--dry-run' => true,
            '--output' => $output,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertFileExists($output);
        $written = json_decode((string) file_get_contents($output), true);
        $this->assertIsArray($written);
        $this->assertSame($report['validator_version'], $written['validator_version']);
    }

    #[Test]
    public function api_after_command_created_asset_includes_display_surface_v1(): void
    {
        $this->seedCompiledOccupation('actors');
        $file = $this->writeAsset();

        [$exitCode] = $this->runImport($file, ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'actors')
            ->assertJsonPath('display_surface_v1.template_version', 'v4.2')
            ->assertJsonPath('display_surface_v1.subject.soc_code', '27-2011')
            ->assertJsonPath('display_surface_v1.subject.onet_code', '27-2011.00')
            ->assertJsonPath('display_surface_v1.page.content.hero.title', '演员职业判断');
    }

    #[Test]
    public function non_actors_still_return_no_display_surface_v1(): void
    {
        $this->seedCompiledOccupation('accountants-and-auditors');

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('display_surface_v1');
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runImport(string $file, array $options = []): array
    {
        $exitCode = Artisan::call('career:import-actors-display-asset', array_merge([
            '--file' => $file,
            '--json' => true,
        ], $options));

        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, Artisan::output());

        return [$exitCode, $report];
    }

    private function createOccupation(bool $withCrosswalks = true): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'performing-arts',
            'title_en' => 'Performing Arts',
            'title_zh' => '表演艺术',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'actors',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Actors',
            'canonical_title_zh' => '演员',
            'search_h1_zh' => '演员职业诊断',
        ]);

        if ($withCrosswalks) {
            $this->addActorsCrosswalks($occupation);
        } else {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => 'us_soc',
                'source_code' => '27-2012',
                'source_title' => 'Producers and Directors',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);
        }

        return $occupation;
    }

    private function seedCompiledOccupation(string $slug): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-display-surface-command-'.$slug,
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

        if ($slug === 'actors') {
            $this->addActorsCrosswalks($chain['occupation']);
        }

        return $chain['occupation'];
    }

    private function addActorsCrosswalks(Occupation $occupation): void
    {
        OccupationCrosswalk::query()->updateOrCreate(
            [
                'occupation_id' => $occupation->id,
                'source_system' => 'us_soc',
            ],
            [
                'source_code' => '27-2011',
                'source_title' => 'Actors',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]
        );

        OccupationCrosswalk::query()->updateOrCreate(
            [
                'occupation_id' => $occupation->id,
                'source_system' => 'onet_soc_2019',
            ],
            [
                'source_code' => '27-2011.00',
                'source_title' => 'Actors',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]
        );
    }

    private function writeAsset(?callable $mutator = null): string
    {
        $payload = $this->assetPayload();
        if ($mutator !== null) {
            $mutator($payload);
        }

        $path = $this->tempDir().'/actors_v4_2_pilot_master.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(): array
    {
        return [
            'asset' => [
                'template_name' => 'career_job_public_display',
                'template_version' => 'v4.2',
                'asset_role' => 'formal_pilot_master',
                'asset_type' => 'career_job_public_display',
                'public_display_only' => true,
                'slug' => 'actors',
                'soc_code' => '27-2011',
                'onet_code' => '27-2011.00',
                'routes' => [
                    'zh' => '/zh/career/jobs/actors',
                    'en' => '/en/career/jobs/actors',
                ],
                'canonical' => [
                    'title_en' => 'Actors',
                    'title_zh' => '演员',
                ],
                'not_included_in_public_display' => [
                    'release_gate',
                    'release_gates',
                    'qa_risk',
                    'admin_review_state',
                    'tracking_json',
                    'raw_ai_exposure_score',
                ],
            ],
            'seo' => [
                'title' => [
                    'zh' => '演员职业判断',
                    'en' => 'Actor career fit',
                ],
            ],
            'component_order' => [
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
            ],
            'page' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'decision_card' => ['summary' => '适合以表演为核心的人。'],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                    'decision_card' => ['summary' => 'For people centered on performance work.'],
                ],
            ],
            'sources' => [
                'primary' => [
                    ['label' => 'BLS Occupational Outlook Handbook', 'url' => 'https://example.test/bls'],
                ],
            ],
            'structured_data_from_visible_content' => [
                '@type' => 'Occupation',
                'name' => 'Actors',
            ],
            'implementation_contract' => [
                'structured_data_policy' => 'visible_content_only',
            ],
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/career-import-actors-display-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
