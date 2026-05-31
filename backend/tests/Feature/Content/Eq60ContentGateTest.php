<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\Eq60PackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Eq60ContentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_lint_compile_and_questions_api_contract(): void
    {
        $this->artisan('content:lint --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);

        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $this->assertIsArray($questions);
        $this->assertSame('eq_60.questions.compiled.v1', (string) ($questions['schema'] ?? ''));
        $this->assertCount(60, (array) data_get($questions, 'questions_doc_by_locale.zh-CN.items', []));
        $this->assertCount(60, (array) data_get($questions, 'questions_doc_by_locale.en.items', []));

        $manifest = $loader->readCompiledJson('manifest.json', 'v1');
        $this->assertIsArray($manifest);
        $this->assertNotSame('', (string) ($manifest['compiled_hash'] ?? ''));

        $report = $loader->readCompiledJson('report.compiled.json', 'v1');
        $this->assertIsArray($report);
        $this->assertSame('eq_60.report.compiled.v2', (string) ($report['schema'] ?? ''));

        $sectionKeys = array_values(array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter((array) data_get($report, 'layout.sections', []), 'is_array')
        ));
        $this->assertSame([
            'disclaimer_top',
            'quality_notice',
            'global_overview',
            'self_awareness',
            'emotion_regulation',
            'empathy',
            'relationship_management',
            'cross_quadrant_insight',
            'action_plan_14d',
            'methodology',
            'disclaimer_bottom',
        ], $sectionKeys);
        $this->assertCount(102, (array) data_get($report, 'blocks', []));
        $this->assertNotEmpty((array) data_get($report, 'variables_allowlist.allowed', []));

        $assets = $loader->readCompiledJson('report_assets.compiled.json', 'v1');
        $this->assertIsArray($assets);
        $this->assertSame('eq_60.report_assets.compiled.v1', (string) ($assets['schema'] ?? ''));
        $this->assertSame([
            'scientific_contract',
            'score_system',
            'core_formulations',
            'mechanism_map',
            'reality_translation',
            'career_environment',
            'action_prescriptions',
            'cross_assessment_context',
            'sjt_bridge',
            'personalization_routes',
        ], array_keys((array) data_get($assets, 'assets', [])));

        $routes = (array) data_get($assets, 'assets.personalization_routes.routes', []);
        foreach (['balanced_integrated', 'high_empathy_low_recovery', 'aware_but_unregulated', 'low_confidence_result'] as $routeId) {
            $this->assertSame($routeId, (string) data_get($routes, $routeId.'.route_id'));
            $this->assertSame($routeId, (string) data_get($routes, $routeId.'.selected_asset_ids.core_formulation_id'));
            $this->assertNotSame('', (string) data_get($routes, $routeId.'.selected_asset_ids.action_prescription_id'));
        }

        foreach ([
            'balanced_integrated',
            'high_empathy_low_recovery',
            'aware_but_unregulated',
            'calm_but_distant',
            'relationship_first_self_later',
            'self_clear_repair_weak',
            'steady_collaborator',
            'sensitive_absorber',
            'developing_foundation',
            'low_confidence_result',
        ] as $formulationId) {
            foreach (['zh-CN', 'en'] as $locale) {
                $this->assertNotSame('', (string) data_get($assets, 'assets.core_formulations.formulations.'.$formulationId.'.'.$locale.'.title'));
                $this->assertNotSame('', (string) data_get($assets, 'assets.core_formulations.formulations.'.$formulationId.'.'.$locale.'.core_claim'));
            }
        }

        foreach (['SA_ER', 'EM_ER', 'EM_RM', 'SA_RM', 'ER_RM'] as $pair) {
            foreach (['high_high', 'high_low', 'low_high', 'low_low'] as $state) {
                $this->assertNotSame('', (string) data_get($assets, 'assets.mechanism_map.pairs.'.$pair.'.'.$state.'.zh-CN.title'));
                $this->assertNotSame('', (string) data_get($assets, 'assets.mechanism_map.pairs.'.$pair.'.'.$state.'.en.title'));
            }
        }

        foreach (['feedback', 'conflict', 'relationship_boundary', 'team_collaboration', 'pressure_recovery', 'career_environment'] as $scene) {
            $this->assertNotSame('', (string) data_get($assets, 'assets.reality_translation.scenes.'.$scene.'.zh-CN.better_move'));
            $this->assertNotSame('', (string) data_get($assets, 'assets.reality_translation.scenes.'.$scene.'.en.better_move'));
        }

        foreach (['interpersonal_density', 'emotional_labor', 'conflict_frequency', 'feedback_intensity', 'autonomy_recovery', 'collaboration_complexity'] as $variable) {
            foreach (['low', 'medium', 'high'] as $level) {
                $this->assertNotSame('', (string) data_get($assets, 'assets.career_environment.variables.'.$variable.'.'.$level.'.zh-CN.what_to_verify'));
                $this->assertNotSame('', (string) data_get($assets, 'assets.career_environment.variables.'.$variable.'.'.$level.'.en.what_to_verify'));
            }
        }

        foreach ([
            'emotion_labeling',
            'pause_recovery',
            'feedback_decompression',
            'empathy_boundary',
            'repair_after_conflict',
            'express_without_escalation',
            'support_without_rescuing',
            'cold_to_warm_response',
            'relationship_energy_management',
            'conflict_deescalation',
            'self_connection',
            'retest_reflection',
        ] as $prescriptionId) {
            $this->assertNotEmpty((array) data_get($assets, 'assets.action_prescriptions.prescriptions.'.$prescriptionId.'.zh-CN.seven_day_plan'));
            $this->assertNotEmpty((array) data_get($assets, 'assets.action_prescriptions.prescriptions.'.$prescriptionId.'.en.seven_day_plan'));
        }

        $crossContextAssets = (array) data_get($assets, 'assets.cross_assessment_context.assets', []);
        foreach ([
            'eq.cross_context.boundary.default',
            'eq.cross_context.mbti.available',
            'eq.cross_context.big_five.available',
            'eq.cross_context.enneagram.available',
        ] as $assetId) {
            $asset = is_array($crossContextAssets[$assetId] ?? null) ? $crossContextAssets[$assetId] : [];
            $this->assertNotSame('', (string) data_get($asset, 'zh-CN.title'));
            $this->assertNotSame('', (string) data_get($asset, 'en.title'));
            $this->assertStringNotContainsString('predicts job performance', (string) data_get($asset, 'en.claim_boundary'));
            $this->assertStringNotContainsString('certified emotional intelligence', (string) data_get($asset, 'en.claim_boundary'));
        }

        $sjtAssets = (array) data_get($assets, 'assets.sjt_bridge.assets', []);
        $plannedSjt = is_array($sjtAssets['eq.sjt_bridge.planned'] ?? null) ? $sjtAssets['eq.sjt_bridge.planned'] : [];
        $this->assertFalse((bool) data_get($plannedSjt, 'zh-CN.available', true));
        $this->assertStringContainsString('not MSCEIT', (string) data_get($plannedSjt, 'en.what_it_is_not'));

        foreach (glob(base_path('content_packs/EQ_60/v1/raw/report_assets/*.json')) ?: [] as $eq60AssetPath) {
            $mirrorPath = str_replace('/EQ_60/', '/EQ_EMOTIONAL_INTELLIGENCE/', $eq60AssetPath);
            $this->assertFileExists($mirrorPath);
            $this->assertSame(
                hash_file('sha256', $eq60AssetPath),
                hash_file('sha256', $mirrorPath),
                'EQ_EMOTIONAL_INTELLIGENCE mirror drift: '.basename($eq60AssetPath)
            );
        }
        foreach (glob(base_path('content_packs/EQ_60/v1/raw/personalization_routes/*.json')) ?: [] as $eq60RoutePath) {
            $mirrorPath = str_replace('/EQ_60/', '/EQ_EMOTIONAL_INTELLIGENCE/', $eq60RoutePath);
            $this->assertFileExists($mirrorPath);
            $this->assertSame(
                hash_file('sha256', $eq60RoutePath),
                hash_file('sha256', $mirrorPath),
                'EQ_EMOTIONAL_INTELLIGENCE route mirror drift: '.basename($eq60RoutePath)
            );
        }
        $this->assertFileExists(base_path('content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/report_assets.compiled.json'));

        (new ScaleRegistrySeeder)->run();

        $zh = $this->getJson('/api/v0.3/scales/EQ_60/questions?locale=zh-CN&region=CN_MAINLAND');
        $zh->assertStatus(200);
        $zh->assertJsonPath('scale_code', 'EQ_60');
        $zh->assertJsonPath('locale', 'zh-CN');
        $this->assertCount(60, (array) data_get($zh->json(), 'questions.items', []));
        $this->assertCount(5, (array) data_get($zh->json(), 'meta.option_anchors', []));
        $this->assertSame(['SA', 'ER', 'EM', 'RM'], array_values((array) data_get($zh->json(), 'meta.dimension_codes', [])));

        $en = $this->getJson('/api/v0.3/scales/EQ_60/questions?locale=en&region=GLOBAL');
        $en->assertStatus(200);
        $en->assertJsonPath('scale_code', 'EQ_60');
        $en->assertJsonPath('locale', 'en');
        $this->assertCount(60, (array) data_get($en->json(), 'questions.items', []));
        $this->assertCount(5, (array) data_get($en->json(), 'meta.option_anchors', []));
    }
}
