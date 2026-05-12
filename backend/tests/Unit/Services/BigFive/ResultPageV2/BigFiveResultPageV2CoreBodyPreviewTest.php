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

    public function test_runtime_freeze_classifier_ignores_iq_report_foundation_changes(): void
    {
        $changed = [
            'backend/app/Services/Report/IqReportBuilder.php',
            'backend/app/Services/Report/ReportComposerRegistry.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_public_content_release_guard_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/ReleaseVerifyPublicContent.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_public_distribution_owner_changes(): void
    {
        $changed = [
            'backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
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
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionDTO.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthValidator.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionValidator.php',
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

    public function test_runtime_freeze_classifier_ignores_career_80_cohort_readiness_plan_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessPlanner.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessRow.php',
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

    public function test_runtime_freeze_classifier_ignores_article_publishing_runtime_truth_gate_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/StructuredData/CareerArticleStructuredDataBuilder.php',
            'backend/app/Services/Cms/ArticleSeoService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
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
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
            'backend/app/Http/Resources/Career/CareerJobDetailResource.php',
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerLocaleIntegrityGate.php',
            'backend/app/Services/Cms/CareerJobSeoService.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
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

    public function test_runtime_freeze_classifier_ignores_career_runtime_projection_consumer_changes(): void
    {
        $changed = [
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionLookup.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionVisibility.php',
            'backend/app/Providers/AppServiceProvider.php',
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobListBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerSearchBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
            'backend/app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php',
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
    ): array {
        $impacting = [];

        foreach ($changed as $file) {
            if ($this->isCareerConsoleCommandFile($file)) {
                continue;
            }

            if ($this->isPublicContentReleaseGuardCommandFile($file)) {
                continue;
            }

            if ($this->isCommercePaymentActionFile($file)) {
                continue;
            }

            if ($this->isCmsLifecycleTenantScopeFile($file)) {
                continue;
            }

            if ($this->isArticlePublishingRuntimeTruthGateFile($file)) {
                continue;
            }

            if ($this->isPrivacyLogsDsarKeyRotationFile($file)) {
                continue;
            }

            if ($this->isFileImportIdempotencyHardeningFile($file)) {
                continue;
            }

            if ($this->isCiAuthBypassHardeningFile($file)) {
                continue;
            }

            if ($this->isCareerDisplaySurfaceFile($file)) {
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

            if ($this->isCareerRuntimeProjectionTruthAuditFile($file)) {
                continue;
            }

            if ($this->isCareerSeoGeoReadinessAuditFile($file)) {
                continue;
            }

            if ($this->isCareerSurfaceReadinessAuditFile($file)) {
                continue;
            }

            if ($this->isCareer80CohortReadinessPlanFile($file)) {
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

            if ($this->isCareerRuntimeProjectionConsumerFile($file)) {
                continue;
            }

            if (
                $file === 'backend/routes/api.php'
                && $this->routeDiffIsCareerPublicDistributionOnly($routeChangedLines ?? $this->routeChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            if ($this->isBigFiveV2PilotSupportFile($file)) {
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

            if ($this->isResultEmailLookupFile($file)) {
                continue;
            }

            if ($this->isResultEmailGatedReadFile($file)) {
                continue;
            }

            if ($this->isIqScoringContractFoundationFile($file)) {
                continue;
            }

            if ($this->isRiasecMeasurementContractComparePolicyFile($file)) {
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

            if ($this->isIqReportFoundationFile($file)) {
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
                && $this->kernelDiffIsCareerOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
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

    private function isPublicContentReleaseGuardCommandFile(string $file): bool
    {
        return $file === 'backend/app/Console/Commands/ReleaseVerifyPublicContent.php';
    }

    private function isCommercePaymentActionFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Filament/Tenant/Resources/OrderResource.php',
            'backend/app/Http/Controllers/API/V0_3/CommerceController.php',
            'backend/app/Internal/Commerce/PaymentWebhookHandlerCore.php',
            'backend/app/Services/Commerce/Checkout/AlipayCheckoutService.php',
            'backend/app/Services/Commerce/OrderManager.php',
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

    private function isArticlePublishingRuntimeTruthGateFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Career/StructuredData/CareerArticleStructuredDataBuilder.php',
            'backend/app/Services/Cms/ArticleSeoService.php',
        ], true);
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

    private function isIqScoringContractFoundationFile(string $file): bool
    {
        return $file === 'backend/app/Services/Assessment/Drivers/IqTestDriver.php';
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

    private function isIqReportFoundationFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Report/IqReportBuilder.php',
            'backend/app/Services/Report/ReportComposerRegistry.php',
            'backend/app/Services/Report/ReportSnapshotStore.php',
            'backend/app/Http/Controllers/API/V0_3/AttemptReadController.php',
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
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
            'backend/app/Services/Career/Bundles/CareerAliasResolutionBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerLocaleIntegrityGate.php',
            'backend/app/Services/Career/Bundles/CareerRecommendationDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
            'backend/app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
            'backend/app/Http/Resources/Career/CareerJobDetailResource.php',
            'backend/app/Services/Cms/CareerJobSeoService.php',
        ], true);
    }

    private function isCareerRuntimeProjectionConsumerFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionLookup.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionVisibility.php',
            'backend/app/Providers/AppServiceProvider.php',
            'backend/app/Services/Career/Bundles/CareerJobListBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerSearchBundleBuilder.php',
            'backend/app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php',
        ], true);
    }

    private function isCareerPublicDistributionFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
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
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionDTO.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthExporter.php',
            'backend/app/Domain/Career/Publish/CareerCanonicalRuntimeTruthValidator.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionService.php',
            'backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionValidator.php',
        ], true);
    }

    private function isCareerCanonicalEligibilityAuditSchemaFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerCanonicalEligibilityAuditRow.php',
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

    private function isCareer80CohortReadinessPlanFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessIssue.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessPlanner.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessResult.php',
            'backend/app/Domain/Career/Audit/CareerCanonical80CohortReadinessRow.php',
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

    private function isBigFiveV2PilotSupportFile(string $file): bool
    {
        return $file === 'backend/app/Services/BigFive/ResultPageV2/BigFiveResultPageV2RuntimeWrapper.php'
            || $file === 'backend/app/Services/BigFive/ResultPageV2/BigFiveV2PilotRuntimeObservability.php'
            || preg_match('#^backend/app/Services/BigFive/ResultPageV2/(ContentAssets|RouteMatrix|Selector|Composer|Access|Routing|Pdf|Share|History|Compare|Rollout|Observability)/[A-Za-z0-9_]+\.php$#', $file) === 1;
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

            if (preg_match('/^\+\s*$/u', $line) === 1) {
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
    private function routeDiffIsCareerPublicDistributionOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (str_starts_with($line, '-')) {
                return false;
            }

            if (preg_match('/SitemapSourceController|\\/seo\\/sitemap-source/u', $line) !== 1) {
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

        $isShallow = trim((string) shell_exec($gitPrefix.'rev-parse --is-shallow-repository 2>/dev/null')) === 'true';
        if ($isShallow) {
            exec($gitPrefix.'fetch --no-tags --unshallow origin 2>/dev/null', output: $unshallowOutput, result_code: $unshallowExitCode);
            if ($unshallowExitCode === 0) {
                exec($gitPrefix.'fetch --no-tags origin main:refs/remotes/origin/main 2>/dev/null');
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
