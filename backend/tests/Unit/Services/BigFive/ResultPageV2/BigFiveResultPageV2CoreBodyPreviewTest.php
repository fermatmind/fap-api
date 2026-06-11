<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use Tests\TestCase;

final class BigFiveResultPageV2CoreBodyPreviewTest extends TestCase
{
    private const FIXTURE_FILE = 'canonical_o59_core_body_preview.payload.json';

    private const CORE_BODY_FILE = 'content_assets/big5/result_page_v2/core_body/v0_1/canonical_o59_c32_e20_a55_n68.core_body.json';

    private const MODULE_MAPPING_FILE = 'content_assets/big5/result_page_v2/governance/big5_v2_module_to_section_mapping_v0_1.json';

    private const ANTI_TARGET_FILE = 'content_assets/big5/result_page_v2/governance/big5_v2_anti_target_render_terms_v0_1.json';

    private const REQUIRED_SECTIONS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
    ];

    private const VISIBLE_FIELDS = [
        'title_zh',
        'subtitle_zh',
        'summary_zh',
        'body_zh',
        'bullets_zh',
        'table_zh',
        'action_zh',
        'cta_zh',
    ];

    public function test_preview_payload_and_o59_core_body_json_parse(): void
    {
        $this->assertIsArray($this->previewEnvelope());
        $this->assertIsArray($this->coreBody());
    }

    public function test_preview_payload_uses_big5_result_page_v2_contract(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(BigFiveResultPageV2Contract::SCHEMA_VERSION, $payload['schema_version'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload['payload_key'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::SCALE_CODE, $payload['scale_code'] ?? null);
        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($this->previewEnvelope()));
    }

    public function test_preview_payload_references_canonical_scores_and_profile_label(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(59, data_get($payload, 'projection_v2.domains.O.score'));
        $this->assertSame(32, data_get($payload, 'projection_v2.domains.C.score'));
        $this->assertSame(20, data_get($payload, 'projection_v2.domains.E.score'));
        $this->assertSame(55, data_get($payload, 'projection_v2.domains.A.score'));
        $this->assertSame(68, data_get($payload, 'projection_v2.domains.N.score'));
        $this->assertSame('敏锐的独立思考者', $payload['profile_label_zh'] ?? null);
        $this->assertSame('敏锐的独立思考者', data_get($payload, 'projection_v2.profile_signature.label_zh'));
    }

    public function test_all_8_source_sections_are_represented_with_mapping_trace(): void
    {
        $payload = $this->previewPayload();
        $sections = $this->sourceSectionsRepresentedByBlocks($payload);

        $this->assertSame(self::REQUIRED_SECTIONS, data_get($payload, 'b5_a_lite_section_trace.runtime_sections'));
        $this->assertSame($this->moduleMapping()['runtime_sections'], data_get($payload, 'b5_a_lite_section_trace.runtime_sections'));
        $this->assertSame(self::REQUIRED_SECTIONS, array_values(array_intersect(self::REQUIRED_SECTIONS, $sections)));
    }

    public function test_preview_payload_blocks_are_module_based_and_renderable(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_map(
            static fn (array $module): string => (string) $module['module_key'],
            $payload['modules']
        ));

        foreach ($payload['modules'] as $module) {
            $moduleKey = (string) $module['module_key'];
            $this->assertNotEmpty($module['blocks'], $moduleKey);

            foreach ($module['blocks'] as $block) {
                $this->assertStringStartsWith($moduleKey.'.', (string) $block['block_key']);
                $this->assertSame($moduleKey, $block['module_key'] ?? null);
                $this->assertContains($block['block_kind'] ?? null, BigFiveResultPageV2Contract::BLOCK_KINDS);
                $this->assertIsArray($block['content'] ?? null);
                $this->assertNotEmpty($this->visibleText((array) $block['content']));
                $this->assertContains($block['source_section_key'] ?? null, self::REQUIRED_SECTIONS);
                $this->assertIsArray($block['source_modules'] ?? null);
            }
        }
    }

    public function test_visible_fields_do_not_contain_anti_target_terms_or_internal_notes(): void
    {
        $visibleText = $this->visibleText($this->previewPayload());

        foreach ($this->antiTargetTerms() as $term) {
            if ($term === 'all') {
                $this->assertDoesNotMatchRegularExpression('/(^|[\\s:：])all($|[\\s,，.。:：])/iu', $visibleText);

                continue;
            }

            $this->assertStringNotContainsString($term, $visibleText, $term);
        }

        foreach (['internal_metadata', 'selection_guidance', 'selector_basis', 'editor_notes', 'qa_notes'] as $internalLeak) {
            $this->assertStringNotContainsString($internalLeak, $visibleText, $internalLeak);
        }
    }

    public function test_preview_includes_chinese_hero_body_from_o59_core_body(): void
    {
        $heroBody = data_get($this->coreBody(), 'sections.0.blocks.0.body_zh');
        $this->assertIsString($heroBody);

        $this->assertStringContainsString($heroBody, $this->visibleText($this->previewPayload()));
    }

    public function test_facet_content_is_explanatory_not_pure_percentile_list(): void
    {
        $facetText = $this->visibleTextForSection('facet_details');

        $this->assertStringContainsString('解释性推断', $facetText);
        $this->assertStringContainsString('并非独立测量结论', $facetText);
        $this->assertStringNotContainsString('N1 百分位', $facetText);
        $this->assertDoesNotMatchRegularExpression('/^\\s*(N\\d|O\\d|C\\d|E\\d|A\\d)\\s*[:：]?\\s*\\d+\\s*$/um', $facetText);
    }

    public function test_action_content_covers_required_application_contexts(): void
    {
        $actionText = $this->visibleTextForSection('action_plan');

        foreach (['workplace', 'relationship', 'stress'] as $requiredScenario) {
            $this->assertStringContainsString($requiredScenario, $actionText);
        }
        $this->assertMatchesRegularExpression('/growth\\/action|成长行动/u', $actionText);
    }

    public function test_methodology_content_includes_privacy_and_data_use(): void
    {
        $methodologyText = $this->visibleTextForSection('methodology_and_access');

        foreach (['隐私', '数据使用', '删除个人测试结果', '4-8 周'] as $requiredPhrase) {
            $this->assertStringContainsString($requiredPhrase, $methodologyText);
        }
    }

    public function test_preview_payload_is_staging_only_and_not_production_allowed(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame('staging_only', $payload['runtime_use'] ?? null);
        $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
    }

    public function test_runtime_paths_have_no_uncommitted_diff(): void
    {
        $runtimePaths = [
            'backend/app',
            'backend/routes',
            'backend/database',
            'backend/content_packs',
            'frontend',
            'selector_ready_assets',
        ];

        $changed = $this->gitChangedFilesInBranchDiff($runtimePaths);

        $this->assertSame([], $changed);
    }

    public function test_runtime_freeze_classifier_ignores_career_only_artisan_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/CareerAlignD8AuthorityCrosswalks.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\CareerAlignD8AuthorityCrosswalks;',
            '+        CareerAlignD8AuthorityCrosswalks::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_career_cli_artifact_path_guard_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/CareerCliArtifactPathGuard.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_detail_ready_1048_audit_scanner_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerDetailReadyPublicationCandidateScanner.php',
            'backend/app/Domain/Career/Audit/CareerDetailReadyTargetAuthority.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_cms_article_report_correctness_changes(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/ArticleResource/Pages/CreateArticle.php',
            'backend/app/Filament/Ops/Resources/ArticleResource/Pages/EditArticle.php',
            'backend/app/Filament/Ops/Resources/ArticleResource/Support/ArticleSeoMetaWorkspace.php',
            'backend/app/Models/ReportSnapshot.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_personality_enneagram_compatibility_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php',
            'backend/app/PersonalityCms/DesktopClone/PersonalityDesktopCloneAssetSlotSupport.php',
            'backend/app/Services/Experiments/ExperimentAssigner.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_mbti_personality_variant_seo_metadata_refresh_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/PersonalityRefreshMbtiVariantSeoMetadata.php',
            'backend/app/Services/Cms/MbtiPersonalityVariantSeoMetadataService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_mbti_personality_variant_section_structure_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/PersonalityEnsureMbtiVariantSectionStructure.php',
            'backend/app/Services/Cms/MbtiPersonalityVariantSectionStructureService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_cross_assessment_context_guard_changes(): void
    {
        $changed = [
            'backend/app/Services/Eq/EqCrossAssessmentContextGuard.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_ci_scale_impact_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/CiScaleImpact.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_sitemap_source_cache_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/WarmSitemapSourceCacheCommand.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_commerce_tenant_repair_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/CommerceRepairPostCommitFailed.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_analytics_funnel_refresh_command_guard_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/RefreshAnalyticsFunnelDailyCommand.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_conversion_daily_read_model_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/RefreshSeoConversionDailyCommand.php',
            'backend/app/Services/Analytics/SeoConversionDailyBuilder.php',
            'backend/database/migrations/2026_06_09_000100_create_analytics_seo_conversion_daily_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_public_healthz_alias_route_addition(): void
    {
        $changed = [
            'backend/routes/web.php',
        ];
        $webRouteChangedLines = [
            '+use App\\Http\\Controllers\\HealthzController;',
            '+use App\\Http\\Middleware\\HealthzAccessControl;',
            '+Route::middleware([HealthzAccessControl::class, \'throttle:api_public\'])',
            '+    ->get(\'/healthz\', [HealthzController::class, \'show\'])',
            '+    ->withoutMiddleware([',
            '+        \\Illuminate\\Cookie\\Middleware\\EncryptCookies::class,',
            '+        \\App\\Http\\Middleware\\EncryptCookies::class,',
            '+        \\Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse::class,',
            '+        \\Illuminate\\Session\\Middleware\\StartSession::class,',
            '+        \\Illuminate\\View\\Middleware\\ShareErrorsFromSession::class,',
            '+        \\Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken::class,',
            '+        \\App\\Http\\Middleware\\VerifyCsrfToken::class,',
            '+    ])',
            '+    ->name(\'healthz.public\');',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', webRouteChangedLines: $webRouteChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_public_healthz_alias_route_removal(): void
    {
        $changed = [
            'backend/routes/web.php',
        ];
        $webRouteChangedLines = [
            '-use App\\Http\\Controllers\\HealthzController;',
            '-use App\\Http\\Middleware\\HealthzAccessControl;',
            '-Route::middleware([HealthzAccessControl::class, \'throttle:api_public\'])',
            '-    ->get(\'/healthz\', [HealthzController::class, \'show\'])',
            '-    ->withoutMiddleware([',
            '-        \\Illuminate\\Cookie\\Middleware\\EncryptCookies::class,',
            '-        \\App\\Http\\Middleware\\EncryptCookies::class,',
            '-        \\Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse::class,',
            '-        \\Illuminate\\Session\\Middleware\\StartSession::class,',
            '-        \\Illuminate\\View\\Middleware\\ShareErrorsFromSession::class,',
            '-        \\Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken::class,',
            '-        \\App\\Http\\Middleware\\VerifyCsrfToken::class,',
            '-    ])',
            '-    ->name(\'healthz.public\');',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', webRouteChangedLines: $webRouteChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_api_root_service_landing_route_addition(): void
    {
        $changed = [
            'backend/routes/web.php',
        ];
        $webRouteChangedLines = [
            '+    if ($requestHost === \'api.fermatmind.com\' || $requestHost === \'staging-api.fermatmind.com\') {',
            '+        return response()->json([',
            '+            \'ok\' => true,',
            '+            \'service\' => \'FermatMind API\',',
            '+            \'message\' => \'API root is online. Use versioned /api routes for application traffic.\',',
            '+            \'healthz\' => \'restricted\',',
            '+        ]);',
            '+    }',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', webRouteChangedLines: $webRouteChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_iq_report_foundation_changes(): void
    {
        $changed = [
            'backend/app/Services/Report/IqReportBuilder.php',
            'backend/app/Services/Report/ReportComposerRegistry.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_iq_paid_report_entitlement_contract_changes(): void
    {
        $changed = [
            'backend/app/Services/Commerce/EntitlementManager.php',
            'backend/app/Services/Report/ReportAccess.php',
            'backend/app/Services/Report/Resolvers/AccessResolver.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_clinical_combo_en_paid_parity_changes(): void
    {
        $changed = [
            'backend/app/Services/Report/ClinicalCombo/ClinicalComboBlockSelector.php',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/blocks.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/consent.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/crisis_resources.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/golden_cases.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/landing.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/layout.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/manifest.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/options_sets.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/policy.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/privacy_addendum.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/questions.compiled.json',
            'backend/content_packs/CLINICAL_COMBO_68/v1/raw/blocks/paid_blocks.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_v5_report_asset_layer_changes(): void
    {
        $changed = [
            'backend/app/Services/Content/Eq60ContentCompileService.php',
            'backend/app/Services/Content/Eq60ContentLintService.php',
            'backend/app/Services/Content/Eq60PackLoader.php',
            'backend/app/Services/Report/Eq60ReportComposer.php',
            'backend/content_packs/EQ_60/v1/compiled/golden_cases.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/landing.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/manifest.json',
            'backend/content_packs/EQ_60/v1/compiled/options.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/policy.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/questions.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/report.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/report_assets.compiled.json',
            'backend/content_packs/EQ_60/v1/raw/blocks/free_blocks.json',
            'backend/content_packs/EQ_60/v1/raw/blocks/paid_blocks.json',
            'backend/content_packs/EQ_60/v1/raw/golden_cases.csv',
            'backend/content_packs/EQ_60/v1/raw/report_layout.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/golden_cases.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/landing.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/manifest.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/options.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/policy.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/questions.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/report.compiled.json',
            'backend/content_packs/EQ_60/v1/raw/report_assets/core_formulations.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/report_assets.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/blocks/free_blocks.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/blocks/paid_blocks.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/golden_cases.csv',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_layout.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/core_formulations.json',
            'backend/database/seeders/CiScalesRegistrySeeder.php',
            'backend/database/seeders/Pr19CommerceSeeder.php',
            'backend/database/seeders/ScaleRegistrySeeder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_sjt_16_content_pack_skeleton_changes(): void
    {
        $changed = [
            'backend/content_packs/EQ_SJT_16/v1/compiled/manifest.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/domains.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/item_schema.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/module_contract.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/scoring_rubric_draft.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_sjt_16_scorer_ready_files(): void
    {
        $changed = [
            'backend/app/Services/Assessment/Scorers/EqSjt16Scorer.php',
            'backend/content_packs/EQ_SJT_16/v1/raw/golden_cases.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/items.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_integrated_report_composer_draft(): void
    {
        $changed = [
            'backend/app/Services/Report/EqIntegratedReportComposer.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_sjt_validation_telemetry_contract(): void
    {
        $changed = [
            'backend/app/Services/Eq/EqSjtValidationTelemetryContract.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_eq_journey_state_contract_changes(): void
    {
        $changed = [
            'backend/app/Models/EqJourneyState.php',
            'backend/app/Services/Eq/EqJourneyStateService.php',
            'backend/database/migrations/2026_05_31_083300_create_eq_journey_states_table.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            "+            Route::get('/attempts/{id}/eq/journey', [AttemptReadController::class, 'eqJourney'])",
            "+                ->middleware([\\App\\Http\\Middleware\\FmTokenAuth::class, 'uuid:id'])",
            "+                ->defaults('public_realm', true)",
            "+                ->name('api.v0_3.attempts.eq.journey.show');",
            "+            Route::post('/attempts/{id}/eq/journey', [AttemptReadController::class, 'submitEqJourney'])",
            "+                ->middleware([\\App\\Http\\Middleware\\FmTokenAuth::class, 'uuid:id'])",
            "+                ->defaults('public_realm', true)",
            "+                ->name('api.v0_3.attempts.eq.journey.submit');",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            routeChangedLines: $routeChangedLines
        ));
    }

    public function test_runtime_freeze_classifier_ignores_public_content_release_guard_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ReleaseVerifyPublicContent.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_storage_release_roots_audit_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/StorageReleaseRootsAudit.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_storage_path_safety_hardening_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/StorageRehydrateExactRelease.php',
            'backend/app/Services/Storage/QuarantinedRootPurgeService.php',
            'backend/app/Services/Storage/ReportArtifactsArchiveService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_mbti_private_relationship_auth_hardening(): void
    {
        $changed = [
            'backend/app/Services/V0_3/MbtiCompareInviteService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_release_revalidate_automation_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Support/ContentReleaseFollowUp.php',
            'backend/app/Services/Cms/ContentReleasePathPlanner.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_cms_media_pipeline_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/ArticleResource.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource/Pages/CreateMediaAsset.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource/Pages/EditMediaAsset.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/MediaLibraryController.php',
            'backend/app/Models/MediaAsset.php',
            'backend/app/Models/MediaVariant.php',
            'backend/app/Services/Cms/MediaAssetStorageSyncService.php',
            'backend/app/Support/PublicMediaUrlGuard.php',
            'backend/database/migrations/2026_05_16_000100_add_media_asset_sync_status.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_safe_tmp_artifact_helper(): void
    {
        $changed = [
            'backend/app/Support/SafeArtifactDirectory.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_db_migration_portability_files(): void
    {
        $changed = [
            'backend/app/Support/Database/SchemaIndex.php',
            'backend/database/migrations/2026_02_10_160100_add_idx_idempo_payload.php',
            'backend/database/migrations/2026_02_11_090100_add_idx_idempotency_keys_provider_recorded_hash.php',
            'backend/database/migrations/2026_02_27_110000_ensure_norms_table_lookup_index.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_public_recency_ordering_only(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php',
        ];
        $articleControllerChangedLines = [
            "+            ->orderByDesc('published_at')",
            "-            ->orderByDesc('published_at')",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            articleControllerChangedLines: $articleControllerChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_article_multi_test_graph_edge_files(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php',
            'backend/app/Models/ArticleTestEdge.php',
            'backend/database/migrations/2026_05_15_000300_create_article_test_edges_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_homepage_recommended_article_metadata_backfill(): void
    {
        $changed = [
            'backend/database/migrations/2026_05_27_000100_backfill_homepage_recommended_en_article_media_taxonomy.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_logical_db_foundation_migrations(): void
    {
        $changed = [
            'backend/database/migrations/2026_05_17_000100_create_seo_urls_table.php',
            'backend/database/migrations/2026_05_17_000200_create_seo_url_entities_table.php',
            'backend/database/migrations/2026_05_17_000300_create_seo_internal_traffic_rules_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_disabled_collector_skeleton_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelCollectCommand.php',
            'backend/app/Services/SeoIntel/Collectors/NoopSeoIntelCollector.php',
            'backend/app/Services/SeoIntel/SeoIntelCollector.php',
            'backend/app/Services/SeoIntel/SeoIntelCollectorManager.php',
            'backend/app/Services/SeoIntel/SeoIntelCollectorResult.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_url_truth_inventory_collector_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/Collectors/UrlTruthInventoryCollector.php',
            'backend/app/Services/SeoIntel/Sources/BackendAuthorityUrlTruthSource.php',
            'backend/app/Services/SeoIntel/Sources/UrlTruthInventorySource.php',
            'backend/app/Services/SeoIntel/UrlTruthInventoryRecord.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_two_stage_url_truth_handoff_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelUrlTruthHandoffCommand.php',
            'backend/app/Services/SeoIntel/UrlTruthHandoffArtifact.php',
            'backend/app/Services/SeoIntel/UrlTruthInventoryRecordWriter.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_drift_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/Collectors/CrawlerLogFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/DriftFoundationCollector.php',
            'backend/app/Services/SeoIntel/DriftIssueCandidate.php',
            'backend/app/Services/SeoIntel/Drift/CrawlerLogLineParser.php',
            'backend/app/Services/SeoIntel/Drift/CrawlerUserAgentClassifier.php',
            'backend/app/Services/SeoIntel/Drift/HtmlSnapshotParser.php',
            'backend/app/Services/SeoIntel/Drift/MetadataDriftComparator.php',
            'backend/app/Services/SeoIntel/Drift/SitemapLlmsParityComparator.php',
            'backend/app/Services/SeoIntel/UrlTruthDriftIssueCandidateSource.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_attribution_revenue_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/AttributionDailyBuilder.php',
            'backend/app/Services/SeoIntel/Collectors/AttributionRevenueFoundationCollector.php',
            'backend/app/Services/SeoIntel/ConsentStateNormalizer.php',
            'backend/app/Services/SeoIntel/InternalTrafficFilter.php',
            'backend/app/Services/SeoIntel/RevenueDailyBuilder.php',
            'backend/app/Services/SeoIntel/SourceEngineNormalizer.php',
            'backend/database/migrations/2026_05_17_000400_create_seo_event_funnel_daily_table.php',
            'backend/database/migrations/2026_05_17_000500_create_seo_landing_attribution_daily_table.php',
            'backend/database/migrations/2026_05_17_000600_create_seo_revenue_daily_table.php',
            'backend/database/migrations/2026_05_17_000700_create_seo_cluster_daily_table.php',
            'backend/database/migrations/2026_05_17_000800_create_seo_consent_daily_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_gsc_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/Collectors/GscCollector.php',
            'backend/app/Services/SeoIntel/GscQueryClassifier.php',
            'backend/app/Services/SeoIntel/GscSearchAnalyticsRowNormalizer.php',
            'backend/database/migrations/2026_05_17_000900_create_seo_gsc_daily_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_baidu_indexnow_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/BaiduPushPayloadValidator.php',
            'backend/app/Services/SeoIntel/Collectors/BaiduFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/IndexNowFoundationCollector.php',
            'backend/app/Services/SeoIntel/IndexNowPayloadValidator.php',
            'backend/app/Services/SeoIntel/SearchChannelSubmissionStatusNormalizer.php',
            'backend/database/migrations/2026_05_17_001000_create_seo_baidu_push_logs_table.php',
            'backend/database/migrations/2026_05_17_001100_create_seo_baidu_landing_daily_table.php',
            'backend/database/migrations/2026_05_17_001200_create_seo_indexnow_submissions_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_domestic_search_adapter_contract_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/Collectors/DomesticSearchFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/ShenmaFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/So360FoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/SogouFoundationCollector.php',
            'backend/app/Services/SeoIntel/DomesticIndexSampleNormalizer.php',
            'backend/app/Services/SeoIntel/DomesticSearchEngineAdapterContract.php',
            'backend/app/Services/SeoIntel/DomesticSearchSubmissionStatusNormalizer.php',
            'backend/app/Services/SeoIntel/DomesticSearchUrlEligibilityValidator.php',
            'backend/database/migrations/2026_05_17_001300_create_seo_search_engine_verification_statuses_table.php',
            'backend/database/migrations/2026_05_17_001400_create_seo_domestic_submission_logs_table.php',
            'backend/database/migrations/2026_05_17_001500_create_seo_domestic_index_samples_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_chinese_crawler_log_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/ChineseCrawlerUserAgentClassifier.php',
            'backend/app/Services/SeoIntel/Collectors/ChineseCrawlerLogCollector.php',
            'backend/app/Services/SeoIntel/CrawlerLogDailyAggregator.php',
            'backend/app/Services/SeoIntel/CrawlerLogLineParser.php',
            'backend/app/Services/SeoIntel/CrawlerLogPrivacySanitizer.php',
            'backend/database/migrations/2026_05_17_001600_create_seo_crawler_logs_daily_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_crawler_log_fixture_parser_mvp_file(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogFixtureParser.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_crawler_log_aggregate_dry_run_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelCrawlerLogObserveCommand.php',
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogAggregateDryRun.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_crawler_log_production_canary_runtime_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogProductionCanaryDryRun.php',
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogSingleSourceReader.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_crawler_log_aggregate_storage_gate_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogAggregateStorageWriter.php',
            'backend/database/migrations/seo_intel/2026_05_22_111800_create_seo_crawler_log_daily_aggregates_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_issue_queue_foundation_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/Collectors/IssueQueueFoundationCollector.php',
            'backend/app/Services/SeoIntel/SeoIssueQueueContract.php',
            'backend/app/Services/SeoIntel/SeoIssueQueueProducer.php',
            'backend/app/Services/SeoIntel/SeoIssueSanitizer.php',
            'backend/app/Services/SeoIntel/SeoIssueSummaryService.php',
            'backend/database/migrations/2026_05_17_001700_create_seo_issue_queue_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_dash_api_read_only_contract_files(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Ops/SeoIntel/SeoIntelDashboardController.php',
            'backend/app/Http/Middleware/EnsureSeoIntelReadAuthorized.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoDashboardApiReadService.php',
            'backend/app/Support/Rbac/PermissionNames.php',
            'backend/routes/api.php',
        ];

        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Ops\\SeoIntel\\SeoIntelDashboardController;',
            '+use App\\Http\\Middleware\\EnsureSeoIntelReadAuthorized;',
            '+',
            "+    Route::prefix('ops/seo-intel')",
            '+        ->middleware([',
            '+            ...$cmsAdminMiddleware,',
            '+            EnsureSeoIntelReadAuthorized::class,',
            '+        ])',
            '+        ->group(function () {',
            "+            Route::get('/overview', [SeoIntelDashboardController::class, 'overview'])",
            "+                ->name('api.v0_5.ops.seo_intel.overview');",
            "+            Route::get('/url-truth', [SeoIntelDashboardController::class, 'urlTruth'])",
            "+                ->name('api.v0_5.ops.seo_intel.url_truth');",
            "+            Route::get('/issues', [SeoIntelDashboardController::class, 'issues'])",
            "+                ->name('api.v0_5.ops.seo_intel.issues');",
            "+            Route::get('/trends', [SeoIntelDashboardController::class, 'trends'])",
            "+                ->name('api.v0_5.ops.seo_intel.trends');",
            "+            Route::get('/page-performance', [SeoIntelDashboardController::class, 'pagePerformance'])",
            "+                ->name('api.v0_5.ops.seo_intel.page_performance');",
            '+        });',
            '+',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            routeChangedLines: $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_search_channel_queue_runtime_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelSearchChannelQueueCommand.php',
            'backend/app/Console/Commands/SeoIntelSearchChannelSubmitCommand.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueAuditLogger.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueChannelMapper.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueEligibilityEvaluator.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueEligibilityResult.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueIdempotency.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueLiveSubmissionExecutor.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueuePlanner.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueWriteService.php',
            'backend/database/migrations/seo_intel/2026_05_20_220000_create_seo_search_channel_queue_tables.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_mbti_url_truth_cleanup_runtime_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelMbtiUrlTruthCleanupCommand.php',
            'backend/app/Services/SeoIntel/MbtiUrlTruthCleanupService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_publish_rehearsal_dry_run_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelContentPublishRehearsalCommand.php',
            'backend/app/Services/SeoIntel/ContentOps/ContentPublishRehearsalDryRun.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_internal_link_graph_dry_run_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelInternalLinkGraphCommand.php',
            'backend/app/Services/SeoIntel/InternalLink/InternalLinkGraphDryRun.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_translation_parity_read_model_files(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/TranslationParity/TranslationParityMatrixReadModel.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_pages_local_baseline_import_package_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ContentPagesImportLocalBaseline.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_science_content_page_no_write_gate_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/ScienceContentPageDraftDryRunCommand.php',
            'backend/app/Console/Commands/ScienceContentPageOperatorReviewReadinessCommand.php',
            'backend/app/Console/Commands/ScienceContentPagePreImportQaCommand.php',
            'backend/app/Services/Cms/ScienceContentPageDraftDryRunService.php',
            'backend/app/Services/Cms/ScienceContentPageOperatorReviewReadinessService.php',
            'backend/app/Services/Cms/ScienceContentPagePreImportQaService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_pages_controlled_publish_runtime_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/ContentPagesPublishControlledCommand.php',
            'backend/app/Services/ContentPages/ContentPagesControlledPublishService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_page_help_service_field_contract_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/ContentPageResource.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php',
            'backend/app/Models/ContentPage.php',
            'backend/app/Services/Cms/ContentPageTranslationAdapter.php',
            'backend/database/migrations/2026_06_05_150000_add_help_service_fields_to_content_pages.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_science_content_page_publish_safety_field_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/ContentPageResource.php',
            'backend/app/Filament/Ops/Resources/ContentPageResource/Pages/EditContentPage.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php',
            'backend/app/Models/ContentPage.php',
            'backend/app/Services/Cms/ContentPagePublishGate.php',
            'backend/app/Services/Cms/ContentPageTranslationAdapter.php',
            'backend/database/migrations/2026_06_08_010000_add_publish_safety_fields_to_content_pages.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_daily_giving_ledger_mvp_files(): void
    {
        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Foundation\\DailyGivingRecordController;',
            '+    Route::get(\'/foundation/giving-records/months\', [DailyGivingRecordController::class, \'months\']);',
            '+    Route::get(\'/foundation/giving-records/months/{yearMonth}\', [DailyGivingRecordController::class, \'monthRecords\']);',
            '+    Route::get(\'/foundation/giving-records\', [DailyGivingRecordController::class, \'index\']);',
            '+    Route::get(\'/foundation/giving-records/{recordCode}\', [DailyGivingRecordController::class, \'show\']);',
        ];

        $changed = [
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/CreateDailyGivingRecord.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/EditDailyGivingRecord.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/ListDailyGivingRecords.php',
            'backend/app/Http/Controllers/API/V0_5/Foundation/DailyGivingRecordController.php',
            'backend/app/Http/Resources/Foundation/DailyGivingRecordResource.php',
            'backend/app/Models/DailyGivingRecord.php',
            'backend/database/factories/DailyGivingRecordFactory.php',
            'backend/database/migrations/2026_05_30_000100_create_daily_giving_records_table.php',
            'backend/routes/api.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_chinese_claim_linter_runtime_files(): void
    {
        $changed = [
            'backend/app/Console/Commands/SeoIntelClaimLintCommand.php',
            'backend/app/Services/SeoIntel/ClaimLint/ChineseClaimLinter.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_growth_attribution_ops_ui_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Pages/ContentGrowthAttributionPage.php',
            'backend/app/Services/Ops/ContentGrowthAttributionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_runtime_ops_read_model_file(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/OpsDashboard/CareerRuntimeReadModelService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_conversion_ops_read_model_file(): void
    {
        $changed = [
            'backend/app/Services/SeoIntel/OpsDashboard/SeoConversionFunnelReadService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_overview_ops_ui_file(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Pages/ContentOverviewPage.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_personality_ops_ui_files(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/PersonalityProfileResource.php',
            'backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_intel_migration_isolation_files(): void
    {
        $changed = [
            'backend/database/migrations/seo_intel/2026_05_17_000100_create_seo_urls_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000200_create_seo_url_entities_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001700_create_seo_issue_queue_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_public_distribution_owner_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
            'backend/app/Services/Career/PublicCareerAuthorityResponseCache.php',
            'backend/app/Services/SEO/SitemapGenerator.php',
            'backend/app/Services/SEO/SitemapCache.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_runtime_publish_projection_owner_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Expansion/CanonicalBatchPromotionService.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionRollbackGate.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionRollbackResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionTransaction.php',
            'backend/app/Domain/Career/Publish/CareerFullReleaseLedgerProjectionService.php',
            'backend/app/Domain/Career/Publish/FirstWaveBlockedGovernancePolicy.php',
            'backend/app/Domain/Career/Publish/CareerRolloutReportAuthoritySigner.php',
            'backend/app/Domain/Career/Publish/CareerVerifiedRolloutBatchSlugAuthority.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionDTO.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionExporter.php',
            'backend/app/Domain/Career/Publish/CareerPublicTrustTaxonomyExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthValidator.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionValidator.php',
            'backend/app/Services/Career/CareerFirstWaveReleaseArtifactMaterializationService.php',
            'backend/app/Services/Career/CareerFirstWaveRolloutBundleArtifactMaterializationService.php',
            'backend/app/Services/Career/CareerFirstWaveRolloutWavePlanArtifactMaterializationService.php',
            'backend/app/Domain/Career/Expansion/CanonicalBatchCloseoutResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPostPromotionReleaseGateService.php',
            'backend/app/Domain/Career/Expansion/CanonicalPostPromotionReleaseGateValidator.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_canonical_eligibility_audit_schema_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContext.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextApprovalGate.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextRequirement.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextStatus.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityCheckProtocol.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityLayer.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityLayerStatus.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityReport.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityScope.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilitySeverity.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilitySidecar.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityStatus.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_public_resolution_plan_resolver_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlan.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanIssue.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanResolver.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanRow.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanValidationResult.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_occupation_entity_inventory_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryAuditor.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryIssue.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryResult.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryRow.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityRemediationPlan.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityRemediationPlanRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_baseline_metadata_inventory_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryAuditor.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryIssue.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryResult.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_index_state_authority_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityAuditor.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityIssue.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityResult.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityRow.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateRemediationPlan.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateRemediationPlanRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_entity_index_context_artifact_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactRow.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_runtime_projection_truth_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityAuditor.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityIssue.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_seo_geo_readiness_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessAuditor.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_surface_readiness_audit_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessAuditor.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_surface_context_artifact_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_80_cohort_readiness_plan_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/Career80TargetDeltaPlanner.php',
            'backend/app/Domain/Career/Audit/Career80TargetDeltaResult.php',
            'backend/app/Domain/Career/Audit/CareerFullVisiblePublicationGate.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortDeltaPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortDeltaPlanResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidateAwareArtifactRefresh.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeArtifactRefreshPlanner.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeArtifactRefreshResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidatePrepPlanner.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidatePrepResult.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutManifestPlanner.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutManifestResult.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutGatePlanner.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutGateResult.php',
            'backend/app/Domain/Career/Audit/Career80TotalLiveAcceptancePlanner.php',
            'backend/app/Domain/Career/Audit/Career80TotalLiveAcceptanceResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortCloseoutPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortCloseoutResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveLiveVerificationScalingPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveLiveVerificationScalingResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessCandidate.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelection.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelectionIssue.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelectionResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelector.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessPlanner.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelectionReport.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelectionRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelector.php',
            'backend/app/Domain/Career/Audit/Career80RolloutCandidateGate.php',
            'backend/app/Domain/Career/Audit/Career2786PublicResolutionPartitionPlanner.php',
            'backend/app/Domain/Career/Audit/Career2786PublicResolutionPartitionResult.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_2786_readiness_policy_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyClassifier.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyIssue.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyResult.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyRow.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_expansion_manifest_train_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainBatch.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainGenerator.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainResult.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_batch_live_acceptance_v2_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Auditor.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Issue.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Result.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Row.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_2786_full_audit_artifact_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/Career2786FullAuditArtifact.php',
            'backend/app/Domain/Career/Audit/Career2786FullAuditArtifactBuilder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_commerce_payment_action_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/CommerceController.php',
            'backend/app/Services/Commerce/Checkout/AlipayCheckoutService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_freemium_locale_policy_files(): void
    {
        $changed = [
            'backend/app/Services/Commerce/FreemiumLocalePolicy.php',
            'backend/app/Services/Report/Resolvers/OfferResolver.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_payment_webhook_digest_idempotency_changes(): void
    {
        $changed = [
            'backend/app/Jobs/Commerce/ReprocessPaymentEventJob.php',
            'backend/app/Services/Commerce/Webhook/WebhookEntitlementService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_destructive_migration_retirement_evidence(): void
    {
        $changed = [
            'backend/database/migrations/2026_03_26_120000_drop_attempt_quality_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_order_tenant_ownership_boundary_changes(): void
    {
        $changed = [
            'backend/app/Filament/Tenant/Resources/OrderResource.php',
            'backend/app/Http/Controllers/API/V0_3/CommerceController.php',
            'backend/app/Internal/Commerce/PaymentWebhookHandlerCore.php',
            'backend/app/Services/Commerce/OrderManager.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_cms_lifecycle_tenant_scope_changes(): void
    {
        $changed = [
            'backend/app/Models/Article.php',
            'backend/app/Models/PersonalityProfileSection.php',
            'backend/app/Models/PersonalityProfileSeoMeta.php',
            'backend/app/Models/PersonalityProfileVariant.php',
            'backend/app/Models/PersonalityProfileVariantSection.php',
            'backend/app/Models/PersonalityProfileVariantSeoMeta.php',
            'backend/app/Services/Cms/ArticlePublishService.php',
            'backend/app/Services/Cms/ArticleService.php',
            'backend/database/migrations/2026_05_06_010000_add_org_scope_to_personality_profile_children.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_research_backend_mvp_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Cms/ResearchReportController.php',
            'backend/app/Models/ResearchReport.php',
            'backend/database/migrations/2026_05_19_000100_create_research_reports_table.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            '+use App\Http\Controllers\API\V0_5\Cms\ResearchReportController;',
            '+    Route::get(\'/research\', [ResearchReportController::class, \'index\']);',
            '+    Route::get(\'/research/{slug}\', [ResearchReportController::class, \'show\']);',
            '+        Route::get(\'/internal/research-reports\', [ResearchReportController::class, \'internalIndex\']);',
            '+        Route::get(\'/internal/research-reports/{slug}\', [ResearchReportController::class, \'internalShow\']);',
            '+        Route::put(\'/internal/research-reports/{slug}\', [ResearchReportController::class, \'internalUpdate\']);',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', routeChangedLines: $routeChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_research_cms_trusted_org_hardening(): void
    {
        $changed = [
            'backend/app/Http/Middleware/ResolveOrgContext.php',
        ];

        $changedLines = [
            '+        $candidates = $this->isOpsPanelRequest($request) || $this->isInternalCmsApiRequest($request)',
            '-        $candidates = $this->isOpsPanelRequest($request)',
            '-                $request->header(\'X-FM-Org-Id\'),',
            '-                $request->header(\'X-Org-Id\'),',
            '-                $request->query(\'org_id\'),',
            '-                $request->route(\'org_id\'),',
            '+    private function isInternalCmsApiRequest(Request $request): bool',
            '+    {',
            '+        return str_starts_with(\'/\'.ltrim($request->path(), \'/\'), \'/api/v0.5/internal/\');',
            '+    }',
            '+',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            resolveOrgContextChangedLines: $changedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_article_publishing_runtime_truth_gate_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ArticleImportLocalBaseline.php',
            'backend/app/Services/Career/StructuredData/CareerArticleStructuredDataBuilder.php',
            'backend/app/Services/Cms/ArticleSeoService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_baseline_projection_convergence_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ArticleImportLocalBaseline.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/LandingSurfaceController.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_editorial_package_draft_gate_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ArticleImportEditorialPackage.php',
            'backend/app/Console/Kernel.php',
            'backend/app/Services/Cms/EditorialPackage/EditorialPackageDraftImporter.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\ArticleImportEditorialPackage;',
            '+        ArticleImportEditorialPackage::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_article_body_h1_guard_changes(): void
    {
        $changed = [
            'backend/app/Services/Cms/ArticleBodyHeadingGuard.php',
            'backend/app/Services/Cms/ArticleTranslationRevisionWorkspace.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_publishing_ops_dashboard_changes(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Pages/ArticlePublishingOpsPage.php',
            'backend/app/Models/ArticleEditorialPackageImport.php',
            'backend/database/migrations/2026_05_14_000100_create_article_editorial_package_imports_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_editorial_review_approval_changes(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Pages/EditorialReviewPage.php',
            'backend/app/Services/Cms/ArticleTranslationWorkflowService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_article_draft_preview_ops_route_changes(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Resources/ArticleResource/Support/ArticleWorkspace.php',
            'backend/app/Http/Controllers/Ops/ArticleDraftPreviewController.php',
            'backend/routes/web.php',
        ];
        $webRouteChangedLines = [
            '+use App\Http\Controllers\Ops\ArticleDraftPreviewController;',
            '+use App\Http\Middleware\AdminAuth;',
            '+use App\Http\Middleware\EnsureAdminTotpVerified;',
            '+use App\Http\Middleware\EnsureCmsAdminAuthorized;',
            '+use App\Http\Middleware\OpsAccessControl;',
            '+use App\Http\Middleware\RequireOpsOrgSelected;',
            '+use App\Http\Middleware\ResolveOrgContext;',
            '+use App\Http\Middleware\SetOpsRequestContext;',
            '+    Route::get(\'/ops/article-preview/{article}\', ArticleDraftPreviewController::class)',
            '+        ->middleware([',
            '+            SetOpsRequestContext::class,',
            '+            AdminAuth::class,',
            '+            ResolveOrgContext::class,',
            '+            EnsureAdminTotpVerified::class,',
            '+            RequireOpsOrgSelected::class,',
            '+            OpsAccessControl::class,',
            '+            EnsureCmsAdminAuthorized::class.\':read\',',
            '+        ])',
            '+        ->whereNumber(\'article\')',
            '+        ->name(\'ops.articles.preview\');',
            '+',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            webRouteChangedLines: $webRouteChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_controlled_article_publish_sop_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ArticlePublishControlled.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\ArticlePublishControlled;',
            '+        ArticlePublishControlled::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_article_cover_propagation_smoke_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ArticleCoverPropagationSmoke.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\ArticleCoverPropagationSmoke;',
            '+        ArticleCoverPropagationSmoke::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_alipay_pending_compensation_scheduler_changes(): void
    {
        $changed = [
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            "+        \$schedule->command('commerce:compensate-pending-orders --provider=alipay --include-created --only-stale --limit=10 --older-than-minutes=60')->everyTenMinutes()->withoutOverlapping();",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_privacy_logs_dsar_key_rotation_changes(): void
    {
        $changed = [
            'backend/app/Contracts/Security/PiiEnvelopeAdapter.php',
            'backend/app/Http/Controllers/API/V0_3/ComplianceDsarController.php',
            'backend/app/Jobs/Ops/BackfillPiiEncryptionJob.php',
            'backend/app/Support/PiiCipher.php',
            'backend/app/Support/Security/ExternalKmsPiiEnvelopeAdapter.php',
            'backend/app/Support/Security/LocalPiiEnvelopeAdapter.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_attempt_email_binding_foundation_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/AttemptEmailBindingController.php',
            'backend/app/Http/Requests/V0_3/AttemptEmailBindingRequest.php',
            'backend/app/Models/AttemptEmailBinding.php',
            'backend/app/Services/Attempts/AttemptEmailBindingService.php',
            'backend/database/migrations/2026_05_05_000100_create_attempt_email_bindings_table.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_3\\AttemptEmailBindingController;',
            '+            Route::post(\'/attempts/{id}/email-bind\', [AttemptEmailBindingController::class, \'store\'])',
            '+                ->middleware([\\App\\Http\\Middleware\\FmTokenAuth::class, \'uuid:id\'])',
            '+                ->defaults(\'public_realm\', true)',
            '+                ->name(\'api.v0_3.attempts.email_bind\');',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_attempt_data_lifecycle_erasure_changes(): void
    {
        $changed = [
            'backend/app/Services/Attempts/AttemptDataLifecycleService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_result_email_lookup_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/ResultEmailLookupController.php',
            'backend/app/Http/Requests/V0_3/ResultEmailLookupRequest.php',
            'backend/app/Providers/AppServiceProvider.php',
            'backend/app/Services/Results/ResultAccessTokenService.php',
            'backend/app/Services/Results/ResultEmailLookupService.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_3\\ResultEmailLookupController;',
            '+        Route::post(\'/results/lookup-by-email\', [ResultEmailLookupController::class, \'store\'])',
            '+            ->middleware(\'throttle:api_result_lookup\')',
            '+            ->defaults(\'public_realm\', true)',
            '+            ->name(\'api.v0_3.results.lookup_by_email\');',
            '+',
        ];
        $appServiceProviderChangedLines = [
            '+        RateLimiter::for(\'api_result_lookup\', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {',
            '+            if ($shouldBypassRateLimits()) {',
            '+                return Limit::none();',
            '+            }',
            '+            $limit = (int) config(\'fap.rate_limits.api_result_lookup_per_minute\', 20);',
            '+            $limit = max(1, $limit);',
            '+            return Limit::perMinute($limit)',
            '+                ->by($scopedRateKey($request, \'api_result_lookup\'))',
            '+                ->response($response(\'RATE_LIMIT_RESULT_LOOKUP\', \'Too many result lookup requests. Please retry later.\'));',
            '+        });',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            $routeChangedLines,
            $appServiceProviderChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_result_email_access_link_delivery_changes(): void
    {
        $changed = [
            'backend/app/Services/Email/EmailPreferenceService.php',
            'backend/app/Services/Email/EmailOutboxService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_seo_funnel_attribution_ingest_contract_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/MbtiAttributionEventController.php',
            'backend/routes/api.php',
        ];
        $allowedChangedLines = [
            "+        'submit_attempt',",
            "+        'landing_pv',",
            "+        'article_to_test_click',",
            "+        'start_test',",
            "+        'complete_test',",
            "+        'view_result',",
            "+        'url',",
            "+        'lang',",
            "+        'page_type',",
            "+        'source_url',",
            "+        'source_article',",
            "+        'target_test',",
            "+        'scale_id',",
            "+        'form_id',",
            "+            'payload.durationMs' => ['nullable', 'integer', 'min:0', 'max:86400000'],",
            "+            'payload.url' => ['nullable', 'string', 'max:512', 'regex:/\\A[^\\r\\n]*\\z/'],",
            '+        $payloadInput = $request->input(\'payload\');',
            '+        $isSeoConversionEvent = $this->isSeoConversionEvent($eventName, $payload);',
            '+        if ($isSeoConversionEvent) {',
            "+            'seo_conversion' => [",
            "+            'payload' => ['nullable', 'array'],",
            "-            'payload' => ['nullable', 'array:'.implode(',', self::PAYLOAD_KEYS)],",
            '+        $scaleCode = $this->normalizeOptionalString(',
            "+            'scale_code' => \$scaleCode,",
            '+    private function sanitizeSeoPublicUrl(mixed $value, string $field): ?string',
        ];
        $routeChangedLines = [
            "+    Route::post('/seo/attribution/events', [MbtiAttributionEventController::class, 'store'])",
            '+        ->middleware([',
            '+            \\App\\Http\\Middleware\\LimitApiPublicPayloadSize::class,',
            "+            'throttle:api_track',",
            '+        ])',
            "+        ->name('api.v0_5.seo.attribution_events.store');",
        ];
        $blockedChangedLines = [
            '+        abort(403);',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            routeChangedLines: $routeChangedLines,
            attributionControllerChangedLines: $allowedChangedLines,
        ));
        $this->assertSame(['backend/app/Http/Controllers/API/V0_3/MbtiAttributionEventController.php'], $this->mbtiImpactingRuntimeChanges(
            ['backend/app/Http/Controllers/API/V0_3/MbtiAttributionEventController.php'],
            '',
            '',
            attributionControllerChangedLines: $blockedChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_iq_identity_metadata_seed_changes(): void
    {
        $changed = [
            'backend/database/seeders/ScaleRegistrySeeder.php',
        ];
        $scaleRegistrySeederChangedLines = [
            "-            'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',",
            "+            'default_dir_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',",
            '-                questions: 60,',
            '+                questions: 30,',
            '-                minutes: 12,',
            '+                minutes: 20,',
            "-        \$this->command?->info('ScaleRegistrySeeder: IQ_RAVEN scale upserted.');",
            "+        \$this->command?->info('ScaleRegistrySeeder: IQ public slug scale upserted with IQ_RAVEN legacy identity and IQ_INTELLIGENCE_QUOTIENT canonical pack metadata.');",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            null,
            null,
            $scaleRegistrySeederChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_iq_scoring_contract_driver_changes(): void
    {
        $changed = [
            'backend/app/Services/Assessment/Drivers/IqTestDriver.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_iq_norm_authority_foundation_changes(): void
    {
        $changed = [
            'backend/app/Services/Iq/IqNormAuthorityContract.php',
            'backend/database/migrations/2026_05_31_090000_create_iq_norm_authorities_table.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_iq_norm_import_dry_run_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/NormsIqImport.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\NormsIqImport;',
            '+        NormsIqImport::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_iq_production_observability_guard_changes(): void
    {
        $changed = [
            'backend/app/Services/Iq/IqProductionObservability.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_iq_result_secrecy_redaction_only(): void
    {
        $changed = [
            'backend/app/Services/Attempts/AttemptSubmitService.php',
            'backend/app/Services/Iq/IqResultPayloadRedactor.php',
        ];
        $allowedSubmitChangedLines = [
            '+use App\\Services\\Iq\\IqResultPayloadRedactor;',
            '+        if (IqResultPayloadRedactor::isIqScale($responseCodes[\'scale_code_legacy\'], $responseCodes[\'scale_code_v2\'])) {',
            '+            $payload = IqResultPayloadRedactor::redactAnswerKeys($payload);',
            '+            $compatScores = IqResultPayloadRedactor::redactAnswerKeys($compatScores);',
            '+        }',
            '+',
        ];
        $blockedSubmitChangedLines = [
            '+        $payload[\'big5_result_page_v2\'] = [];',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            attemptSubmitServiceChangedLines: $allowedSubmitChangedLines,
        ));
        $this->assertSame(['backend/app/Services/Attempts/AttemptSubmitService.php'], $this->mbtiImpactingRuntimeChanges(
            ['backend/app/Services/Attempts/AttemptSubmitService.php'],
            '',
            '',
            attemptSubmitServiceChangedLines: $blockedSubmitChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_attempt_submission_reliability_hardening(): void
    {
        $changed = [
            'backend/app/Services/Attempts/AttemptSubmissionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_attempt_submission_queue_jitter(): void
    {
        $changed = [
            'backend/app/Jobs/ProcessAttemptSubmissionJob.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_measurement_contract_compare_policy_changes(): void
    {
        $changed = [
            'backend/app/Services/Assessment/Drivers/RiasecDriver.php',
            'backend/app/Services/Attempts/AttemptStartService.php',
            'backend/app/Services/Riasec/RiasecCompareGuardService.php',
            'backend/app/Services/Riasec/RiasecFormCatalog.php',
            'backend/app/Services/Riasec/RiasecMeasurementContract.php',
            'backend/app/Services/Riasec/RiasecPublicFormSummaryBuilder.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
            'backend/app/Services/V0_3/Me/MeAttemptsService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_interpretation_rule_contract_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecInterpretationRuleContract.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_quality_rule_contract_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecQualityRuleContract.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_module_selector_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecReportModuleSelector.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_content_registry_contract_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecContentRegistrySlotContract.php',
            'backend/app/Services/Riasec/RiasecDeepCopySlotRegistry.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_projection_v2_minimal_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/AttemptReadController.php',
            'backend/app/Services/Assessment/Drivers/RiasecDriver.php',
            'backend/app/Services/Report/RiasecReportComposer.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_snapshot_bound_report_changes(): void
    {
        $changed = [
            'backend/app/Services/Report/ReportGatekeeper.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
            'backend/app/Services/Report/RiasecReportComposer.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_snapshot_surface_changes(): void
    {
        $changed = [
            'backend/app/Services/Report/Pdf/ReportPdfDocumentService.php',
            'backend/app/Services/V0_3/Me/MeAttemptsService.php',
            'backend/app/Services/V0_3/ShareService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_technical_note_method_boundary_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/ScalesController.php',
            'backend/app/Services/Riasec/RiasecTechnicalNoteService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_activity_explorer_examples_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecActivityExplorerService.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_exploration_feedback_overlay_changes(): void
    {
        $changed = [
            'backend/app/Services/Riasec/RiasecExplorationFeedbackOverlayService.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_riasec_question_pack_translation_changes(): void
    {
        $changed = [
            'backend/content_packs/RIASEC/v1-standard-60/compiled/questions.compiled.json',
            'backend/content_packs/RIASEC/v1-standard-60/compiled/manifest.json',
            'backend/content_packs/RIASEC/v1-enhanced-140/compiled/questions.compiled.json',
            'backend/content_packs/RIASEC/v1-enhanced-140/compiled/manifest.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_enneagram_forced_choice_question_pack_translation_changes(): void
    {
        $changed = [
            'backend/content_packs/ENNEAGRAM/v1-forced-choice-144/compiled/questions.compiled.json',
            'backend/content_packs/ENNEAGRAM/v1-forced-choice-144/compiled/manifest.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_import_service_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_file_import_idempotency_hardening_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Import/CareerAuthorityDatasetReader.php',
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
            'backend/app/Services/Ingestion/ReplayService.php',
            'backend/app/Services/Storage/QuarantinedRootRestoreService.php',
            'backend/app/Support/Xlsx/XlsxCellReference.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_ci_auth_bypass_hardening_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_3/AuthWxPhoneController.php',
            'backend/app/Services/Auth/PhoneOtpService.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            "-        if (app()->environment(['local', 'testing', 'ci'])) {",
            "+        if (app()->environment(['local', 'testing'])) {",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            null,
            $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_surface_builder_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Cms/CareerJobController.php',
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
            'backend/app/Http/Resources/Career/CareerJobDetailResource.php',
            'backend/app/Services/Career/Bundles/CareerRuntimePublishedDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerLocaleIntegrityGate.php',
            'backend/app/Services/Cms/CareerJobSeoService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_cn_proxy_public_owner_surface_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Career/CareerCnProxyPublicOwnerController.php',
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
            'backend/app/Services/Career/Bundles/CareerCnProxyPublicOwnerSurfaceBuilder.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Career\\CareerCnProxyPublicOwnerController;',
            "+    Route::get('/career/cn-proxy/{slug}', [CareerCnProxyPublicOwnerController::class, 'show']);",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            routeChangedLines: $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_career_directory_authority_api_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Career/CareerDirectoryController.php',
            'backend/app/Services/Career/CareerDirectoryAuthorityService.php',
            'backend/routes/api.php',
        ];
        $routeChangedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Career\\CareerDirectoryController;',
            "+    Route::get('/career/directory', [CareerDirectoryController::class, 'index']);",
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            routeChangedLines: $routeChangedLines,
        ));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_asset_backed_bundle_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerRecommendationDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_policy_claim_guard_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Scoring/ClaimPermissionsCompiler.php',
            'backend/app/Domain/Career/Scoring/ClaimReasonCode.php',
            'backend/app/Domain/Career/Scoring/WarningMatrix.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_runtime_projection_consumer_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobListController.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveDiscoverabilityManifestService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveLaunchTierSummaryService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveLifecycleSummaryService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveNextStepLinksService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionLookup.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionVisibility.php',
            'backend/app/Domain/Career/Publish/FirstWavePublishReadyValidator.php',
            'backend/app/Domain/Career/Publish/FirstWaveReadinessSummaryService.php',
            'backend/app/Providers/AppServiceProvider.php',
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobListBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerSearchBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
            'backend/app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_packs_index_streaming_scan_changes(): void
    {
        $changed = [
            'backend/app/Services/Content/ContentPacksIndex.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges(
            $changed,
            '',
            '',
            contentPacksIndexChangedLines: [
                '+use RecursiveDirectoryIterator;',
                '+use RecursiveIteratorIterator;',
                '+use SplFileInfo;',
                '-        $items = $this->scanItems($packsRootFs);',
                '+        $scanned = $this->scanIndex($packsRootFs, $defaults);',
                '+        $items = (array) ($scanned[\'items\'] ?? []);',
                '-        $byPackId = $this->buildByPackId($items, $defaults);',
                '-            \'by_pack_id\' => $byPackId,',
                '+            \'by_pack_id\' => (array) ($scanned[\'by_pack_id\'] ?? []),',
                '-    private function scanItems(string $packsRootFs): array',
                '+    private function scanIndex(string $packsRootFs, array $defaults): array',
                '+        $byPackId = [];',
                '+        $latest = [];',
                '-        foreach (File::allFiles($packsRootFs) as $file) {',
                '-            if ($file->getFilename() !== \'manifest.json\') {',
                '-                continue;',
                '-            }',
                '-',
                '+        foreach ($this->manifestFilesUnder($packsRootFs) as $file) {',
                '-            if (! is_array($this->readJsonFile($questionsPath))) {',
                '+            if (! $this->isValidJsonArrayDocument($questionsPath)) {',
                '+            $this->recordByPackIdVersion(',
                '+                $byPackId,',
                '+                $latest,',
                '+                $packId,',
                '+                $dirVersion,',
                '+                $updatedAt',
                '+            );',
                '-        return $items;',
                '+        return [',
                '+            \'items\' => $items,',
                '+            \'by_pack_id\' => $this->finalizeByPackId($byPackId, $latest, $defaults),',
                '+        ];',
                '-    private function buildByPackId(array $items, array $defaults): array',
                '-    {',
                '-        $byPackId = [];',
                '-        $latest = [];',
                '-        $defaultPackId = (string) ($defaults[\'default_pack_id\'] ?? \'\');',
                '-        $defaultDirVersion = (string) ($defaults[\'default_dir_version\'] ?? \'\');',
                '-',
                '-        foreach ($items as $item) {',
                '-            $packId = (string) ($item[\'pack_id\'] ?? \'\');',
                '-            $dirVersion = (string) ($item[\'dir_version\'] ?? \'\');',
                '-            if ($packId === \'\' || $dirVersion === \'\') {',
                '-                continue;',
                '-            }',
                '+    private function recordByPackIdVersion(',
                '+        array &$byPackId,',
                '+        array &$latest,',
                '+        string $packId,',
                '+        string $dirVersion,',
                '+        int $updatedAt',
                '+    ): void {',
                '+        if ($packId === \'\' || $dirVersion === \'\') {',
                '+            return;',
                '+        }',
                '-            if (! isset($byPackId[$packId])) {',
                '-                $byPackId[$packId] = [',
                '-                    \'default_dir_version\' => \'\',',
                '-                    \'versions\' => [],',
                '-                ];',
                '-            }',
                '+        if (! isset($byPackId[$packId])) {',
                '+            $byPackId[$packId] = [',
                '+                \'default_dir_version\' => \'\',',
                '+                \'versions\' => [],',
                '+            ];',
                '+        }',
                '-            $byPackId[$packId][\'versions\'][] = $dirVersion;',
                '+        $byPackId[$packId][\'versions\'][$dirVersion] = true;',
                '-            $updatedAt = (int) ($item[\'updated_at\'] ?? 0);',
                '-            if (! isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId][\'updated_at\'] ?? 0)) {',
                '-                $latest[$packId] = [',
                '-                    \'dir_version\' => $dirVersion,',
                '-                    \'updated_at\' => $updatedAt,',
                '-                ];',
                '-            }',
                '+        if (! isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId][\'updated_at\'] ?? 0)) {',
                '+            $latest[$packId] = [',
                '+                \'dir_version\' => $dirVersion,',
                '+                \'updated_at\' => $updatedAt,',
                '+            ];',
                '+        }',
                '+    }',
                '+',
                '+    private function finalizeByPackId(array $byPackId, array $latest, array $defaults): array',
                '+    {',
                '+        $defaultPackId = (string) ($defaults[\'default_pack_id\'] ?? \'\');',
                '+        $defaultDirVersion = (string) ($defaults[\'default_dir_version\'] ?? \'\');',
                '-            $versions = array_values(array_unique($info[\'versions\'] ?? []));',
                '+            $versions = array_keys((array) ($info[\'versions\'] ?? []));',
                '+    /**',
                '+     * @return \\Generator<int, SplFileInfo>',
                '+     */',
                '+    private function manifestFilesUnder(string $packsRootFs): \\Generator',
                '+    {',
                '+        $iterator = new RecursiveIteratorIterator(',
                '+            new RecursiveDirectoryIterator($packsRootFs, RecursiveDirectoryIterator::SKIP_DOTS),',
                '+            RecursiveIteratorIterator::LEAVES_ONLY',
                '+        );',
                '+',
                '+        foreach ($iterator as $file) {',
                '+            if (! $file instanceof SplFileInfo || ! $file->isFile()) {',
                '+                continue;',
                '+            }',
                '+',
                '+            if ($file->getFilename() !== \'manifest.json\') {',
                '+                continue;',
                '+            }',
                '+',
                '+            yield $file;',
                '+        }',
                '+    }',
                '+',
                '+    private function isValidJsonArrayDocument(string $path): bool',
                '+    {',
                '+        if ($path === \'\' || ! File::isFile($path)) {',
                '+            return false;',
                '+        }',
                '+',
                '+        $firstMeaningfulByte = $this->firstNonWhitespaceByte($path);',
                '+        if ($firstMeaningfulByte === null) {',
                '+            return false;',
                '+        }',
                '+',
                '+        return $firstMeaningfulByte === \'[\' || $firstMeaningfulByte === \'{\';',
                '+    }',
                '+',
                '+    private function firstNonWhitespaceByte(string $path): ?string',
                '+    {',
                '+        $handle = @fopen($path, \'rb\');',
                '+        if (! is_resource($handle)) {',
                '+            return null;',
                '+        }',
                '+',
                '+        try {',
                '+            while (! feof($handle)) {',
                '+                $chunk = fread($handle, 8192);',
                '+                if (! is_string($chunk) || $chunk === \'\') {',
                '+                    continue;',
                '+                }',
                '+',
                '+                $length = strlen($chunk);',
                '+                for ($index = 0; $index < $length; $index++) {',
                '+                    $char = $chunk[$index];',
                '+                    if (! ctype_space($char)) {',
                '+                        return $char;',
                '+                    }',
                '+                }',
                '+            }',
                '+        } finally {',
                '+            fclose($handle);',
                '+        }',
                '+',
                '+        return null;',
                '+    }',
            ],
        ));
    }

    public function test_runtime_freeze_classifier_ignores_content_packs_index_artifact_phase2_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ContentPacksIndexBuild.php',
            'backend/app/Services/Content/ContentPacksIndex.php',
            'backend/app/Services/Content/ContentPacksIndexArtifactStore.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_analytics_funnel_ops_read_model_changes(): void
    {
        $changed = [
            'backend/app/Filament/Ops/Pages/FunnelConversionPage.php',
            'backend/app/Filament/Ops/Widgets/FunnelWidget.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_payment_unlock_attribution_diagnostics(): void
    {
        $changed = [
            'backend/app/Services/Analytics/PaymentUnlockAttributionDiagnostics.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_packs_index_phase3_responsibility_shrink(): void
    {
        $changed = [
            'backend/app/Services/Content/ContentPacksIndex.php',
            'backend/app/Services/Content/ContentPacksIndexFallbackScanner.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_content_runtime_cache_freshness_changes(): void
    {
        $changed = [
            'backend/app/Services/Content/ContentLoaderService.php',
            'backend/app/Services/Content/ContentPacksIndex.php',
            'backend/app/Services/Content/ContentPacksIndexArtifactStore.php',
            'backend/app/Services/Content/ContentPacksIndexFallbackScanner.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_content_asset_loader_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/ContentAssets/BigFiveV2AssetPackageLoader.php',
            'backend/app/Services/BigFive/ResultPageV2/RouteMatrix/BigFiveV2RouteMatrixParser.php',
            'backend/app/Services/BigFive/ResultPageV2/Selector/BigFiveV2DeterministicSelector.php',
            'backend/app/Services/BigFive/ResultPageV2/Composer/BigFiveV2PilotPayloadComposer.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_routing_adapter_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/Routing/BigFiveV2BandMapper.php',
            'backend/app/Services/BigFive/ResultPageV2/Routing/BigFiveV2ProjectionRouteInputAdapter.php',
            'backend/app/Services/BigFive/ResultPageV2/Routing/BigFiveV2RouteInput.php',
            'backend/app/Services/BigFive/ResultPageV2/Routing/BigFiveV2RouteMatrixLookup.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_pilot_runtime_flag_wrapper_change(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/BigFiveResultPageV2RuntimeWrapper.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_surface_adapter_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/Pdf/BigFiveV2PdfPayloadAdapter.php',
            'backend/app/Services/BigFive/ResultPageV2/Share/BigFiveV2ShareSafeSummaryAdapter.php',
            'backend/app/Services/BigFive/ResultPageV2/History/BigFiveV2HistorySnapshotAdapter.php',
            'backend/app/Services/BigFive/ResultPageV2/Compare/BigFiveV2CompareSnapshotAdapter.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_rollout_governance_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/Rollout/BigFiveV2ProductionRolloutDecision.php',
            'backend/app/Services/BigFive/ResultPageV2/Rollout/BigFiveV2ProductionRolloutGate.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_rollout_observability_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/Observability/BigFiveV2ProductionRolloutTelemetry.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_en_parity_draft_catalog(): void
    {
        $changed = [
            'backend/content_packs/BIG5_OCEAN/v2/drafts/en_parity/result_page_v2_en_asset_catalog_draft.v1.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_allows_bigfive_norm_foundation_data_scope_only(): void
    {
        $allowed = [
            'backend/app/Models/BigFiveNormObservation.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormObservationCaptureWriter.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormCaptureDecision.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormCaptureResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAnonymizer.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormPrivacyPolicy.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAggregationDryRun.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAggregationResult.php',
            'backend/database/migrations/2026_05_06_132700_create_big_five_norm_observations_table.php',
        ];

        $blocked = [
            'backend/app/Services/BigFive/Norms/BigFiveNormRuntimeSelector.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRuntimeComposer.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRuntimeEngine.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormPublicPercentilePresenter.php',
            'backend/app/Services/BigFive/BigFivePublicProjectionService.php',
            'backend/routes/api.php',
            'frontend/src/big5/norms.ts',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($allowed, '', ''));
        $this->assertSame($blocked, $this->mbtiImpactingRuntimeChanges($blocked, '', ''));
    }

    public function test_runtime_freeze_classifier_allows_bigfive_dne_internal_snapshot_scope_only(): void
    {
        $allowed = [
            'backend/app/Services/BigFive/Norms/BigFiveNormSnapshot.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSnapshotBuilder.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRecomputeEngine.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRecomputeResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSegmentedAggregator.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSegmentedAggregationResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormDriftDetector.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormDriftResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormInternalPercentileResolver.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormInternalPercentileDecision.php',
        ];

        $blocked = [
            'backend/app/Services/BigFive/Norms/BigFiveNormRuntimeSnapshotResolver.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormPublicPercentilePresenter.php',
            'backend/app/Services/BigFive/BigFivePublicProjectionService.php',
            'backend/routes/api.php',
            'frontend/src/big5/dynamic-norms.ts',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($allowed, '', ''));
        $this->assertSame($blocked, $this->mbtiImpactingRuntimeChanges($blocked, '', ''));
    }

    public function test_runtime_freeze_classifier_allows_bigfive_v2_cms_editorial_governance_scope_only(): void
    {
        $allowed = [
            'backend/app/Filament/Ops/Support/BigFiveV2EditorialAssetIndexPresenter.php',
            'backend/app/Models/BigFiveV2EditorialAssetIndexEntry.php',
            'backend/app/Models/BigFiveV2EditorialRevision.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialAssetIndex.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialApprovalFlow.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialPreviewService.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialWorkflow.php',
            'backend/app/Policies/BigFiveV2EditorialRevisionPolicy.php',
            'backend/database/migrations/2026_05_07_010000_create_big_five_v2_editorial_revisions_table.php',
        ];

        $blocked = [
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialRuntimePublisher.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2EditorialRuntimePublishLinkage.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2CmsRuntimeComposer.php',
            'backend/app/Services/BigFive/Cms/BigFiveV2CmsRuntimeSelector.php',
            'backend/app/Services/BigFive/BigFivePublicProjectionService.php',
            'backend/routes/api.php',
            'frontend/src/big5/cms-preview.ts',
            'backend/content_packs/big5/default/manifest.json',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($allowed, '', ''));
        $this->assertSame($blocked, $this->mbtiImpactingRuntimeChanges($blocked, '', ''));
    }

    public function test_runtime_freeze_classifier_keeps_mbti_and_bigfive_runtime_changes_blocked(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/BigFiveResultPageV2Transformer.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\MbtiPrewarmCommand;',
            '+        MbtiPrewarmCommand::class,',
        ];

        $this->assertSame($changed, $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    /**
     * @return array<string,mixed>
     */
    private function previewEnvelope(): array
    {
        return $this->decodeJsonFile('tests/Fixtures/big5_result_page_v2/'.self::FIXTURE_FILE);
    }

    /**
     * @return array<string,mixed>
     */
    private function previewPayload(): array
    {
        $payload = $this->previewEnvelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function coreBody(): array
    {
        return $this->decodeJsonFile(self::CORE_BODY_FILE);
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleMapping(): array
    {
        return $this->decodeJsonFile(self::MODULE_MAPPING_FILE);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sourceSectionsRepresentedByBlocks(array $payload): array
    {
        $sections = [];
        foreach ($payload['modules'] as $module) {
            foreach ($module['blocks'] as $block) {
                $sectionKey = (string) ($block['source_section_key'] ?? '');
                if ($sectionKey !== '' && ! in_array($sectionKey, $sections, true)) {
                    $sections[] = $sectionKey;
                }
            }
        }

        return $sections;
    }

    private function visibleTextForSection(string $sectionKey): string
    {
        $parts = [];
        foreach ($this->previewPayload()['modules'] as $module) {
            foreach ($module['blocks'] as $block) {
                if (($block['source_section_key'] ?? null) === $sectionKey) {
                    $parts[] = $this->visibleText((array) ($block['content'] ?? []));
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function antiTargetTerms(): array
    {
        $decoded = $this->decodeJsonFile(self::ANTI_TARGET_FILE);

        return array_values(array_map(
            static fn (array $term): string => (string) $term['term'],
            (array) ($decoded['terms'] ?? []),
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function visibleText(array $payload): string
    {
        $parts = [];
        $this->collectVisibleText($payload, $parts);

        return implode("\n", $parts);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     * @param  list<string>  $parts
     */
    private function collectVisibleText(array $payload, array &$parts): void
    {
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::VISIBLE_FIELDS, true)) {
                $parts[] = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : (string) $value;

                continue;
            }

            if (is_array($value)) {
                $this->collectVisibleText($value, $parts);
            }
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function gitChangedFilesInBranchDiff(array $paths): array
    {
        $repoRoot = dirname(base_path());
        $baseRef = $this->mergeBaseWithMain($repoRoot);
        $this->assertNotSame('', $baseRef);

        $command = array_merge(['git', '-C', $repoRoot, 'diff', '--name-only', "{$baseRef}...HEAD", '--'], $paths);
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return $this->mbtiImpactingRuntimeChanges(array_values(array_unique(array_filter($output))), $repoRoot, $baseRef);
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>|null  $kernelChangedLines
     * @return list<string>
     */
    private function mbtiImpactingRuntimeChanges(
        array $changed,
        string $repoRoot,
        string $baseRef,
        ?array $kernelChangedLines = null,
        ?array $routeChangedLines = null,
        ?array $appServiceProviderChangedLines = null,
        ?array $scaleRegistrySeederChangedLines = null,
        ?array $articleControllerChangedLines = null,
        ?array $attributionControllerChangedLines = null,
        ?array $attemptSubmitServiceChangedLines = null,
        ?array $webRouteChangedLines = null,
        ?array $resolveOrgContextChangedLines = null,
        ?array $contentPacksIndexChangedLines = null,
    ): array {
        $impacting = [];

        foreach ($changed as $file) {
            if ($this->isCareerConsoleCommandFile($file)) {
                continue;
            }

            if ($this->isCareerCliArtifactPathGuardFile($file)) {
                continue;
            }

            if ($this->isCmsArticleReportCorrectnessFile($file)) {
                continue;
            }

            if ($this->isPersonalityEnneagramCompatibilityFile($file)) {
                continue;
            }

            if ($this->isMbtiPersonalityVariantSeoMetadataRefreshFile($file)) {
                continue;
            }

            if ($this->isMbtiPersonalityVariantSectionStructureFile($file)) {
                continue;
            }

            if ($this->isPublicContentReleaseGuardCommandFile($file)) {
                continue;
            }

            if ($this->isStorageReleaseRootsAuditCommandFile($file)) {
                continue;
            }

            if ($this->isStoragePathSafetyHardeningFile($file)) {
                continue;
            }

            if ($this->isMbtiPrivateRelationshipAuthHardeningFile($file)) {
                continue;
            }

            if ($this->isCiScaleImpactCommandFile($file)) {
                continue;
            }

            if ($this->isSitemapSourceCacheCommandFile($file)) {
                continue;
            }

            if ($this->isContentPacksIndexArtifactRuntimeFile($file)) {
                continue;
            }

            if ($this->isContentReleaseRevalidateAutomationFile($file)) {
                continue;
            }

            if ($this->isCmsMediaPipelineFile($file)) {
                continue;
            }

            if ($this->isFreemiumLocalePolicyFile($file)) {
                continue;
            }

            if ($this->isCommercePaymentActionFile($file)) {
                continue;
            }

            if ($this->isCmsLifecycleTenantScopeFile($file)) {
                continue;
            }

            if ($this->isDailyGivingLedgerFile($file)) {
                continue;
            }

            if ($this->isResearchBackendMvpFile($file)) {
                continue;
            }

            if (
                $file === 'backend/app/Http/Middleware/ResolveOrgContext.php'
                && $this->resolveOrgContextDiffIsResearchCmsTrustedOrgOnly(
                    $resolveOrgContextChangedLines ?? (
                        $repoRoot !== '' && $baseRef !== ''
                            ? $this->changedLinesForFile($repoRoot, $baseRef, $file)
                            : []
                    )
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php'
                && $this->articleControllerDiffIsPublicArticleRecencyOrderingOnly(
                    $articleControllerChangedLines ?? (
                        $repoRoot !== '' && $baseRef !== ''
                            ? $this->changedLinesForFile($repoRoot, $baseRef, $file)
                            : []
                    )
                )
            ) {
                continue;
            }

            if ($this->isArticlePublishingRuntimeTruthGateFile($file)) {
                continue;
            }

            if ($this->isArticleEditorialPackageDraftGateFile($file)) {
                continue;
            }

            if ($this->isArticleBodyH1GuardFile($file)) {
                continue;
            }

            if ($this->isArticlePublishingOpsDashboardFile($file)) {
                continue;
            }

            if ($this->isArticleEditorialReviewApprovalFile($file)) {
                continue;
            }

            if ($this->isArticleDraftPreviewOpsRouteFile($file)) {
                continue;
            }

            if ($this->isArticleMultiTestGraphEdgeFile($file)) {
                continue;
            }

            if ($this->isHomepageRecommendedArticleMetadataBackfillFile($file)) {
                continue;
            }

            if ($this->isSeoIntelLogicalDbFoundationMigrationFile($file)) {
                continue;
            }

            if ($this->isSeoIntelDisabledCollectorSkeletonFile($file)) {
                continue;
            }

            if ($this->isSeoIntelUrlTruthInventoryCollectorFile($file)) {
                continue;
            }

            if ($this->isSeoIntelTwoStageUrlTruthHandoffFile($file)) {
                continue;
            }

            if ($this->isSeoIntelDriftFoundationFile($file)) {
                continue;
            }

            if ($this->isSeoIntelAttributionRevenueFoundationFile($file)) {
                continue;
            }

            if ($this->isSeoConversionDailyReadModelFile($file)) {
                continue;
            }

            if ($this->isSeoIntelGscFoundationFile($file)) {
                continue;
            }

            if ($this->isSeoIntelBaiduIndexNowFoundationFile($file)) {
                continue;
            }

            if ($this->isDomesticSearchAdapterContractFile($file)) {
                continue;
            }

            if ($this->isChineseCrawlerLogFoundationFile($file)) {
                continue;
            }

            if ($this->isCrawlerLogFixtureParserMvpFile($file)) {
                continue;
            }

            if ($this->isCrawlerLogAggregateDryRunFile($file)) {
                continue;
            }

            if ($this->isCrawlerLogAggregateStorageGateFile($file)) {
                continue;
            }

            if ($this->isSeoIssueQueueFoundationFile($file)) {
                continue;
            }

            if ($this->isSeoDashApiReadOnlyContractFile($file)) {
                continue;
            }

            if ($this->isSeoIntelSearchChannelQueueRuntimeFile($file)) {
                continue;
            }

            if ($this->isSeoIntelMbtiUrlTruthCleanupRuntimeFile($file)) {
                continue;
            }

            if ($this->isContentPublishRehearsalDryRunFile($file)) {
                continue;
            }

            if ($this->isInternalLinkGraphDryRunFile($file)) {
                continue;
            }

            if ($this->isTranslationParityReadModelFile($file)) {
                continue;
            }

            if ($this->isContentPagesLocalBaselineImportPackageFile($file)) {
                continue;
            }

            if ($this->isScienceContentPageNoWriteGateFile($file)) {
                continue;
            }

            if ($this->isContentPagesControlledPublishRuntimeFile($file)) {
                continue;
            }

            if ($this->isContentPageHelpServiceFieldContractFile($file)) {
                continue;
            }

            if ($this->isScienceContentPagePublishSafetyFieldFile($file)) {
                continue;
            }

            if ($this->isChineseClaimLinterRuntimeFile($file)) {
                continue;
            }

            if ($this->isSeoIntelOpsDashboardReadModelFile($file)) {
                continue;
            }

            if ($this->isSeoIntelOpsDashboardUiFile($file)) {
                continue;
            }

            if ($this->isContentGrowthAttributionOpsUiFile($file)) {
                continue;
            }

            if ($this->isContentOverviewOpsUiFile($file)) {
                continue;
            }

            if ($this->isPersonalityOpsUiFile($file)) {
                continue;
            }

            if ($this->isSeoIntelMigrationIsolationFile($file)) {
                continue;
            }

            if ($this->isControlledArticlePublishSopFile($file)) {
                continue;
            }

            if ($this->isArticleCoverPropagationSmokeFile($file)) {
                continue;
            }

            if ($this->isPrivacyLogsDsarKeyRotationFile($file)) {
                continue;
            }

            if ($this->isFileImportIdempotencyHardeningFile($file)) {
                continue;
            }

            if ($this->isSafeTmpArtifactSupportFile($file)) {
                continue;
            }

            if ($this->isDbMigrationPortabilityFile($file)) {
                continue;
            }

            if ($this->isDestructiveMigrationRetirementEvidenceFile($file)) {
                continue;
            }

            if (
                $file === 'backend/app/Services/Attempts/AttemptSubmitService.php'
                && $this->attemptSubmitServiceDiffIsIqResultSecrecyRedactionOnly(
                    $attemptSubmitServiceChangedLines ?? (
                        $repoRoot !== '' && $baseRef !== ''
                            ? $this->changedLinesForFile($repoRoot, $baseRef, $file)
                            : []
                    )
                )
            ) {
                continue;
            }

            if ($this->isIqResultSecrecyRedactionFile($file)) {
                continue;
            }

            if ($this->isCiAuthBypassHardeningFile($file)) {
                continue;
            }

            if ($this->isCareerDisplaySurfaceFile($file)) {
                continue;
            }

            if ($this->isCareerPolicyClaimGuardFile($file)) {
                continue;
            }

            if ($this->isCareerPublicDistributionFile($file)) {
                continue;
            }

            if ($this->isCareerRuntimePublishProjectionFile($file)) {
                continue;
            }

            if ($this->isCareerCanonicalEligibilityAuditSchemaFile($file)) {
                continue;
            }

            if ($this->isCareerPublicResolutionPlanResolverFile($file)) {
                continue;
            }

            if ($this->isCareerOccupationEntityInventoryAuditFile($file)) {
                continue;
            }

            if ($this->isCareerBaselineMetadataInventoryAuditFile($file)) {
                continue;
            }

            if ($this->isCareerIndexStateAuthorityAuditFile($file)) {
                continue;
            }

            if ($this->isCareerEntityIndexContextArtifactFile($file)) {
                continue;
            }

            if ($this->isCareerRuntimeProjectionTruthAuditFile($file)) {
                continue;
            }

            if ($this->isCareerSeoGeoReadinessAuditFile($file)) {
                continue;
            }

            if ($this->isCareerSurfaceReadinessAuditFile($file)) {
                continue;
            }

            if ($this->isCareerSurfaceContextArtifactFile($file)) {
                continue;
            }

            if ($this->isCareer80CohortReadinessPlanFile($file)) {
                continue;
            }

            if ($this->isCareer2786ReadinessPolicyFile($file)) {
                continue;
            }

            if ($this->isCareerExpansionManifestTrainFile($file)) {
                continue;
            }

            if ($this->isCareerBatchLiveAcceptanceV2File($file)) {
                continue;
            }

            if ($this->isCareer2786FullAuditArtifactFile($file)) {
                continue;
            }

            if ($this->isCareerDetailReady1048AuditFile($file)) {
                continue;
            }

            if ($this->isCareerRuntimeProjectionConsumerFile($file)) {
                continue;
            }

            if ($this->isCareerDirectoryAuthorityApiFile($file)) {
                continue;
            }

            if (
                $file === 'backend/app/Services/Content/ContentPacksIndex.php'
                && $this->contentPacksIndexDiffIsStreamingScanOnly(
                    $contentPacksIndexChangedLines ?? (
                        $repoRoot !== '' && $baseRef !== ''
                            ? $this->changedLinesForFile($repoRoot, $baseRef, $file)
                            : []
                    )
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/web.php'
                && (
                    $this->routeDiffIsPublicHealthzAliasOnly($webRouteChangedLines ?? $this->webRouteChangedLines($repoRoot, $baseRef))
                    || $this->routeDiffIsApiRootServiceLandingOnly($webRouteChangedLines ?? $this->webRouteChangedLines($repoRoot, $baseRef))
                    || $this->routeDiffIsArticleDraftPreviewOpsOnly($webRouteChangedLines ?? $this->webRouteChangedLines($repoRoot, $baseRef))
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsResearchBackendMvpOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsCareerCnProxyPublicOwnerSurfaceOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsCareerPublicDistributionOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsCareerDirectoryAuthorityOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsDailyGivingLedgerOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsSeoDashApiReadOnlyContractOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if ($this->isBigFiveV2PilotSupportFile($file)) {
                continue;
            }

            if ($this->isBigFiveV2EnParityDraftCatalogFile($file)) {
                continue;
            }

            if ($this->isBigFiveNormFoundationSchemaFile($file)) {
                continue;
            }

            if ($this->isBigFiveNormFoundationServiceFile($file)) {
                continue;
            }

            if ($this->isBigFiveDynamicNormEngineInternalFile($file)) {
                continue;
            }

            if ($this->isBigFiveV2CmsEditorialGovernanceFile($file)) {
                continue;
            }

            if ($this->isAttemptEmailBindingFoundationFile($file)) {
                continue;
            }

            if ($this->isAttemptDataLifecycleErasureFile($file)) {
                continue;
            }

            if ($this->isAttemptSubmissionReliabilityHardeningFile($file)) {
                continue;
            }

            if ($this->isAttemptSubmissionQueueJitterFile($file)) {
                continue;
            }

            if ($this->isCommerceTenantRepairCommandFile($file)) {
                continue;
            }

            if ($this->isResultEmailLookupFile($file)) {
                continue;
            }

            if ($this->isResultEmailGatedReadFile($file)) {
                continue;
            }

            if ($this->isResultEmailAccessLinkDeliveryFile($file)) {
                continue;
            }

            if ($this->isAnalyticsFunnelEventTaxonomyFile($file)) {
                continue;
            }

            if ($this->isAnalyticsFunnelOpsReadModelFile($file)) {
                continue;
            }

            if ($this->isPaymentUnlockAttributionDiagnosticsFile($file)) {
                continue;
            }

            if ($this->isAnalyticsFunnelRefreshCommandFile($file)) {
                continue;
            }

            if ($this->isEq60V5ReportAssetLayerChange($file)) {
                continue;
            }

            if ($this->isEqSjt16ContentPackSkeletonChange($file)) {
                continue;
            }

            if ($this->isEqSjt16ScorerReadyChange($file)) {
                continue;
            }

            if ($this->isEqIntegratedReportComposerDraftChange($file)) {
                continue;
            }

            if ($this->isEqSjtValidationTelemetryContractChange($file)) {
                continue;
            }

            if ($this->isEq60JourneyStateContractChange($file)) {
                continue;
            }

            if ($this->isEq60FreeReportContractChange($file, $repoRoot, $baseRef)) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsEq60JourneyStateContractOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/app/Http/Controllers/API/V0_3/MbtiAttributionEventController.php'
                && $this->attributionControllerDiffIsSeoFunnelContractOnly(
                    $attributionControllerChangedLines ?? (
                        $repoRoot !== '' && $baseRef !== ''
                            ? $this->changedLinesForFile($repoRoot, $baseRef, $file)
                            : []
                    )
                )
            ) {
                continue;
            }

            if ($this->isIqScoringContractFoundationFile($file)) {
                continue;
            }

            if ($this->isIqNormAuthorityFoundationFile($file)) {
                continue;
            }

            if ($this->isIqNormImportDryRunCommandFile($file)) {
                continue;
            }

            if ($this->isIqProductionObservabilityGuardFile($file)) {
                continue;
            }

            if ($this->isRiasecMeasurementContractComparePolicyFile($file)) {
                continue;
            }

            if ($this->isRiasecInterpretationRuleContractFile($file)) {
                continue;
            }

            if ($this->isRiasecQualityRuleContractFile($file)) {
                continue;
            }

            if ($this->isRiasecModuleSelectorFile($file)) {
                continue;
            }

            if ($this->isRiasecContentRegistryContractFile($file)) {
                continue;
            }

            if ($this->isRiasecProjectionV2MinimalFile($file)) {
                continue;
            }

            if ($this->isRiasecSnapshotBoundReportFile($file)) {
                continue;
            }

            if ($this->isRiasecSnapshotSurfaceFile($file)) {
                continue;
            }

            if ($this->isRiasecTechnicalNoteMethodBoundaryFile($file)) {
                continue;
            }

            if ($this->isRiasecActivityExplorerExamplesFile($file)) {
                continue;
            }

            if ($this->isRiasecExplorationFeedbackOverlayFile($file)) {
                continue;
            }

            if ($this->isRiasecQuestionPackTranslationFile($file)) {
                continue;
            }

            if ($this->isEnneagramForcedChoiceQuestionPackTranslationFile($file)) {
                continue;
            }

            if ($this->isIqReportFoundationFile($file)) {
                continue;
            }

            if ($this->isIqPaidReportEntitlementContractFile($file)) {
                continue;
            }

            if ($this->isClinicalComboEnPaidParityFile($file)) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsSeoAttributionIngestOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsCiAuthBypassHardeningOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsEmailAccessTrainOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if (
                $file === 'backend/app/Providers/AppServiceProvider.php'
                && $this->appServiceProviderDiffIsResultLookupRateLimiterOnly(
                    $appServiceProviderChangedLines ?? $this->appServiceProviderChangedLines($repoRoot, $baseRef)
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/app/Console/Kernel.php'
                && (
                    $this->kernelDiffIsCareerOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                    || $this->kernelDiffIsArticleEditorialPackageDraftGateOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                    || $this->kernelDiffIsControlledArticlePublishSopOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                    || $this->kernelDiffIsArticleCoverPropagationSmokeOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                    || $this->kernelDiffIsAlipayPendingCompensationSchedulerOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                    || $this->kernelDiffIsIqNormImportDryRunOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/bootstrap/app.php'
                && $repoRoot !== ''
                && $baseRef !== ''
                && $this->kernelDiffIsAlipayPendingCompensationSchedulerOnly(
                    $this->changedLinesForFile($repoRoot, $baseRef, $file)
                )
            ) {
                continue;
            }

            if (
                $file === 'backend/database/seeders/ScaleRegistrySeeder.php'
                && $this->scaleRegistrySeederDiffIsIqIdentityMetadataOnly(
                    $scaleRegistrySeederChangedLines ?? $this->scaleRegistrySeederChangedLines($repoRoot, $baseRef)
                )
            ) {
                continue;
            }

            if ($this->isBackendPintBaselineStyleOnlyChange($file, $repoRoot, $baseRef)) {
                continue;
            }

            $impacting[] = $file;
        }

        return array_values(array_unique($impacting));
    }

    private function isCareerConsoleCommandFile(string $file): bool
    {
        return preg_match('#^backend/app/Console/Commands/Career[A-Za-z0-9_]*\.php$#', $file) === 1;
    }

    private function isCareerCliArtifactPathGuardFile(string $file): bool
    {
        return $file === 'backend/app/Services/Career/CareerCliArtifactPathGuard.php';
    }

    private function isCmsArticleReportCorrectnessFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/ArticleResource/Pages/CreateArticle.php',
            'backend/app/Filament/Ops/Resources/ArticleResource/Pages/EditArticle.php',
            'backend/app/Filament/Ops/Resources/ArticleResource/Support/ArticleSeoMetaWorkspace.php',
            'backend/app/Models/ReportSnapshot.php',
        ], true);
    }

    private function isPersonalityEnneagramCompatibilityFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php',
            'backend/app/PersonalityCms/DesktopClone/PersonalityDesktopCloneAssetSlotSupport.php',
            'backend/app/Services/Experiments/ExperimentAssigner.php',
        ], true);
    }

    private function isMbtiPersonalityVariantSeoMetadataRefreshFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/PersonalityRefreshMbtiVariantSeoMetadata.php',
            'backend/app/Services/Cms/MbtiPersonalityVariantSeoMetadataService.php',
        ], true);
    }

    private function isMbtiPersonalityVariantSectionStructureFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/PersonalityEnsureMbtiVariantSectionStructure.php',
            'backend/app/Services/Cms/MbtiPersonalityVariantSectionStructureService.php',
        ], true);
    }

    private function isPublicContentReleaseGuardCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/ReleaseVerifyPublicContent.php';
    }

    private function isStorageReleaseRootsAuditCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/StorageReleaseRootsAudit.php';
    }

    private function isStoragePathSafetyHardeningFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/StorageRehydrateExactRelease.php',
            'backend/app/Services/Storage/QuarantinedRootPurgeService.php',
            'backend/app/Services/Storage/ReportArtifactsArchiveService.php',
        ], true);
    }

    private function isMbtiPrivateRelationshipAuthHardeningFile(string $file): bool
    {
        return $file === 'backend/app/Services/V0_3/MbtiCompareInviteService.php';
    }

    private function isCiScaleImpactCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/CiScaleImpact.php';
    }

    private function isSitemapSourceCacheCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/WarmSitemapSourceCacheCommand.php';
    }

    private function isContentPacksIndexArtifactRuntimeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ContentPacksIndexBuild.php',
            'backend/app/Services/Content/ContentLoaderService.php',
            'backend/app/Services/Content/ContentPacksIndex.php',
            'backend/app/Services/Content/ContentPacksIndexArtifactStore.php',
            'backend/app/Services/Content/ContentPacksIndexFallbackScanner.php',
        ], true);
    }

    private function isContentReleaseRevalidateAutomationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Support/ContentReleaseFollowUp.php',
            'backend/app/Services/Cms/ContentReleasePathPlanner.php',
        ], true);
    }

    private function isCmsMediaPipelineFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/ArticleResource.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource/Pages/CreateMediaAsset.php',
            'backend/app/Filament/Ops/Resources/MediaAssetResource/Pages/EditMediaAsset.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/MediaLibraryController.php',
            'backend/app/Models/MediaAsset.php',
            'backend/app/Models/MediaVariant.php',
            'backend/app/Services/Cms/MediaAssetStorageSyncService.php',
            'backend/app/Support/PublicMediaUrlGuard.php',
            'backend/database/migrations/2026_05_16_000100_add_media_asset_sync_status.php',
        ], true);
    }

    private function isCommercePaymentActionFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Tenant/Resources/OrderResource.php',
            'backend/app/Http/Controllers/API/V0_3/CommerceController.php',
            'backend/app/Internal/Commerce/PaymentWebhookHandlerCore.php',
            'backend/app/Services/Commerce/Checkout/AlipayCheckoutService.php',
            'backend/app/Services/Commerce/OrderManager.php',
            'backend/app/Services/Commerce/Webhook/WebhookEntitlementService.php',
            'backend/app/Jobs/Commerce/ReprocessPaymentEventJob.php',
        ], true);
    }

    private function isFreemiumLocalePolicyFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Commerce/FreemiumLocalePolicy.php',
            'backend/app/Services/Report/Resolvers/OfferResolver.php',
        ], true);
    }

    private function isCmsLifecycleTenantScopeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Models/Article.php',
            'backend/app/Models/PersonalityProfileSection.php',
            'backend/app/Models/PersonalityProfileSeoMeta.php',
            'backend/app/Models/PersonalityProfileVariant.php',
            'backend/app/Models/PersonalityProfileVariantSection.php',
            'backend/app/Models/PersonalityProfileVariantSeoMeta.php',
            'backend/app/Services/Cms/ArticlePublishService.php',
            'backend/app/Services/Cms/ArticleService.php',
            'backend/database/migrations/2026_05_06_010000_add_org_scope_to_personality_profile_children.php',
        ], true);
    }

    private function isDailyGivingLedgerFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/CreateDailyGivingRecord.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/EditDailyGivingRecord.php',
            'backend/app/Filament/Ops/Resources/DailyGivingRecordResource/Pages/ListDailyGivingRecords.php',
            'backend/app/Http/Controllers/API/V0_5/Foundation/DailyGivingRecordController.php',
            'backend/app/Http/Resources/Foundation/DailyGivingRecordResource.php',
            'backend/app/Models/DailyGivingRecord.php',
            'backend/database/factories/DailyGivingRecordFactory.php',
            'backend/database/migrations/2026_05_30_000100_create_daily_giving_records_table.php',
        ], true);
    }

    private function isResearchBackendMvpFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Cms/ResearchReportController.php',
            'backend/app/Models/ResearchReport.php',
            'backend/database/migrations/2026_05_19_000100_create_research_reports_table.php',
        ], true);
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function resolveOrgContextDiffIsResearchCmsTrustedOrgOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        $allowedLines = [
            '+        $candidates = $this->isOpsPanelRequest($request) || $this->isInternalCmsApiRequest($request)',
            '-        $candidates = $this->isOpsPanelRequest($request)',
            '-                $request->header(\'X-FM-Org-Id\'),',
            '-                $request->header(\'X-Org-Id\'),',
            '-                $request->query(\'org_id\'),',
            '-                $request->route(\'org_id\'),',
            '+    private function isInternalCmsApiRequest(Request $request): bool',
            '+    {',
            '+        return str_starts_with(\'/\'.ltrim($request->path(), \'/\'), \'/api/v0.5/internal/\');',
            '+    }',
            '+',
        ];

        foreach ($changedLines as $line) {
            if (! in_array($line, $allowedLines, true)) {
                return false;
            }
        }

        return true;
    }

    private function isArticlePublishingRuntimeTruthGateFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ArticleImportLocalBaseline.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/LandingSurfaceController.php',
            'backend/app/Services/Career/StructuredData/CareerArticleStructuredDataBuilder.php',
            'backend/app/Services/Cms/ArticleSeoService.php',
        ], true);
    }

    private function isArticleEditorialPackageDraftGateFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/ArticleImportEditorialPackage.php'
            || str_starts_with($file, 'backend/app/Services/Cms/EditorialPackage/');
    }

    private function isArticleBodyH1GuardFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Cms/ArticleBodyHeadingGuard.php',
            'backend/app/Services/Cms/ArticleTranslationRevisionWorkspace.php',
        ], true);
    }

    private function isArticlePublishingOpsDashboardFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Pages/ArticlePublishingOpsPage.php',
            'backend/app/Models/ArticleEditorialPackageImport.php',
            'backend/database/migrations/2026_05_14_000100_create_article_editorial_package_imports_table.php',
        ], true);
    }

    private function isArticleEditorialReviewApprovalFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Pages/EditorialReviewPage.php',
            'backend/app/Services/Cms/ArticleTranslationWorkflowService.php',
        ], true);
    }

    private function isArticleDraftPreviewOpsRouteFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/ArticleResource/Support/ArticleWorkspace.php',
            'backend/app/Http/Controllers/Ops/ArticleDraftPreviewController.php',
        ], true);
    }

    private function isArticleMultiTestGraphEdgeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php',
            'backend/app/Models/ArticleTestEdge.php',
            'backend/database/migrations/2026_05_15_000300_create_article_test_edges_table.php',
        ], true);
    }

    private function isSeoIntelLogicalDbFoundationMigrationFile(string $file): bool
    {
        return in_array($file, [
            'backend/database/migrations/2026_05_17_000100_create_seo_urls_table.php',
            'backend/database/migrations/2026_05_17_000200_create_seo_url_entities_table.php',
            'backend/database/migrations/2026_05_17_000300_create_seo_internal_traffic_rules_table.php',
        ], true);
    }

    private function isSeoIntelDisabledCollectorSkeletonFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelCollectCommand.php',
            'backend/app/Services/SeoIntel/Collectors/NoopSeoIntelCollector.php',
            'backend/app/Services/SeoIntel/SeoIntelCollector.php',
            'backend/app/Services/SeoIntel/SeoIntelCollectorManager.php',
            'backend/app/Services/SeoIntel/SeoIntelCollectorResult.php',
        ], true);
    }

    private function isSeoIntelUrlTruthInventoryCollectorFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/Collectors/UrlTruthInventoryCollector.php',
            'backend/app/Services/SeoIntel/Sources/BackendAuthorityUrlTruthSource.php',
            'backend/app/Services/SeoIntel/Sources/UrlTruthInventorySource.php',
            'backend/app/Services/SeoIntel/UrlTruthInventoryRecord.php',
        ], true);
    }

    private function isSeoIntelTwoStageUrlTruthHandoffFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelUrlTruthHandoffCommand.php',
            'backend/app/Services/SeoIntel/UrlTruthHandoffArtifact.php',
            'backend/app/Services/SeoIntel/UrlTruthInventoryRecordWriter.php',
        ], true);
    }

    private function isSeoIntelDriftFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/Collectors/CrawlerLogFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/DriftFoundationCollector.php',
            'backend/app/Services/SeoIntel/DriftIssueCandidate.php',
            'backend/app/Services/SeoIntel/Drift/CrawlerLogLineParser.php',
            'backend/app/Services/SeoIntel/Drift/CrawlerUserAgentClassifier.php',
            'backend/app/Services/SeoIntel/Drift/HtmlSnapshotParser.php',
            'backend/app/Services/SeoIntel/Drift/MetadataDriftComparator.php',
            'backend/app/Services/SeoIntel/Drift/SitemapLlmsParityComparator.php',
            'backend/app/Services/SeoIntel/UrlTruthDriftIssueCandidateSource.php',
        ], true);
    }

    private function isSeoIntelAttributionRevenueFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/AttributionDailyBuilder.php',
            'backend/app/Services/SeoIntel/Collectors/AttributionRevenueFoundationCollector.php',
            'backend/app/Services/SeoIntel/ConsentStateNormalizer.php',
            'backend/app/Services/SeoIntel/InternalTrafficFilter.php',
            'backend/app/Services/SeoIntel/RevenueDailyBuilder.php',
            'backend/app/Services/SeoIntel/SourceEngineNormalizer.php',
            'backend/database/migrations/2026_05_17_000400_create_seo_event_funnel_daily_table.php',
            'backend/database/migrations/2026_05_17_000500_create_seo_landing_attribution_daily_table.php',
            'backend/database/migrations/2026_05_17_000600_create_seo_revenue_daily_table.php',
            'backend/database/migrations/2026_05_17_000700_create_seo_cluster_daily_table.php',
            'backend/database/migrations/2026_05_17_000800_create_seo_consent_daily_table.php',
        ], true);
    }

    private function isSeoIntelGscFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/Collectors/GscCollector.php',
            'backend/app/Services/SeoIntel/GscQueryClassifier.php',
            'backend/app/Services/SeoIntel/GscSearchAnalyticsRowNormalizer.php',
            'backend/database/migrations/2026_05_17_000900_create_seo_gsc_daily_table.php',
        ], true);
    }

    private function isSeoIntelBaiduIndexNowFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/BaiduPushPayloadValidator.php',
            'backend/app/Services/SeoIntel/Collectors/BaiduFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/IndexNowFoundationCollector.php',
            'backend/app/Services/SeoIntel/IndexNowPayloadValidator.php',
            'backend/app/Services/SeoIntel/SearchChannelSubmissionStatusNormalizer.php',
            'backend/database/migrations/2026_05_17_001000_create_seo_baidu_push_logs_table.php',
            'backend/database/migrations/2026_05_17_001100_create_seo_baidu_landing_daily_table.php',
            'backend/database/migrations/2026_05_17_001200_create_seo_indexnow_submissions_table.php',
        ], true);
    }

    private function isDomesticSearchAdapterContractFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/Collectors/DomesticSearchFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/ShenmaFoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/So360FoundationCollector.php',
            'backend/app/Services/SeoIntel/Collectors/SogouFoundationCollector.php',
            'backend/app/Services/SeoIntel/DomesticIndexSampleNormalizer.php',
            'backend/app/Services/SeoIntel/DomesticSearchEngineAdapterContract.php',
            'backend/app/Services/SeoIntel/DomesticSearchSubmissionStatusNormalizer.php',
            'backend/app/Services/SeoIntel/DomesticSearchUrlEligibilityValidator.php',
            'backend/database/migrations/2026_05_17_001300_create_seo_search_engine_verification_statuses_table.php',
            'backend/database/migrations/2026_05_17_001400_create_seo_domestic_submission_logs_table.php',
            'backend/database/migrations/2026_05_17_001500_create_seo_domestic_index_samples_table.php',
        ], true);
    }

    private function isChineseCrawlerLogFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/ChineseCrawlerUserAgentClassifier.php',
            'backend/app/Services/SeoIntel/Collectors/ChineseCrawlerLogCollector.php',
            'backend/app/Services/SeoIntel/CrawlerLogDailyAggregator.php',
            'backend/app/Services/SeoIntel/CrawlerLogLineParser.php',
            'backend/app/Services/SeoIntel/CrawlerLogPrivacySanitizer.php',
            'backend/database/migrations/2026_05_17_001600_create_seo_crawler_logs_daily_table.php',
        ], true);
    }

    private function isCrawlerLogFixtureParserMvpFile(string $file): bool
    {
        return $file === 'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogFixtureParser.php';
    }

    private function isCrawlerLogAggregateDryRunFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelCrawlerLogObserveCommand.php',
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogAggregateDryRun.php',
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogProductionCanaryDryRun.php',
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogSingleSourceReader.php',
        ], true);
    }

    private function isCrawlerLogAggregateStorageGateFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogAggregateStorageWriter.php',
            'backend/database/migrations/seo_intel/2026_05_22_111800_create_seo_crawler_log_daily_aggregates_table.php',
        ], true);
    }

    private function isSeoIssueQueueFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/Collectors/IssueQueueFoundationCollector.php',
            'backend/app/Services/SeoIntel/SeoIssueQueueContract.php',
            'backend/app/Services/SeoIntel/SeoIssueQueueProducer.php',
            'backend/app/Services/SeoIntel/SeoIssueSanitizer.php',
            'backend/app/Services/SeoIntel/SeoIssueSummaryService.php',
            'backend/database/migrations/2026_05_17_001700_create_seo_issue_queue_table.php',
        ], true);
    }

    private function isSeoDashApiReadOnlyContractFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Ops/SeoIntel/SeoIntelDashboardController.php',
            'backend/app/Http/Middleware/EnsureSeoIntelReadAuthorized.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoDashboardApiReadService.php',
            'backend/app/Support/Rbac/PermissionNames.php',
        ], true);
    }

    private function isSeoIntelSearchChannelQueueRuntimeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelSearchChannelQueueCommand.php',
            'backend/app/Console/Commands/SeoIntelSearchChannelSubmitCommand.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueAuditLogger.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueChannelMapper.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueEligibilityEvaluator.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueEligibilityResult.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueIdempotency.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueLiveSubmissionExecutor.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueuePlanner.php',
            'backend/app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueWriteService.php',
            'backend/database/migrations/seo_intel/2026_05_20_220000_create_seo_search_channel_queue_tables.php',
        ], true);
    }

    private function isSeoIntelMbtiUrlTruthCleanupRuntimeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelMbtiUrlTruthCleanupCommand.php',
            'backend/app/Services/SeoIntel/MbtiUrlTruthCleanupService.php',
        ], true);
    }

    private function isContentPublishRehearsalDryRunFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelContentPublishRehearsalCommand.php',
            'backend/app/Services/SeoIntel/ContentOps/ContentPublishRehearsalDryRun.php',
        ], true);
    }

    private function isInternalLinkGraphDryRunFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelInternalLinkGraphCommand.php',
            'backend/app/Services/SeoIntel/InternalLink/InternalLinkGraphDryRun.php',
        ], true);
    }

    private function isTranslationParityReadModelFile(string $file): bool
    {
        return $file === 'backend/app/Services/SeoIntel/TranslationParity/TranslationParityMatrixReadModel.php';
    }

    private function isContentPagesLocalBaselineImportPackageFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/ContentPagesImportLocalBaseline.php';
    }

    private function isScienceContentPageNoWriteGateFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ScienceContentPageDraftDryRunCommand.php',
            'backend/app/Console/Commands/ScienceContentPageImportDraftsCommand.php',
            'backend/app/Console/Commands/ScienceContentPageOperatorReviewReadinessCommand.php',
            'backend/app/Console/Commands/ScienceContentPagePreImportQaCommand.php',
            'backend/app/Services/Cms/ScienceContentPageDraftDryRunService.php',
            'backend/app/Services/Cms/ScienceContentPageDraftImportService.php',
            'backend/app/Services/Cms/ScienceContentPageFrontmatterReader.php',
            'backend/app/Services/Cms/ScienceContentPageOperatorReviewReadinessService.php',
            'backend/app/Services/Cms/ScienceContentPagePreImportQaService.php',
        ], true);
    }

    private function isContentPagesControlledPublishRuntimeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ContentPagesPublishControlledCommand.php',
            'backend/app/Services/ContentPages/ContentPagesControlledPublishService.php',
        ], true);
    }

    private function isContentPageHelpServiceFieldContractFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/ContentPageResource.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php',
            'backend/app/Models/ContentPage.php',
            'backend/app/Services/Cms/ContentPageTranslationAdapter.php',
            'backend/database/migrations/2026_06_05_150000_add_help_service_fields_to_content_pages.php',
        ], true);
    }

    private function isScienceContentPagePublishSafetyFieldFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/ContentPageResource.php',
            'backend/app/Filament/Ops/Resources/ContentPageResource/Pages/EditContentPage.php',
            'backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php',
            'backend/app/Models/ContentPage.php',
            'backend/app/Services/Cms/ContentPagePublishGate.php',
            'backend/app/Services/Cms/ContentPageTranslationAdapter.php',
            'backend/database/migrations/2026_06_08_010000_add_publish_safety_fields_to_content_pages.php',
        ], true);
    }

    private function isChineseClaimLinterRuntimeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/SeoIntelClaimLintCommand.php',
            'backend/app/Services/SeoIntel/ClaimLint/ChineseClaimLinter.php',
        ], true);
    }

    private function isSeoIntelOpsDashboardReadModelFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/SeoIntel/OpsDashboard/AbstractSeoDashboardReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoCrawlerLogObservationReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoDashboardOverviewReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoIssueQueueReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoSearchChannelQueueReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoUrlTruthReadService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/CareerRuntimeReadModelService.php',
            'backend/app/Services/SeoIntel/OpsDashboard/SeoConversionFunnelReadService.php',
        ], true);
    }

    private function isSeoIntelOpsDashboardUiFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Pages/SeoDashboardAccessPage.php',
        ], true);
    }

    private function isContentGrowthAttributionOpsUiFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Pages/ContentGrowthAttributionPage.php',
            'backend/app/Services/Ops/ContentGrowthAttributionService.php',
        ], true);
    }

    private function isContentOverviewOpsUiFile(string $file): bool
    {
        return $file === 'backend/app/Filament/Ops/Pages/ContentOverviewPage.php';
    }

    private function isPersonalityOpsUiFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Resources/PersonalityProfileResource.php',
            'backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php',
        ], true);
    }

    private function isSeoIntelMigrationIsolationFile(string $file): bool
    {
        return in_array($file, [
            'backend/database/migrations/seo_intel/2026_05_17_000100_create_seo_urls_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000200_create_seo_url_entities_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000300_create_seo_internal_traffic_rules_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000400_create_seo_event_funnel_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000500_create_seo_landing_attribution_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000600_create_seo_revenue_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000700_create_seo_cluster_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000800_create_seo_consent_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_000900_create_seo_gsc_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001000_create_seo_baidu_push_logs_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001100_create_seo_baidu_landing_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001200_create_seo_indexnow_submissions_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001300_create_seo_search_engine_verification_statuses_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001400_create_seo_domestic_submission_logs_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001500_create_seo_domestic_index_samples_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001600_create_seo_crawler_logs_daily_table.php',
            'backend/database/migrations/seo_intel/2026_05_17_001700_create_seo_issue_queue_table.php',
        ], true);
    }

    private function isControlledArticlePublishSopFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ArticlePublishControlled.php',
            'backend/app/Services/Cms/ArticlePublishService.php',
        ], true);
    }

    private function isArticleCoverPropagationSmokeFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ArticleCoverPropagationSmoke.php',
            'backend/tests/Feature/Console/ArticleCoverPropagationSmokeCommandTest.php',
        ], true);
    }

    private function isHomepageRecommendedArticleMetadataBackfillFile(string $file): bool
    {
        return $file === 'backend/database/migrations/2026_05_27_000100_backfill_homepage_recommended_en_article_media_taxonomy.php';
    }

    private function isPrivacyLogsDsarKeyRotationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Contracts/Security/PiiEnvelopeAdapter.php',
            'backend/app/Http/Controllers/API/V0_3/ComplianceDsarController.php',
            'backend/app/Jobs/Ops/BackfillPiiEncryptionJob.php',
            'backend/app/Support/PiiCipher.php',
            'backend/app/Support/Security/ExternalKmsPiiEnvelopeAdapter.php',
            'backend/app/Support/Security/LocalPiiEnvelopeAdapter.php',
        ], true);
    }

    private function isFileImportIdempotencyHardeningFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Career/Import/CareerAuthorityDatasetReader.php',
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
            'backend/app/Services/Ingestion/ReplayService.php',
            'backend/app/Services/Storage/QuarantinedRootRestoreService.php',
            'backend/app/Support/Xlsx/XlsxCellReference.php',
        ], true);
    }

    private function isSafeTmpArtifactSupportFile(string $file): bool
    {
        return $file === 'backend/app/Support/SafeArtifactDirectory.php';
    }

    private function isDbMigrationPortabilityFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Support/Database/SchemaIndex.php',
            'backend/database/migrations/2026_02_10_160100_add_idx_idempo_payload.php',
            'backend/database/migrations/2026_02_11_090100_add_idx_idempotency_keys_provider_recorded_hash.php',
            'backend/database/migrations/2026_02_27_110000_ensure_norms_table_lookup_index.php',
        ], true);
    }

    private function isDestructiveMigrationRetirementEvidenceFile(string $file): bool
    {
        return $file === 'backend/database/migrations/2026_03_26_120000_drop_attempt_quality_table.php';
    }

    private function isIqScoringContractFoundationFile(string $file): bool
    {
        return $file === 'backend/app/Services/Assessment/Drivers/IqTestDriver.php';
    }

    private function isIqNormAuthorityFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Iq/IqNormAuthorityContract.php',
            'backend/database/migrations/2026_05_31_090000_create_iq_norm_authorities_table.php',
        ], true);
    }

    private function isIqNormImportDryRunCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/NormsIqImport.php';
    }

    private function isIqProductionObservabilityGuardFile(string $file): bool
    {
        return $file === 'backend/app/Services/Iq/IqProductionObservability.php';
    }

    private function isIqResultSecrecyRedactionFile(string $file): bool
    {
        return $file === 'backend/app/Services/Iq/IqResultPayloadRedactor.php';
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function attemptSubmitServiceDiffIsIqResultSecrecyRedactionOnly(array $changedLines): bool
    {
        return $changedLines === [
            '+use App\\Services\\Iq\\IqResultPayloadRedactor;',
            '+        if (IqResultPayloadRedactor::isIqScale($responseCodes[\'scale_code_legacy\'], $responseCodes[\'scale_code_v2\'])) {',
            '+            $payload = IqResultPayloadRedactor::redactAnswerKeys($payload);',
            '+            $compatScores = IqResultPayloadRedactor::redactAnswerKeys($compatScores);',
            '+        }',
            '+',
        ];
    }

    private function isRiasecMeasurementContractComparePolicyFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Assessment/Drivers/RiasecDriver.php',
            'backend/app/Services/Attempts/AttemptStartService.php',
            'backend/app/Services/Riasec/RiasecCompareGuardService.php',
            'backend/app/Services/Riasec/RiasecFormCatalog.php',
            'backend/app/Services/Riasec/RiasecMeasurementContract.php',
            'backend/app/Services/Riasec/RiasecPublicFormSummaryBuilder.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
            'backend/app/Services/V0_3/Me/MeAttemptsService.php',
        ], true);
    }

    private function isRiasecInterpretationRuleContractFile(string $file): bool
    {
        return $file === 'backend/app/Services/Riasec/RiasecInterpretationRuleContract.php';
    }

    private function isRiasecQualityRuleContractFile(string $file): bool
    {
        return $file === 'backend/app/Services/Riasec/RiasecQualityRuleContract.php';
    }

    private function isRiasecModuleSelectorFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Riasec/RiasecReportModuleSelector.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ], true);
    }

    private function isRiasecContentRegistryContractFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Riasec/RiasecContentRegistrySlotContract.php',
            'backend/app/Services/Riasec/RiasecDeepCopySlotRegistry.php',
        ], true);
    }

    private function isRiasecProjectionV2MinimalFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/AttemptReadController.php',
            'backend/app/Services/Assessment/Drivers/RiasecDriver.php',
            'backend/app/Services/Report/RiasecReportComposer.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ], true);
    }

    private function isRiasecSnapshotBoundReportFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Report/ReportGatekeeper.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
            'backend/app/Services/Report/RiasecReportComposer.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ], true);
    }

    private function isRiasecSnapshotSurfaceFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Report/Pdf/ReportPdfDocumentService.php',
            'backend/app/Services/V0_3/Me/MeAttemptsService.php',
            'backend/app/Services/V0_3/ShareService.php',
        ], true);
    }

    private function isRiasecTechnicalNoteMethodBoundaryFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/ScalesController.php',
            'backend/app/Services/Riasec/RiasecLifecycleCopyService.php',
            'backend/app/Services/Riasec/RiasecTechnicalNoteService.php',
        ], true);
    }

    private function isRiasecActivityExplorerExamplesFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Riasec/RiasecActivityExplorerService.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ], true);
    }

    private function isRiasecExplorationFeedbackOverlayFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Riasec/RiasecExplorationFeedbackOverlayService.php',
            'backend/app/Services/Riasec/RiasecPublicProjectionService.php',
        ], true);
    }

    private function isRiasecQuestionPackTranslationFile(string $file): bool
    {
        return preg_match('#^backend/content_packs/RIASEC/v1-(?:standard-60|enhanced-140)/compiled/(?:questions\\.compiled|manifest)\\.json$#', $file) === 1;
    }

    private function isEnneagramForcedChoiceQuestionPackTranslationFile(string $file): bool
    {
        return preg_match('#^backend/content_packs/ENNEAGRAM/v1-forced-choice-144/compiled/(?:questions\\.compiled|manifest)\\.json$#', $file) === 1;
    }

    private function isIqReportFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Report/IqReportBuilder.php',
            'backend/app/Services/Report/ReportComposerRegistry.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
            'backend/app/Http/Controllers/API/V0_3/AttemptReadController.php',
        ], true);
    }

    private function isIqPaidReportEntitlementContractFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Commerce/EntitlementManager.php',
            'backend/app/Services/Report/ReportAccess.php',
            'backend/app/Services/Report/Resolvers/AccessResolver.php',
        ], true);
    }

    private function isClinicalComboEnPaidParityFile(string $file): bool
    {
        if ($file === 'backend/app/Services/Report/ClinicalCombo/ClinicalComboBlockSelector.php') {
            return true;
        }

        if ($file === 'backend/content_packs/CLINICAL_COMBO_68/v1/raw/blocks/paid_blocks.json') {
            return true;
        }

        return preg_match('#^backend/content_packs/CLINICAL_COMBO_68/v1/compiled/[a-z_]+(?:\\.compiled)?\\.json$#', $file) === 1;
    }

    private function isEq60FreeReportContractChange(string $file, string $repoRoot, string $baseRef): bool
    {
        if ($file === 'backend/app/Services/Report/Eq60ReportComposer.php') {
            return true;
        }

        if (! in_array($file, [
            'backend/app/Services/Report/ReportAccess.php',
            'backend/app/Services/Report/Resolvers/AccessResolver.php',
            'backend/database/seeders/CiScalesRegistrySeeder.php',
            'backend/database/seeders/ScaleRegistrySeeder.php',
        ], true)) {
            return false;
        }

        if ($repoRoot === '' || $baseRef === '') {
            return false;
        }

        $changedLines = $this->changedLinesForFile($repoRoot, $baseRef, $file);

        return match ($file) {
            'backend/app/Services/Report/ReportAccess.php' => $changedLines === [
                '+    /**',
                '+     * @return list<string>',
                '+     */',
                '+    public static function eq60FreeSectionKeys(): array',
                '+    {',
                '+        return [',
                "+            'disclaimer_top',",
                "+            'quality_notice',",
                "+            'global_overview',",
                "+            'self_awareness',",
                "+            'emotion_regulation',",
                "+            'empathy',",
                "+            'relationship_management',",
                "+            'cross_quadrant_insight',",
                "+            'action_plan_14d',",
                "+            'methodology',",
                "+            'disclaimer_bottom',",
                '+        ];',
                '+    }',
                '+',
                '+    /**',
                '+     * @return list<string>',
                '+     */',
                '+    public static function eq60AllRuntimeModules(): array',
                '+    {',
                '+        return [',
                '+            self::MODULE_EQ_CORE,',
                '+            self::MODULE_EQ_FULL,',
                '+            self::MODULE_EQ_CROSS_INSIGHTS,',
                '+            self::MODULE_EQ_GROWTH_PLAN,',
                '+        ];',
                '+    }',
                '+',
            ],
            'backend/app/Services/Report/Resolvers/AccessResolver.php' => $changedLines === [
                '-        if ($forceFreeOnly && ! in_array($scaleCode, [ReportAccess::SCALE_BIG5_OCEAN, ReportAccess::SCALE_ENNEAGRAM, ReportAccess::SCALE_RIASEC], true)) {',
                '+        if ($forceFreeOnly && ! $this->isForceFreeFullAccessScale($scaleCode)) {',
                '-        if ($forceFreeOnly && in_array($scaleCode, [ReportAccess::SCALE_BIG5_OCEAN, ReportAccess::SCALE_ENNEAGRAM, ReportAccess::SCALE_RIASEC], true)) {',
                '+        if ($forceFreeOnly && $this->isForceFreeFullAccessScale($scaleCode)) {',
                '-        if ($forceFreeOnly && in_array($scaleCode, [ReportAccess::SCALE_BIG5_OCEAN, ReportAccess::SCALE_ENNEAGRAM, ReportAccess::SCALE_RIASEC], true)) {',
                '-            $modulesAllowed = ReportAccess::normalizeModules(array_merge(',
                '-                ReportAccess::defaultModulesAllowedForLocked($scaleCode),',
                '-                ReportAccess::allDefaultModulesOffered($scaleCode)',
                '-            ));',
                '+        if ($forceFreeOnly && $this->isForceFreeFullAccessScale($scaleCode)) {',
                '+            $modulesAllowed = $scaleCode === ReportAccess::SCALE_EQ_60',
                '+                ? ReportAccess::eq60AllRuntimeModules()',
                '+                : ReportAccess::normalizeModules(array_merge(',
                '+                    ReportAccess::defaultModulesAllowedForLocked($scaleCode),',
                '+                    ReportAccess::allDefaultModulesOffered($scaleCode)',
                '+                ));',
                '+    private function isForceFreeFullAccessScale(string $scaleCode): bool',
                '+    {',
                '+        return in_array($scaleCode, [',
                '+            ReportAccess::SCALE_BIG5_OCEAN,',
                '+            ReportAccess::SCALE_EQ_60,',
                '+            ReportAccess::SCALE_ENNEAGRAM,',
                '+            ReportAccess::SCALE_RIASEC,',
                '+        ], true);',
                '+    }',
                '+',
            ],
            'backend/database/seeders/CiScalesRegistrySeeder.php' => $changedLines === [
                "+                    'paywall_mode' => 'free_only',",
            ],
            'backend/database/seeders/ScaleRegistrySeeder.php' => $changedLines === [
                "+                'paywall_mode' => 'free_only',",
                "-                'free_sections' => ['intro', 'summary'],",
                "-                'blur_others' => true,",
                "-                'teaser_percent' => 0.35,",
                "-                'upgrade_sku' => 'SKU_EQ_60_FULL_299',",
                "+                'free_sections' => [",
                "+                    'disclaimer_top',",
                "+                    'quality_notice',",
                "+                    'global_overview',",
                "+                    'self_awareness',",
                "+                    'emotion_regulation',",
                "+                    'empathy',",
                "+                    'relationship_management',",
                "+                    'cross_quadrant_insight',",
                "+                    'action_plan_14d',",
                "+                    'methodology',",
                "+                    'disclaimer_bottom',",
                '+                ],',
                "+                'blur_others' => false,",
                "+                'teaser_percent' => 0.0,",
                "+                'upgrade_sku' => null,",
                '-                questions: 50,',
                '+                questions: 60,',
            ],
            default => false,
        };
    }

    private function isEq60V5ReportAssetLayerChange(string $file): bool
    {
        if (in_array($file, [
            'backend/app/Services/Content/Eq60ContentCompileService.php',
            'backend/app/Services/Content/Eq60ContentLintService.php',
            'backend/app/Services/Content/Eq60PackLoader.php',
            'backend/app/Services/Eq/EqCrossAssessmentContextGuard.php',
            'backend/app/Services/Report/Eq60ReportComposer.php',
            'backend/database/seeders/CiScalesRegistrySeeder.php',
            'backend/database/seeders/Pr19CommerceSeeder.php',
            'backend/database/seeders/ScaleRegistrySeeder.php',
        ], true)) {
            return true;
        }

        if (preg_match('#^backend/content_packs/(EQ_60|EQ_EMOTIONAL_INTELLIGENCE)/v1/raw/report_assets/[a-z_]+\.json$#', $file) === 1) {
            return true;
        }

        if (preg_match('#^backend/content_packs/(EQ_60|EQ_EMOTIONAL_INTELLIGENCE)/v1/raw/personalization_routes/[a-z_]+\.json$#', $file) === 1) {
            return true;
        }

        if (preg_match('#^backend/content_packs/(EQ_60|EQ_EMOTIONAL_INTELLIGENCE)/v1/compiled/report_assets\.compiled\.json$#', $file) === 1) {
            return true;
        }

        return in_array($file, [
            'backend/content_packs/EQ_60/v1/compiled/golden_cases.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/landing.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/manifest.json',
            'backend/content_packs/EQ_60/v1/compiled/options.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/policy.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/questions.compiled.json',
            'backend/content_packs/EQ_60/v1/compiled/report.compiled.json',
            'backend/content_packs/EQ_60/v1/raw/blocks/free_blocks.json',
            'backend/content_packs/EQ_60/v1/raw/blocks/paid_blocks.json',
            'backend/content_packs/EQ_60/v1/raw/golden_cases.csv',
            'backend/content_packs/EQ_60/v1/raw/report_layout.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/golden_cases.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/landing.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/manifest.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/options.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/policy.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/questions.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/report.compiled.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/blocks/free_blocks.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/blocks/paid_blocks.json',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/golden_cases.csv',
            'backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_layout.json',
        ], true);
    }

    private function isEqSjt16ContentPackSkeletonChange(string $file): bool
    {
        return in_array($file, [
            'backend/content_packs/EQ_SJT_16/v1/compiled/manifest.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/domains.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/item_schema.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/module_contract.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/scoring_rubric_draft.json',
        ], true);
    }

    private function isEqSjt16ScorerReadyChange(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Assessment/Scorers/EqSjt16Scorer.php',
            'backend/content_packs/EQ_SJT_16/v1/raw/golden_cases.json',
            'backend/content_packs/EQ_SJT_16/v1/raw/items.json',
        ], true);
    }

    private function isEqIntegratedReportComposerDraftChange(string $file): bool
    {
        return $file === 'backend/app/Services/Report/EqIntegratedReportComposer.php';
    }

    private function isEqSjtValidationTelemetryContractChange(string $file): bool
    {
        return $file === 'backend/app/Services/Eq/EqSjtValidationTelemetryContract.php';
    }

    private function isEq60JourneyStateContractChange(string $file): bool
    {
        return in_array($file, [
            'backend/app/Models/EqJourneyState.php',
            'backend/app/Services/Eq/EqJourneyStateService.php',
            'backend/database/migrations/2026_05_31_083300_create_eq_journey_states_table.php',
        ], true);
    }

    private function isCiAuthBypassHardeningFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/AuthWxPhoneController.php',
            'backend/app/Services/Auth/PhoneOtpService.php',
        ], true);
    }

    private function isCareerDisplaySurfaceFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Cms/CareerJobController.php',
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
            'backend/app/Http/Controllers/API/V0_5/Career/CareerCnProxyPublicOwnerController.php',
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
            'backend/app/Services/Career/Bundles/CareerAliasResolutionBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerCnProxyPublicOwnerSurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerLocaleIntegrityGate.php',
            'backend/app/Services/Career/Bundles/CareerRecommendationDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerRuntimePublishedDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
            'backend/app/Http/Resources/Career/CareerJobDetailResource.php',
            'backend/app/Services/Cms/CareerJobSeoService.php',
        ], true);
    }

    private function isCareerPolicyClaimGuardFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Scoring/ClaimPermissionsCompiler.php',
            'backend/app/Domain/Career/Scoring/ClaimReasonCode.php',
            'backend/app/Domain/Career/Scoring/WarningMatrix.php',
        ], true);
    }

    private function isCareerRuntimeProjectionConsumerFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobListController.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveDiscoverabilityManifestService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveLaunchTierSummaryService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveLifecycleSummaryService.php',
            'backend/app/Domain/Career/Publish/CareerFirstWaveNextStepLinksService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionLookup.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionVisibility.php',
            'backend/app/Domain/Career/Publish/FirstWavePublishReadyValidator.php',
            'backend/app/Domain/Career/Publish/FirstWaveReadinessSummaryService.php',
            'backend/app/Providers/AppServiceProvider.php',
            'backend/app/Services/Career/Bundles/CareerJobListBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerSearchBundleBuilder.php',
            'backend/app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php',
        ], true);
    }

    private function isCareerDirectoryAuthorityApiFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/Career/CareerDirectoryController.php',
            'backend/app/Services/Career/CareerDirectoryAuthorityService.php',
        ], true);
    }

    private function isCareerPublicDistributionFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
            'backend/app/Services/Career/PublicCareerAuthorityResponseCache.php',
            'backend/app/Services/SEO/SeoDiscoverabilityCacheInvalidator.php',
            'backend/app/Services/SEO/SitemapGenerator.php',
            'backend/app/Services/SEO/SitemapCache.php',
        ], true);
    }

    private function isCareerRuntimePublishProjectionFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Expansion/CanonicalBatchPromotionExecutorService.php',
            'backend/app/Domain/Career/Publish/CareerFullReleaseLedgerService.php',
            'backend/app/Domain/Career/Expansion/CanonicalExpansionManifestDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalExpansionManifestExporter.php',
            'backend/app/Domain/Career/Expansion/CanonicalExpansionManifestService.php',
            'backend/app/Domain/Career/Expansion/CanonicalExpansionManifestValidator.php',
            'backend/app/Domain/Career/Expansion/CanonicalBatchPromotionService.php',
            'backend/app/Domain/Career/Expansion/CanonicalBatchCloseoutResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPostPromotionReleaseGateService.php',
            'backend/app/Domain/Career/Expansion/CanonicalPostPromotionReleaseGateValidator.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionRollbackGate.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionRollbackResultDTO.php',
            'backend/app/Domain/Career/Expansion/CanonicalPromotionTransaction.php',
            'backend/app/Domain/Career/Expansion/CanonicalRolloutBatchStateMachine.php',
            'backend/app/Domain/Career/Expansion/CanonicalRolloutGovernanceValidator.php',
            'backend/app/Domain/Career/Publish/CareerFullReleaseLedgerProjectionService.php',
            'backend/app/Domain/Career/Publish/FirstWaveBlockedGovernancePolicy.php',
            'backend/app/Domain/Career/Publish/CareerRolloutReportAuthoritySigner.php',
            'backend/app/Domain/Career/Publish/CareerVerifiedRolloutBatchSlugAuthority.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionDTO.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionExporter.php',
            'backend/app/Domain/Career/Publish/CareerPublicTrustTaxonomyExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthValidator.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionValidator.php',
            'backend/app/Services/Career/CareerFirstWaveReleaseArtifactMaterializationService.php',
            'backend/app/Services/Career/CareerFirstWaveRolloutBundleArtifactMaterializationService.php',
            'backend/app/Services/Career/CareerFirstWaveRolloutWavePlanArtifactMaterializationService.php',
        ], true);
    }

    private function isCareerCanonicalEligibilityAuditSchemaFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContext.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextApprovalGate.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextRequirement.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRunContextStatus.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityCheckProtocol.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityLayer.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityLayerStatus.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityReport.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityScope.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilitySeverity.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilitySidecar.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityStatus.php',
        ], true);
    }

    private function isCareerPublicResolutionPlanResolverFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlan.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanIssue.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanResolver.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanRow.php',
            'backend/app/Domain/Career/Audit/CareerPublicResolutionPlanValidationResult.php',
        ], true);
    }

    private function isCareerOccupationEntityInventoryAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryAuditor.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryIssue.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryResult.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityInventoryRow.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityRemediationPlan.php',
            'backend/app/Domain/Career/Audit/CareerOccupationEntityRemediationPlanRow.php',
        ], true);
    }

    private function isCareerBaselineMetadataInventoryAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryAuditor.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryIssue.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryResult.php',
            'backend/app/Domain/Career/Audit/CareerBaselineMetadataInventoryRow.php',
        ], true);
    }

    private function isCareerIndexStateAuthorityAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityAuditor.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityIssue.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityResult.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateAuthorityRow.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateRemediationPlan.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateRemediationPlanRow.php',
        ], true);
    }

    private function isCareerEntityIndexContextArtifactFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerEntityContextArtifactRow.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerIndexStateContextArtifactRow.php',
        ], true);
    }

    private function isCareerRuntimeProjectionTruthAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityAuditor.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityIssue.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeProjectionTruthEligibilityRow.php',
        ], true);
    }

    private function isCareerSeoGeoReadinessAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessAuditor.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerSeoGeoReadinessRow.php',
        ], true);
    }

    private function isCareerSurfaceReadinessAuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessAuditor.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceReadinessRow.php',
        ], true);
    }

    private function isCareerSurfaceContextArtifactFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifact.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactIssue.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactReader.php',
            'backend/app/Domain/Career/Audit/CareerSurfaceContextArtifactRow.php',
        ], true);
    }

    private function isCareer80CohortReadinessPlanFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/Career80TargetDeltaPlanner.php',
            'backend/app/Domain/Career/Audit/Career80TargetDeltaResult.php',
            'backend/app/Domain/Career/Audit/CareerFullVisiblePublicationGate.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortDeltaPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortDeltaPlanResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidateAwareArtifactRefresh.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeArtifactRefreshPlanner.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeArtifactRefreshResult.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidatePrepPlanner.php',
            'backend/app/Domain/Career/Audit/CareerRuntimeCandidatePrepResult.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutManifestPlanner.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutManifestResult.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutGatePlanner.php',
            'backend/app/Domain/Career/Audit/CareerDeltaRolloutGateResult.php',
            'backend/app/Domain/Career/Audit/Career80TotalLiveAcceptancePlanner.php',
            'backend/app/Domain/Career/Audit/Career80TotalLiveAcceptanceResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortCloseoutPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveCohortCloseoutResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveLiveVerificationScalingPlanner.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveLiveVerificationScalingResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessCandidate.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelection.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelectionIssue.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelectionResult.php',
            'backend/app/Domain/Career/Audit/CareerProgressiveReadinessSelector.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessPlanner.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelectionReport.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelectionRow.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CandidateSelector.php',
            'backend/app/Domain/Career/Audit/Career80RolloutCandidateGate.php',
            'backend/app/Domain/Career/Audit/Career2786PublicResolutionPartitionPlanner.php',
            'backend/app/Domain/Career/Audit/Career2786PublicResolutionPartitionResult.php',
        ], true);
    }

    private function isCareer2786ReadinessPolicyFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyClassifier.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyIssue.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyResult.php',
            'backend/app/Domain/Career/Audit/Career2786ReadinessPolicyRow.php',
        ], true);
    }

    private function isCareerExpansionManifestTrainFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainBatch.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainGenerator.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonicalExpansionManifestTrainResult.php',
        ], true);
    }

    private function isCareerBatchLiveAcceptanceV2File(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Auditor.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Issue.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Result.php',
            'backend/app/Domain/Career/Audit/CareerBatchLiveAcceptanceV2Row.php',
        ], true);
    }

    private function isCareer2786FullAuditArtifactFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/Career2786FullAuditArtifact.php',
            'backend/app/Domain/Career/Audit/Career2786FullAuditArtifactBuilder.php',
        ], true);
    }

    private function isCareerDetailReady1048AuditFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerDetailReadyPublicationCandidateScanner.php',
            'backend/app/Domain/Career/Audit/CareerDetailReadyTargetAuthority.php',
        ], true);
    }

    private function isBigFiveV2PilotSupportFile(string $file): bool
    {
        return $file === 'backend/app/Services/BigFive/ResultPageV2/BigFiveResultPageV2RuntimeWrapper.php'
            || $file === 'backend/app/Services/BigFive/ResultPageV2/BigFiveV2PilotRuntimeObservability.php'
            || preg_match('#^backend/app/Services/BigFive/ResultPageV2/(ContentAssets|RouteMatrix|Selector|Composer|Access|Routing|Pdf|Share|History|Compare|Rollout|Observability)/[A-Za-z0-9_]+\.php$#', $file) === 1;
    }

    private function isBigFiveV2EnParityDraftCatalogFile(string $file): bool
    {
        return $file === 'backend/content_packs/BIG5_OCEAN/v2/drafts/en_parity/result_page_v2_en_asset_catalog_draft.v1.json';
    }

    private function isBigFiveNormFoundationSchemaFile(string $file): bool
    {
        return $file === 'backend/app/Models/BigFiveNormObservation.php'
            || preg_match('#^backend/database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_create_big_five_norm_observations_table\.php$#', $file) === 1;
    }

    private function isBigFiveNormFoundationServiceFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/BigFive/Norms/BigFiveNormObservationCaptureWriter.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormCaptureDecision.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormCaptureResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAnonymizer.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormPrivacyPolicy.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAggregationDryRun.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormAggregationResult.php',
        ], true);
    }

    private function isBigFiveDynamicNormEngineInternalFile(string $file): bool
    {
        $filename = basename($file);
        if (preg_match('#(Runtime|PublicPercentile|Presenter|Projection)#', $filename) === 1) {
            return false;
        }

        return in_array($file, [
            'backend/app/Services/BigFive/Norms/BigFiveNormSnapshot.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSnapshotBuilder.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRecomputeEngine.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormRecomputeResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSegmentedAggregator.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormSegmentedAggregationResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormDriftDetector.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormDriftResult.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormInternalPercentileResolver.php',
            'backend/app/Services/BigFive/Norms/BigFiveNormInternalPercentileDecision.php',
        ], true);
    }

    private function isBigFiveV2CmsEditorialGovernanceFile(string $file): bool
    {
        $filename = basename($file);
        if (preg_match('#(Runtime|Selector|Projection|ProductionRollout|DirectRuntime)#', $filename) === 1) {
            return false;
        }

        if ($file === 'backend/app/Filament/Ops/Support/BigFiveV2EditorialAssetIndexPresenter.php') {
            return true;
        }

        if (preg_match('#^backend/app/Models/BigFiveV2Editorial[A-Za-z0-9_]+\.php$#', $file) === 1) {
            return true;
        }

        if (preg_match('#^backend/app/Services/BigFive/Cms/BigFiveV2Editorial[A-Za-z0-9_]+\.php$#', $file) === 1) {
            return true;
        }

        if (preg_match('#^backend/app/Policies/BigFiveV2Editorial[A-Za-z0-9_]+\.php$#', $file) === 1) {
            return true;
        }

        return preg_match(
            '#^backend/database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_create_big_five_v2_editorial_[a-z0-9_]+_table\.php$#',
            $file
        ) === 1;
    }

    private function isAttemptEmailBindingFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/AttemptEmailBindingController.php',
            'backend/app/Http/Requests/V0_3/AttemptEmailBindingRequest.php',
            'backend/app/Models/AttemptEmailBinding.php',
            'backend/app/Services/Attempts/AttemptEmailBindingService.php',
            'backend/database/migrations/2026_05_05_000100_create_attempt_email_bindings_table.php',
        ], true);
    }

    private function isAttemptDataLifecycleErasureFile(string $file): bool
    {
        return $file === 'backend/app/Services/Attempts/AttemptDataLifecycleService.php';
    }

    private function isAttemptSubmissionReliabilityHardeningFile(string $file): bool
    {
        return $file === 'backend/app/Services/Attempts/AttemptSubmissionService.php';
    }

    private function isAttemptSubmissionQueueJitterFile(string $file): bool
    {
        return $file === 'backend/app/Jobs/ProcessAttemptSubmissionJob.php';
    }

    private function isCommerceTenantRepairCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/CommerceRepairPostCommitFailed.php';
    }

    private function isResultEmailLookupFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/ResultEmailLookupController.php',
            'backend/app/Http/Requests/V0_3/ResultEmailLookupRequest.php',
            'backend/app/Services/Results/ResultAccessTokenService.php',
            'backend/app/Services/Results/ResultEmailLookupService.php',
        ], true);
    }

    private function isResultEmailGatedReadFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_3/AttemptReadController.php',
            'backend/app/Services/Results/ResultEmailReadAccessService.php',
        ], true);
    }

    private function isResultEmailAccessLinkDeliveryFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Email/EmailOutboxService.php',
            'backend/app/Services/Email/EmailPreferenceService.php',
        ], true);
    }

    private function isAnalyticsFunnelEventTaxonomyFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Analytics/AnalyticsFunnelDailyBuilder.php',
            'backend/app/Services/Analytics/FunnelEventTaxonomy.php',
        ], true);
    }

    private function isAnalyticsFunnelOpsReadModelFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Ops/Pages/FunnelConversionPage.php',
            'backend/app/Filament/Ops/Widgets/FunnelWidget.php',
        ], true);
    }

    private function isPaymentUnlockAttributionDiagnosticsFile(string $file): bool
    {
        return $file === 'backend/app/Services/Analytics/PaymentUnlockAttributionDiagnostics.php';
    }

    private function isAnalyticsFunnelRefreshCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/RefreshAnalyticsFunnelDailyCommand.php';
    }

    private function isSeoConversionDailyReadModelFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/RefreshSeoConversionDailyCommand.php',
            'backend/app/Services/Analytics/SeoConversionDailyBuilder.php',
            'backend/database/migrations/2026_06_09_000100_create_analytics_seo_conversion_daily_table.php',
        ], true);
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function attributionControllerDiffIsSeoFunnelContractOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/^[+-]\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*[}\]);,]*\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*\{\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*\'(slug|scaleCode|scale_code|current_path|attempt_id|attemptIdMasked|target_attempt_id|answered_count|durationMs|duration_ms|duration_bucket|order_no|orderNo|orderNoMasked|order_id|transaction_id|amount|value|price|currency|provider|pack_version|manifest_hash|norms_version|quality_level|locked|variant|sku_id|utm_source|utm_medium|utm_campaign|utm_term|utm_content|gclid|msclkid|fbclid|referrer|session_id|submit_attempt|landing_pv|article_to_test_click|start_test|complete_test|view_result|url|lang|page_type|source_url|source_article|target_test|scale_id|form_id)\',\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*\'payload\.(slug|scaleCode|scale_code|current_path|attemptIdMasked|answered_count|durationMs|duration_ms|duration_bucket|order_no|orderNo|orderNoMasked|order_id|transaction_id|amount|value|price|currency|provider|pack_version|manifest_hash|norms_version|quality_level|locked|variant|sku_id|utm_source|utm_medium|utm_campaign|utm_term|utm_content|gclid|msclkid|fbclid|referrer|session_id|url|lang|page_type|source_url|source_article|target_test|scale_id|form_id)\'\s*=>\s*\[/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-]\s*\'(form_code|landing_path|locale)\' => /u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-]\s*(\$payload\[\'form_id\'\]|\$payload\[\'url\'\]|\$payload\[\'lang\'\]|\$payload\[\'scale_id\'\])\s*/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-]\s*(\$locale = \$this->normalizeOptionalString\(|\?\? \$payload\[\'scale_id\'\]|\?\? \(\$path !== \'\' && str_starts_with\(\$path, \'\/zh\'\) \? \'zh\' : \'en\'\),)/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^-\s*\'payload\'\s*=>\s*\[\'nullable\',\s*\'array:\'\.implode\(.*, self::PAYLOAD_KEYS\)\],\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*\'payload\'\s*=>\s*\[\'nullable\',\s*\'array\'\],\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*(\$payloadInput = \$request->input\(\'payload\'\);|if \(\$payloadInput !== null && ! is_array\(\$payloadInput\)\) \{|throw ValidationException::withMessages\(\[|\'payload\' => \'The payload field must be an array\.\',|if \(is_array\(\$payloadInput\)\) \{|\$this->rejectUnexpectedKeys\(\$payloadInput, self::PAYLOAD_KEYS, \'payload\'\);)\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-]\s*(\$scaleCode = \$this->normalizeOptionalString\(|\$payload\[\'scale_code\'\]|\?\? \$payload\[\'scaleCode\'\]|\?\? null,|64|\) \?\? \'MBTI\';|\'scale_code\' => (?:\'MBTI\'|\$scaleCode),|\$attributes\[\'scale_code_v2\'\] = (?:\'MBTI\'|\$scaleCode);)\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+]\s*(\/\*\*|\* @var list<string>|\* @param  array<string, mixed>  \$payload|\* @return array<string, mixed>|\*\/|private const SEO_|private function (isSeoConversionEvent|sanitizeSeoConversionPayload|sanitizeSeoPublicUrl|isPrivateAnalyticsPath)\b|\$isSeoConversionEvent =|if \(\$isSeoConversionEvent\)|\$path = \$this->sanitizeSeoPublicUrl|\$payload = \$this->sanitizeSeoConversionPayload|\$meta\[\'seo_conversion\'\]|\'seo_conversion\' =>|\'(event_name|url|lang|page_type|source_url|source_article|target_test|scale_id|form_id|session_id|referrer)\' =>|\'payload\.|\$field =>|foreach \(|if \(|return |throw ValidationException::withMessages|\$sessionId =|\$targetTest =|\$url =|\$normalized =|\$parts =|\$path =|\$scheme =|\$host =|\$payload\[|preg_match\()/u', $line) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsSeoAttributionIngestOnly(array $changedLines): bool
    {
        $allowed = [
            "+    Route::post('/seo/attribution/events', [MbtiAttributionEventController::class, 'store'])",
            '+        ->middleware([',
            '+            \\App\\Http\\Middleware\\LimitApiPublicPayloadSize::class,',
            "+            'throttle:api_track',",
            '+        ])',
            "+        ->name('api.v0_5.seo.attribution_events.store');",
        ];

        return $changedLines !== [] && array_values($changedLines) === $allowed;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsEmailAccessTrainOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/^\+\s*[\[\]{}(),;>\-]*\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/AttemptEmailBindingController|email-bind|api\.v0_3\.attempts\.email_bind|FmTokenAuth|uuid:id|public_realm|ResultEmailLookupController|lookup-by-email|api_result_lookup|api\.v0_3\.results\.lookup_by_email/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function appServiceProviderDiffIsResultLookupRateLimiterOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/^\+\s*[{});]*\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/api_result_lookup|api_result_lookup_per_minute|RATE_LIMIT_RESULT_LOOKUP|Too many result lookup requests|RateLimiter::for|Limit::none|Limit::perMinute|max\\(1, \\$limit\\)|scopedRateKey|shouldBypassRateLimits|response/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsCareerOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/\bCareer[A-Za-z0-9_\\\\]*\b|career:/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsArticleEditorialPackageDraftGateOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/\bArticleImportEditorialPackage\b/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsControlledArticlePublishSopOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/\bArticlePublishControlled\b/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsArticleCoverPropagationSmokeOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/\bArticleCoverPropagationSmoke\b/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsAlipayPendingCompensationSchedulerOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/commerce:compensate-pending-orders|--provider=alipay|--include-created|--only-stale|--limit=10|--older-than-minutes=60|everyTenMinutes|withoutOverlapping/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsIqNormImportDryRunOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            $normalized = ltrim($line, '+-');
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $normalized) === 1) {
                return false;
            }

            if (preg_match('/\bNormsIqImport\b/u', $normalized) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsResearchBackendMvpOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/ResearchReportController|\/research|\/internal\/research-reports/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsCareerCnProxyPublicOwnerSurfaceOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/CareerCnProxyPublicOwnerController|\\/career\\/cn-proxy\\/\\{slug\\}/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsCareerPublicDistributionOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/^[+-]\\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-].*(SitemapSourceController|\\/seo\\/sitemap-source|seo\\.sitemap-source)/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsCareerDirectoryAuthorityOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        $allowedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Career\\CareerDirectoryController;',
            "+    Route::get('/career/directory', [CareerDirectoryController::class, 'index']);",
        ];

        foreach ($changedLines as $line) {
            if (! in_array($line, $allowedLines, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsDailyGivingLedgerOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        $allowedLines = [
            '+use App\\Http\\Controllers\\API\\V0_5\\Foundation\\DailyGivingRecordController;',
            '+    Route::get(\'/foundation/giving-records/months\', [DailyGivingRecordController::class, \'months\']);',
            '+    Route::get(\'/foundation/giving-records/months/{yearMonth}\', [DailyGivingRecordController::class, \'monthRecords\']);',
            '+    Route::get(\'/foundation/giving-records\', [DailyGivingRecordController::class, \'index\']);',
            '+    Route::get(\'/foundation/giving-records/{recordCode}\', [DailyGivingRecordController::class, \'show\']);',
        ];

        foreach ($changedLines as $line) {
            if (! in_array($line, $allowedLines, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsSeoDashApiReadOnlyContractOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/^\+\s*[\[\]{}(),;>\-]*\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^\+.*(SeoIntelDashboardController|EnsureSeoIntelReadAuthorized|ops\\/seo-intel|api\\.v0_5\\.ops\\.seo_intel|overview|urlTruth|url-truth|issues|trends|pagePerformance|page-performance|cmsAdminMiddleware|Route::prefix|Route::get|middleware|group|name)/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsEq60JourneyStateContractOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/^\+\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/eq\\/journey|eqJourney|submitEqJourney|api\\.v0_3\\.attempts\\.eq\\.journey|FmTokenAuth|uuid:id|public_realm/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsPublicHealthzAliasOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/^[+-]\s*[\\[\\]{}(),;]*\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^[+-].*(HealthzController|HealthzAccessControl|throttle:api_public|\\/healthz|healthz\\.public|withoutMiddleware|EncryptCookies|AddQueuedCookiesToResponse|StartSession|ShareErrorsFromSession|VerifyCsrfToken)/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsApiRootServiceLandingOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        $allowedLines = [
            'if ($requestHost === \'api.fermatmind.com\' || $requestHost === \'staging-api.fermatmind.com\') {',
            'return response()->json([',
            '\'ok\' => true,',
            '\'service\' => \'FermatMind API\',',
            '\'message\' => \'API root is online. Use versioned /api routes for application traffic.\',',
            '\'healthz\' => \'restricted\',',
            ']);',
            '}',
        ];

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (! str_starts_with($line, '+')) {
                return false;
            }

            $changedBody = trim(substr($line, 1));
            if ($changedBody === '') {
                continue;
            }

            if (! in_array($changedBody, $allowedLines, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsArticleDraftPreviewOpsOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        $allowedLines = [
            'use App\Http\Controllers\Ops\ArticleDraftPreviewController;',
            'use App\Http\Middleware\AdminAuth;',
            'use App\Http\Middleware\EnsureAdminTotpVerified;',
            'use App\Http\Middleware\EnsureCmsAdminAuthorized;',
            'use App\Http\Middleware\OpsAccessControl;',
            'use App\Http\Middleware\RequireOpsOrgSelected;',
            'use App\Http\Middleware\ResolveOrgContext;',
            'use App\Http\Middleware\SetOpsRequestContext;',
            'Route::get(\'/ops/article-preview/{article}\', ArticleDraftPreviewController::class)',
            '->middleware([',
            'SetOpsRequestContext::class,',
            'AdminAuth::class,',
            'ResolveOrgContext::class,',
            'EnsureAdminTotpVerified::class,',
            'RequireOpsOrgSelected::class,',
            'OpsAccessControl::class,',
            'EnsureCmsAdminAuthorized::class.\':read\',',
            '])',
            '->whereNumber(\'article\')',
            '->name(\'ops.articles.preview\');',
        ];

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (! str_starts_with($line, '+')) {
                return false;
            }

            $changedBody = trim(substr($line, 1));
            if ($changedBody === '') {
                continue;
            }

            if (! in_array($changedBody, $allowedLines, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function routeDiffIsCiAuthBypassHardeningOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/^[+-]\s*if \(app\(\)->environment\(\[\'local\', \'testing\'(?:, \'ci\')?\]\)\) \{/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function scaleRegistrySeederDiffIsIqIdentityMetadataOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/^\s*[+-]\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/default_dir_version|IQ-RAVEN-CN-v0\.3\.0-DEMO|IQ_INTELLIGENCE_QUOTIENT-CN-v0\.3\.0-DEMO|questions:\s*(30|60)|minutes:\s*(12|20)|ScaleRegistrySeeder:\s+IQ_RAVEN scale upserted\.|ScaleRegistrySeeder:\s+IQ public slug scale upserted with IQ_RAVEN legacy identity and IQ_INTELLIGENCE_QUOTIENT canonical pack metadata\./u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function articleControllerDiffIsPublicArticleRecencyOrderingOnly(array $changedLines): bool
    {
        return $changedLines === [
            "+            ->orderByDesc('published_at')",
            "-            ->orderByDesc('published_at')",
        ];
    }

    private function isBackendPintBaselineStyleOnlyChange(string $file, string $repoRoot, string $baseRef): bool
    {
        if ($repoRoot === '' || $baseRef === '') {
            return false;
        }

        $expectedLines = $this->backendPintBaselineStyleOnlyChangedLines()[$file] ?? null;
        if ($expectedLines === null) {
            return false;
        }

        return $this->changedLinesForFile($repoRoot, $baseRef, $file) === $expectedLines;
    }

    /**
     * @return array<string,list<string>>
     */
    private function backendPintBaselineStyleOnlyChangedLines(): array
    {
        return [
            'backend/app/Domain/Career/Publish/CareerFirstWaveLaunchManifestService.php' => [
                '+use App\\DTO\\Career\\CareerFirstWaveIndexPolicyMember;',
                '-use App\\DTO\\Career\\CareerFirstWaveIndexPolicyMember;',
                '-    ): string',
                '-    {',
                '+    ): string {',
            ],
            'backend/app/Models/CareerFeedbackRecord.php' => [
                '-',
            ],
            'backend/app/Models/ContentPage.php' => [
                '-use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
                '+use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
            ],
            'backend/app/Models/InterpretationGuide.php' => [
                '-use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
                '+use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
            ],
            'backend/app/Models/SupportArticle.php' => [
                '-use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
                '+use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;',
            ],
            'backend/app/Services/Analytics/MbtiAttributionFunnelDailyBuilder.php' => [
                '+',
            ],
            'backend/app/Services/Assessment/Scorers/BigFiveScorerV3.php' => [
                '-     * @param  int  $expectedQuestionCount',
            ],
            'backend/app/Services/Attempts/AttemptInviteUnlockService.php' => [
                '-use App\\Services\\Commerce\\EntitlementManager;',
                '+use App\\Services\\Commerce\\EntitlementManager;',
            ],
            'backend/app/Services/Content/ContentPackV2Resolver.php' => [
                '+use Illuminate\\Database\\QueryException;',
                '-use Illuminate\\Database\\QueryException;',
            ],
            'backend/app/Services/Mbti/MbtiFormCatalog.php' => [
                '-    ): array',
                '-    {',
                '+    ): array {',
            ],
            'backend/app/Services/Mbti/MbtiPublicFormSummaryBuilder.php' => [
                '-use Illuminate\\Support\\Facades\\DB;',
            ],
            'backend/app/Support/SchemaBaseline.php' => [
                '+',
                '+',
                '+',
                '+',
                '+',
            ],
            'backend/routes/console.php' => [
                "-})->purpose('Display an inspiring quote');",
                "+})->purpose('Display an inspiring quote');",
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function changedLinesForFile(string $repoRoot, string $baseRef, string $file): array
    {
        $command = [
            'git',
            '-C',
            $repoRoot,
            'diff',
            '--unified=0',
            "{$baseRef}...HEAD",
            '--',
            $file,
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                    return null;
                }

                if (preg_match('/^[+-]/', $line) !== 1) {
                    return null;
                }

                return $line;
            },
            $output,
        )));
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function contentPacksIndexDiffIsStreamingScanOnly(array $changedLines): bool
    {
        $legacyStreamingLines = [
            '-            if (! is_array($this->readJsonFile($questionsPath))) {',
            '+            if (! $this->isValidJsonArrayDocument($questionsPath)) {',
            '+    private function isValidJsonArrayDocument(string $path): bool',
            '+    {',
            '+        if ($path === \'\' || ! File::isFile($path)) {',
            '+            return false;',
            '+        }',
            '+',
            '+        try {',
            '+            $raw = File::get($path);',
            '+        } catch (\\Throwable $e) {',
            '+            return false;',
            '+        }',
            '+',
            '+        if (! is_string($raw) || trim($raw) === \'\') {',
            '+            return false;',
            '+        }',
            '+',
            '+        $trimmed = ltrim($raw);',
            '+        if ($trimmed === \'\' || $trimmed[0] !== \'[\') {',
            '+            return false;',
            '+        }',
            '+',
            '+        return json_validate($raw);',
            '+    }',
            '+',
        ];

        $streamingScanLines = [
            '+use RecursiveDirectoryIterator;',
            '+use RecursiveIteratorIterator;',
            '+use SplFileInfo;',
            '-        $items = $this->scanItems($packsRootFs);',
            '+        $scanned = $this->scanIndex($packsRootFs, $defaults);',
            '+        $items = (array) ($scanned[\'items\'] ?? []);',
            '-        $byPackId = $this->buildByPackId($items, $defaults);',
            '-            \'by_pack_id\' => $byPackId,',
            '+            \'by_pack_id\' => (array) ($scanned[\'by_pack_id\'] ?? []),',
            '-    private function scanItems(string $packsRootFs): array',
            '+    private function scanIndex(string $packsRootFs, array $defaults): array',
            '+        $byPackId = [];',
            '+        $latest = [];',
            '-        foreach (File::allFiles($packsRootFs) as $file) {',
            '-            if ($file->getFilename() !== \'manifest.json\') {',
            '-                continue;',
            '-            }',
            '-',
            '+        foreach ($this->manifestFilesUnder($packsRootFs) as $file) {',
            '-            if (! is_array($this->readJsonFile($questionsPath))) {',
            '+            if (! $this->isValidJsonArrayDocument($questionsPath)) {',
            '+            $this->recordByPackIdVersion(',
            '+                $byPackId,',
            '+                $latest,',
            '+                $packId,',
            '+                $dirVersion,',
            '+                $updatedAt',
            '+            );',
            '-        return $items;',
            '+        return [',
            '+            \'items\' => $items,',
            '+            \'by_pack_id\' => $this->finalizeByPackId($byPackId, $latest, $defaults),',
            '+        ];',
            '-    private function buildByPackId(array $items, array $defaults): array',
            '-    {',
            '-        $byPackId = [];',
            '-        $latest = [];',
            '-        $defaultPackId = (string) ($defaults[\'default_pack_id\'] ?? \'\');',
            '-        $defaultDirVersion = (string) ($defaults[\'default_dir_version\'] ?? \'\');',
            '-',
            '-        foreach ($items as $item) {',
            '-            $packId = (string) ($item[\'pack_id\'] ?? \'\');',
            '-            $dirVersion = (string) ($item[\'dir_version\'] ?? \'\');',
            '-            if ($packId === \'\' || $dirVersion === \'\') {',
            '-                continue;',
            '-            }',
            '+    private function recordByPackIdVersion(',
            '+        array &$byPackId,',
            '+        array &$latest,',
            '+        string $packId,',
            '+        string $dirVersion,',
            '+        int $updatedAt',
            '+    ): void {',
            '+        if ($packId === \'\' || $dirVersion === \'\') {',
            '+            return;',
            '+        }',
            '-            if (! isset($byPackId[$packId])) {',
            '-                $byPackId[$packId] = [',
            '-                    \'default_dir_version\' => \'\',',
            '-                    \'versions\' => [],',
            '-                ];',
            '-            }',
            '+        if (! isset($byPackId[$packId])) {',
            '+            $byPackId[$packId] = [',
            '+                \'default_dir_version\' => \'\',',
            '+                \'versions\' => [],',
            '+            ];',
            '+        }',
            '-            $byPackId[$packId][\'versions\'][] = $dirVersion;',
            '+        $byPackId[$packId][\'versions\'][$dirVersion] = true;',
            '-            $updatedAt = (int) ($item[\'updated_at\'] ?? 0);',
            '-            if (! isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId][\'updated_at\'] ?? 0)) {',
            '-                $latest[$packId] = [',
            '-                    \'dir_version\' => $dirVersion,',
            '-                    \'updated_at\' => $updatedAt,',
            '-                ];',
            '-            }',
            '+        if (! isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId][\'updated_at\'] ?? 0)) {',
            '+            $latest[$packId] = [',
            '+                \'dir_version\' => $dirVersion,',
            '+                \'updated_at\' => $updatedAt,',
            '+            ];',
            '+        }',
            '+    }',
            '+',
            '+    private function finalizeByPackId(array $byPackId, array $latest, array $defaults): array',
            '+    {',
            '+        $defaultPackId = (string) ($defaults[\'default_pack_id\'] ?? \'\');',
            '+        $defaultDirVersion = (string) ($defaults[\'default_dir_version\'] ?? \'\');',
            '-            $versions = array_values(array_unique($info[\'versions\'] ?? []));',
            '+            $versions = array_keys((array) ($info[\'versions\'] ?? []));',
            '+    /**',
            '+     * @return \\Generator<int, SplFileInfo>',
            '+     */',
            '+    private function manifestFilesUnder(string $packsRootFs): \\Generator',
            '+    {',
            '+        $iterator = new RecursiveIteratorIterator(',
            '+            new RecursiveDirectoryIterator($packsRootFs, RecursiveDirectoryIterator::SKIP_DOTS),',
            '+            RecursiveIteratorIterator::LEAVES_ONLY',
            '+        );',
            '+',
            '+        foreach ($iterator as $file) {',
            '+            if (! $file instanceof SplFileInfo || ! $file->isFile()) {',
            '+                continue;',
            '+            }',
            '+',
            '+            if ($file->getFilename() !== \'manifest.json\') {',
            '+                continue;',
            '+            }',
            '+',
            '+            yield $file;',
            '+        }',
            '+    }',
            '+',
            '+    private function isValidJsonArrayDocument(string $path): bool',
            '+    {',
            '+        if ($path === \'\' || ! File::isFile($path)) {',
            '+            return false;',
            '+        }',
            '+',
            '+        $firstMeaningfulByte = $this->firstNonWhitespaceByte($path);',
            '+        if ($firstMeaningfulByte === null) {',
            '+            return false;',
            '+        }',
            '+',
            '+        return $firstMeaningfulByte === \'[\' || $firstMeaningfulByte === \'{\';',
            '+    }',
            '+',
            '+    private function firstNonWhitespaceByte(string $path): ?string',
            '+    {',
            '+        $handle = @fopen($path, \'rb\');',
            '+        if (! is_resource($handle)) {',
            '+            return null;',
            '+        }',
            '+',
            '+        try {',
            '+            while (! feof($handle)) {',
            '+                $chunk = fread($handle, 8192);',
            '+                if (! is_string($chunk) || $chunk === \'\') {',
            '+                    continue;',
            '+                }',
            '+',
            '+                $length = strlen($chunk);',
            '+                for ($index = 0; $index < $length; $index++) {',
            '+                    $char = $chunk[$index];',
            '+                    if (! ctype_space($char)) {',
            '+                        return $char;',
            '+                    }',
            '+                }',
            '+            }',
            '+        } finally {',
            '+            fclose($handle);',
            '+        }',
            '+',
            '+        return null;',
            '+    }',
        ];

        $normalizedChanged = $this->normalizeContentPacksIndexStreamingDiffLines($changedLines);
        if ($normalizedChanged === []) {
            return false;
        }

        $normalizedLegacy = $this->normalizeContentPacksIndexStreamingDiffLines($legacyStreamingLines);
        $normalizedStreaming = $this->normalizeContentPacksIndexStreamingDiffLines($streamingScanLines);

        if ($normalizedChanged === $normalizedLegacy || $normalizedChanged === $normalizedStreaming) {
            return true;
        }

        $allowed = array_fill_keys(array_merge($normalizedLegacy, $normalizedStreaming), true);
        foreach ($normalizedChanged as $line) {
            if (! isset($allowed[$line])) {
                return false;
            }
        }

        $diffText = implode("\n", $normalizedChanged);
        foreach ([
            'RecursiveDirectoryIterator',
            'RecursiveIteratorIterator',
            'scanIndex($packsRootFs, $defaults)',
            'manifestFilesUnder($packsRootFs)',
            'recordByPackIdVersion(',
            'finalizeByPackId(',
            'isValidJsonArrayDocument($questionsPath)',
            'firstNonWhitespaceByte($path)',
        ] as $requiredMarker) {
            if (! str_contains($diffText, $requiredMarker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function normalizeContentPacksIndexStreamingDiffLines(array $lines): array
    {
        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (! preg_match('/^[+-]/', $line)) {
                    return null;
                }

                $body = substr($line, 1);
                $trimmed = trim($body);
                if ($trimmed === '') {
                    return null;
                }

                if (in_array($trimmed, ['{', '}', '];'], true)) {
                    return null;
                }

                if (
                    str_starts_with($trimmed, '/**')
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '*/')
                ) {
                    return null;
                }

                return $line;
            },
            $lines,
        )));
    }

    /**
     * @return list<string>
     */
    private function kernelChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        $command = [
            'git',
            '-C',
            $repoRoot,
            'diff',
            '--unified=0',
            "{$baseRef}...HEAD",
            '--',
            'backend/app/Console/Kernel.php',
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                    return null;
                }

                if (! str_starts_with($line, '+') && ! str_starts_with($line, '-')) {
                    return null;
                }

                return substr($line, 1);
            },
            $output,
        )));
    }

    /**
     * @return list<string>
     */
    private function routeChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        $command = [
            'git',
            '-C',
            $repoRoot,
            'diff',
            '--unified=0',
            "{$baseRef}...HEAD",
            '--',
            'backend/routes/api.php',
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                    return null;
                }

                if (preg_match('/^[+-]/', $line) !== 1) {
                    return null;
                }

                return $line;
            },
            $output,
        )));
    }

    /**
     * @return list<string>
     */
    private function webRouteChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        return $this->changedLinesForFile($repoRoot, $baseRef, 'backend/routes/web.php');
    }

    /**
     * @return list<string>
     */
    private function appServiceProviderChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        $command = [
            'git',
            '-C',
            $repoRoot,
            'diff',
            '--unified=0',
            "{$baseRef}...HEAD",
            '--',
            'backend/app/Providers/AppServiceProvider.php',
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                    return null;
                }

                if (preg_match('/^[+-]/', $line) !== 1) {
                    return null;
                }

                return $line;
            },
            $output,
        )));
    }

    /**
     * @return list<string>
     */
    private function scaleRegistrySeederChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        return $this->changedLinesForFile($repoRoot, $baseRef, 'backend/database/seeders/ScaleRegistrySeeder.php');
    }

    private function mergeBaseWithMain(string $repoRoot): string
    {
        $gitPrefix = 'git -C '.escapeshellarg($repoRoot).' ';
        $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
        if ($baseRef !== '') {
            return $baseRef;
        }

        exec($gitPrefix.'fetch --no-tags --depth=1 origin main:refs/remotes/origin/main 2>/dev/null', output: $output, result_code: $exitCode);
        if ($exitCode === 0) {
            $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
            if ($baseRef !== '') {
                return $baseRef;
            }
        }

        exec($gitPrefix.'fetch --no-tags --deepen=1000 origin main:refs/remotes/origin/main 2>/dev/null', output: $deepenOutput, result_code: $deepenExitCode);
        if ($deepenExitCode === 0) {
            $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
            if ($baseRef !== '') {
                return $baseRef;
            }
        }

        $isShallow = trim((string) shell_exec($gitPrefix.'rev-parse --is-shallow-repository 2>/dev/null')) === 'true';
        if ($isShallow) {
            exec($gitPrefix.'fetch --no-tags --unshallow origin 2>/dev/null', output: $unshallowOutput, result_code: $unshallowExitCode);
            if ($unshallowExitCode === 0) {
                exec($gitPrefix.'fetch --no-tags origin main:refs/remotes/origin/main 2>/dev/null');
            }
        }

        $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
        if ($baseRef !== '') {
            return $baseRef;
        }

        exec($gitPrefix.'fetch --no-tags origin main:refs/remotes/origin/main 2>/dev/null', output: $fullFetchOutput, result_code: $fullFetchExitCode);
        if ($fullFetchExitCode === 0) {
            $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
            if ($baseRef !== '') {
                return $baseRef;
            }
        }

        return trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json, "Missing JSON file: {$relativePath}");

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, "Invalid JSON object: {$relativePath}");

        return $decoded;
    }
}
