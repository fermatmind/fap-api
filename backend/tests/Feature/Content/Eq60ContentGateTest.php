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
            'reality_scene_variants',
            'career_environment',
            'action_prescriptions',
            'cross_assessment_context',
            'seo_geo_authority',
            'sjt_bridge',
            'result_snapshot',
            'commercial_conversion_assets',
            'quality_confidence',
            'psychometric_evidence_status',
            'result_page_depth_modules',
            'agent_knowledge_base_schema',
            'agent_dialogue_playbooks',
            'backend_integration_contract',
            'personalization_routes',
        ], array_keys((array) data_get($assets, 'assets', [])));

        $routes = (array) data_get($assets, 'assets.personalization_routes.routes', []);
        $this->assertGreaterThanOrEqual(60, count($routes));
        foreach (['balanced_integrated', 'high_empathy_low_recovery', 'aware_but_unregulated', 'low_confidence_result'] as $formulationId) {
            $matched = array_values(array_filter($routes, static fn ($route): bool => is_array($route) && (string) ($route['formulation_id'] ?? '') === $formulationId));
            $this->assertNotEmpty($matched, 'Missing v2 route for '.$formulationId);
            $route = (array) $matched[0];
            $this->assertNotSame('', (string) ($route['route_id'] ?? ''));
            $this->assertNotSame('', (string) data_get($route, 'selected_asset_ids.core_formulation'));
            $this->assertNotSame('', (string) data_get($route, 'selected_asset_ids.action_prescription'));
            $this->assertNotSame('', (string) data_get($route, 'locales.zh-CN.route_headline'));
            $this->assertNotSame('', (string) data_get($route, 'locales.en.route_headline'));
        }

        $depthAssets = (array) data_get($assets, 'assets.result_page_depth_modules.assets', []);
        $this->assertGreaterThanOrEqual(60, count($depthAssets));
        foreach (['balanced_integrated', 'high_empathy_low_recovery', 'aware_but_unregulated', 'low_confidence_result'] as $formulationId) {
            foreach (['evidence_stack', 'development_path'] as $moduleType) {
                $assetId = 'eq.depth.'.$moduleType.'.'.$formulationId;
                $this->assertArrayHasKey($assetId, $depthAssets);
                $depthAsset = (array) $depthAssets[$assetId];
                $this->assertSame($moduleType, (string) data_get($depthAsset, 'meta.module_type'));
                $this->assertContains($formulationId, (array) data_get($depthAsset, 'meta.applies_to'));
                $this->assertNotSame('', (string) data_get($depthAsset, 'zh-CN.title'));
                $this->assertNotSame('', (string) data_get($depthAsset, 'en.title'));
            }
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

        $sceneVariants = (array) data_get($assets, 'assets.reality_scene_variants.assets', []);
        $this->assertGreaterThanOrEqual(180, count($sceneVariants));
        foreach ([
            'eq.scene.feedback.high_empathy_low_recovery.primary',
            'eq.scene.conflict.aware_but_unregulated.primary',
            'eq.scene.relationship_boundary.low_confidence_result.primary',
            'eq.scene.team_collaboration.team_stabilizer.primary',
        ] as $assetId) {
            $this->assertArrayHasKey($assetId, $sceneVariants);
            $asset = (array) $sceneVariants[$assetId];
            $this->assertNotSame('', (string) data_get($asset, 'zh-CN.typical_response'));
            $this->assertNotSame('', (string) data_get($asset, 'en.typical_response'));
            $this->assertNotSame('', (string) data_get($asset, 'zh-CN.micro_script'));
            $this->assertNotSame('', (string) data_get($asset, 'en.micro_script'));
            $this->assertNotEmpty((array) data_get($asset, 'zh-CN.evidence_signals'));
            $this->assertNotEmpty((array) data_get($asset, 'en.evidence_signals'));
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

        $resultSnapshotAssets = (array) data_get($assets, 'assets.result_snapshot.assets', []);
        $highEmpathySnapshot = is_array($resultSnapshotAssets['eq.snapshot.high_empathy_low_recovery'] ?? null)
            ? (array) $resultSnapshotAssets['eq.snapshot.high_empathy_low_recovery']
            : [];
        $this->assertStringContainsString('恢复系统', (string) data_get($highEmpathySnapshot, 'zh-CN.core_judgment'));
        $this->assertStringContainsString('recovery can lag', (string) data_get($highEmpathySnapshot, 'en.core_judgment'));
        $this->assertNotEmpty((array) data_get($highEmpathySnapshot, 'en.conversion_actions'));

        $conversionAssets = (array) data_get($assets, 'assets.commercial_conversion_assets.assets', []);
        foreach ([
            'eq.conversion.save_report',
            'eq.conversion.email_revisit',
            'eq.conversion.pdf_export',
            'eq.conversion.share_card',
            'eq.conversion.retest_reminder',
            'eq.conversion.related_tests',
            'eq.conversion.agent_entry',
        ] as $assetId) {
            $asset = is_array($conversionAssets[$assetId] ?? null) ? (array) $conversionAssets[$assetId] : [];
            $this->assertNotSame('', (string) data_get($asset, 'zh-CN.cta_label'));
            $this->assertNotSame('', (string) data_get($asset, 'en.cta_label'));
        }
        $this->assertStringNotContainsString('SKU_EQ_60_FULL_299', json_encode($conversionAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringNotContainsString('购买', json_encode($conversionAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringNotContainsString('解锁', json_encode($conversionAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $qualityAssets = (array) data_get($assets, 'assets.quality_confidence.assets', []);
        $qualityA = is_array($qualityAssets['eq.quality.level.A'] ?? null) ? (array) $qualityAssets['eq.quality.level.A'] : [];
        $this->assertNotSame('', (string) data_get($qualityA, 'zh-CN.why_this_level'));
        $this->assertNotSame('', (string) data_get($qualityA, 'en.how_to_read'));

        $evidenceAssets = (array) data_get($assets, 'assets.psychometric_evidence_status.assets', []);
        $contentValidity = is_array($evidenceAssets['eq.evidence.content_validity'] ?? null) ? (array) $evidenceAssets['eq.evidence.content_validity'] : [];
        $this->assertNotSame('', (string) data_get($contentValidity, 'zh-CN.user_facing_status_label'));
        $this->assertNotSame('', (string) data_get($contentValidity, 'en.next_validation_step'));

        $agentKnowledgeSchema = (array) data_get($assets, 'assets.agent_knowledge_base_schema', []);
        $this->assertSame('eq60.report_assets.agent_knowledge_base_schema.v1', (string) data_get($agentKnowledgeSchema, 'schema'));
        $this->assertSame('backend_content_pack_and_report_composer', (string) data_get($agentKnowledgeSchema, 'authority.report_authority'));
        $this->assertContains('enable_sjt', (array) data_get($agentKnowledgeSchema, 'authority.agent_must_not', []));
        $this->assertContains('risk:sjt_unavailable', (array) data_get($agentKnowledgeSchema, 'retrieval_tag_taxonomy.core_tags', []));
        foreach (['SA', 'ER', 'EM', 'RM'] as $dimensionCode) {
            $this->assertContains('dimension:'.$dimensionCode, (array) data_get($agentKnowledgeSchema, 'retrieval_tag_taxonomy.core_tags', []));
        }
        foreach ([
            'understand_my_result',
            'why_this_result',
            'how_to_improve',
            'career_environment_fit',
            'relationship_or_conflict_help',
            'quality_or_confidence_question',
            'compare_with_other_tests',
            'ask_for_sjt',
            'share_or_save_report',
            'clinical_or_hiring_request',
        ] as $intentId) {
            $this->assertSame($intentId, (string) data_get($agentKnowledgeSchema, 'user_intent_map.intents.'.$intentId.'.intent_id'));
            $this->assertNotEmpty((array) data_get($agentKnowledgeSchema, 'user_intent_map.intents.'.$intentId.'.retrieval_tags'));
            $this->assertNotSame('', (string) data_get($agentKnowledgeSchema, 'user_intent_map.intents.'.$intentId.'.zh-CN.safe_opening'));
            $this->assertNotSame('', (string) data_get($agentKnowledgeSchema, 'user_intent_map.intents.'.$intentId.'.en.safe_opening'));
        }
        $this->assertSame('critical', (string) data_get($agentKnowledgeSchema, 'forbidden_claims.claims.true_emotional_ability.severity'));
        $this->assertSame('critical', (string) data_get($agentKnowledgeSchema, 'forbidden_claims.claims.msceit_like.severity'));
        $this->assertSame('high', (string) data_get($agentKnowledgeSchema, 'forbidden_claims.claims.paid_unlock_required.severity'));
        $this->assertSame('not_implemented', (string) data_get($agentKnowledgeSchema, 'maintenance.agent_runtime_status'));
        $this->assertSame('planned_unavailable', (string) data_get($agentKnowledgeSchema, 'maintenance.sjt_status'));

        $agentPlaybooks = (array) data_get($assets, 'assets.agent_dialogue_playbooks.assets', []);
        $understandResult = is_array($agentPlaybooks['eq.agent.playbook.understand_result'] ?? null) ? (array) $agentPlaybooks['eq.agent.playbook.understand_result'] : [];
        $this->assertNotSame('', (string) data_get($understandResult, 'zh-CN.clarifying_question'));
        $this->assertNotSame('', (string) data_get($understandResult, 'en.refusal_example'));

        $backendContractAssets = (array) data_get($assets, 'assets.backend_integration_contract.assets', []);
        $schemaMapping = is_array($backendContractAssets['eq.backend_contract.schema_mapping'] ?? null) ? (array) $backendContractAssets['eq.backend_contract.schema_mapping'] : [];
        $this->assertNotSame('', (string) data_get($schemaMapping, 'zh-CN.requirement'));
        $this->assertStringContainsString('compiler', (string) data_get($schemaMapping, 'en.requirement'));

        $seoGeoAssets = (array) data_get($assets, 'assets.seo_geo_authority.assets', []);
        $seoGeoAsset = is_array($seoGeoAssets['eq.seo_geo_authority.en_landing.default'] ?? null)
            ? (array) $seoGeoAssets['eq.seo_geo_authority.en_landing.default']
            : [];
        $this->assertSame('/en/tests/eq-test-emotional-intelligence-assessment', (string) data_get($assets, 'assets.seo_geo_authority.public_page.canonical_path'));
        $this->assertTrue((bool) data_get($assets, 'assets.seo_geo_authority.public_page.sitemap_eligible'));
        $this->assertTrue((bool) data_get($assets, 'assets.seo_geo_authority.public_page.llms_eligible'));
        $this->assertStringContainsString('Free EQ Test', (string) data_get($seoGeoAsset, 'en.meta_title'));
        $this->assertStringContainsString('self-report', (string) data_get($seoGeoAsset, 'en.meta_description'));
        $this->assertStringContainsString('not for clinical diagnosis', (string) data_get($seoGeoAsset, 'en.claim_boundary'));
        $this->assertStringNotContainsString('predicts job performance', json_encode($seoGeoAsset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

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
        $en->assertJsonPath('meta.seo_geo_authority.schema', 'eq.seo_geo_authority.public.v1');
        $en->assertJsonPath('meta.seo_geo_authority.authority_source', 'backend_content_pack');
        $en->assertJsonPath('meta.seo_geo_authority.canonical_path', '/en/tests/eq-test-emotional-intelligence-assessment');
        $en->assertJsonPath('meta.seo_geo_authority.sitemap_eligible', true);
        $en->assertJsonPath('meta.seo_geo_authority.llms_eligible', true);
        $en->assertJsonPath('meta.seo_geo_authority.structured_data.assessment_mode', 'self_report_trait_mixed_ei');
    }
}
