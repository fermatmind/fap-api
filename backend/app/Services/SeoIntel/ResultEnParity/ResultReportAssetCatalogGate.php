<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\ResultEnParity;

final class ResultReportAssetCatalogGate
{
    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $families = $this->families();

        return [
            'schema_version' => 'result-en-parity-01-asset-catalog-gate.v1',
            'task' => 'RESULT-EN-PARITY-01',
            'gate' => [
                'name' => 'assessment_result_report_locale_parity_gate',
                'mode' => 'fail_closed_for_missing_english_interpretation_assets',
                'production_mutation' => false,
                'cms_mutation' => false,
                'search_channel_action' => false,
                'fap_web_authority' => false,
            ],
            'covered_families' => array_keys($families),
            'families' => $families,
            'blocking_issues' => $this->blockingIssues($families),
            'summary' => $this->summary($families),
        ];
    }

    /**
     * @param  array<string, mixed>  $export
     * @return array<int, array<string, mixed>>
     */
    public function blockingIssuesFromExport(array $export): array
    {
        /** @var array<string, array<string, mixed>> $families */
        $families = $export['families'] ?? [];

        return $this->blockingIssues($families);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function families(): array
    {
        return [
            'MBTI' => [
                'authority_sources' => [
                    'backend/app/Services/Report/ReportComposer.php',
                    'backend/app/Services/Report/ReportPayloadAssembler.php',
                    'backend/app/Services/Mbti/MbtiPublicProjectionService.php',
                    'backend/app/Internal/Legacy/Mbti/Report/LegacyMbtiReportPayloadBuilderV2Core.php',
                ],
                'sensitive_claim_boundary' => 'career wording must remain exploratory and non-deterministic',
                'assets' => [
                    $this->asset('mbti.external_content_package_export', false, false, true, false, false, 'interpretation_copy', false, 'blocking_missing_backend_export'),
                    $this->asset('mbti.legacy_generated_fallback_copy', true, false, true, true, false, 'interpretation_copy', false, 'blocking_legacy_zh_fallback_risk'),
                    $this->asset('mbti.frontend_clone_content_reference', true, false, true, true, false, 'interpretation_copy', false, 'frontend_non_authoritative_reference_only'),
                    $this->asset('mbti.result_type_label', true, true, false, false, true, 'presentation_label', false),
                ],
            ],
            'BIG5_OCEAN' => [
                'authority_sources' => [
                    'backend/app/Services/Report/BigFiveReportComposer.php',
                    'backend/app/Services/BigFive/BigFivePackLoader.php',
                    'backend/app/Services/BigFive/ResultPageV2/*',
                    'backend/content_assets/big5/*',
                ],
                'sensitive_claim_boundary' => 'trait-vector and workstyle explanation only; no career recommendation authority',
                'assets' => [
                    $this->asset('big5.v1.copy.compiled', true, true, false, false, false, 'interpretation_copy', false),
                    $this->asset('big5.v1.blocks.compiled', true, true, false, false, false, 'interpretation_copy', false),
                    $this->asset('big5.result_page_v2.route_matrix', true, false, true, true, false, 'interpretation_copy', false, 'blocking_v2_en_missing'),
                    $this->asset('big5.result_page_v2.coupling_assets', true, false, true, true, false, 'interpretation_copy', false, 'blocking_v2_en_missing'),
                    $this->asset('big5.result_page_v2.scenario_action_assets', true, false, true, true, false, 'interpretation_copy', false, 'blocking_v2_en_missing'),
                    $this->asset('big5.result_page_v2.facet_assets', true, false, true, true, false, 'interpretation_copy', false, 'blocking_v2_en_missing'),
                    $this->asset('big5.result_page_v2.canonical_profiles', true, false, true, true, false, 'interpretation_copy', false, 'blocking_v2_en_missing'),
                    $this->asset('big5.trait_chart_labels', true, true, false, false, true, 'presentation_label', false),
                ],
            ],
            'ENNEAGRAM' => [
                'authority_sources' => [
                    'backend/app/Services/Report/EnneagramReportComposer.php',
                    'backend/app/Services/Enneagram/EnneagramPublicProjectionService.php',
                    'backend/content_packs/ENNEAGRAM/v2/registry/*',
                ],
                'sensitive_claim_boundary' => 'self-knowledge language only; no clinical or hiring suitability claims',
                'assets' => [
                    $this->asset('enneagram.type_registry', true, false, true, true, false, 'interpretation_copy', false, 'blocking_registry_en_missing'),
                    $this->asset('enneagram.pair_registry', true, false, true, true, false, 'interpretation_copy', false, 'blocking_registry_en_missing'),
                    $this->asset('enneagram.group_registry', true, false, true, true, false, 'interpretation_copy', false, 'blocking_registry_en_missing'),
                    $this->asset('enneagram.technical_note_registry', true, false, true, true, false, 'interpretation_copy', false, 'blocking_registry_en_missing'),
                    $this->asset('enneagram.ui_copy_registry', true, false, true, true, false, 'interpretation_copy', false, 'blocking_registry_en_missing'),
                    $this->asset('enneagram.type_badge_label', true, true, false, false, true, 'presentation_label', false),
                ],
            ],
            'EQ_60' => [
                'authority_sources' => [
                    'backend/app/Services/Report/Eq60ReportComposer.php',
                    'backend/app/Services/Eq60/Eq60PackLoader.php',
                    'backend/content_packs/EQ_60/v1/compiled/*',
                ],
                'sensitive_claim_boundary' => 'emotional skill self-reflection only; no clinical or hiring suitability claims',
                'assets' => [
                    $this->asset('eq60.report.blocks_by_locale', true, true, false, false, false, 'interpretation_copy', false),
                    $this->asset('eq60.report_assets.localized_maps', true, true, false, false, false, 'interpretation_copy', false),
                    $this->asset('eq60.dimension_labels', true, true, false, false, true, 'presentation_label', false),
                ],
            ],
            'RIASEC' => [
                'authority_sources' => [
                    'backend/app/Services/Report/RiasecReportComposer.php',
                    'backend/app/Services/Riasec/RiasecLifecycleCopyService.php',
                    'backend/app/Services/Riasec/RiasecDeepCopySlotRegistry.php',
                    'backend/content_assets/riasec/*',
                ],
                'sensitive_claim_boundary' => 'interest signal and exploratory guidance only; no active career recommendation authority',
                'assets' => [
                    $this->asset('riasec.lifecycle_copy.share_pdf_history', true, false, true, true, false, 'interpretation_copy', true, 'blocking_riasec_en_missing'),
                    $this->asset('riasec.lifecycle_copy.faq', true, false, true, true, false, 'interpretation_copy', true, 'blocking_riasec_en_missing'),
                    $this->asset('riasec.lifecycle_copy.technical_note_user_summary', true, false, true, true, false, 'interpretation_copy', true, 'blocking_riasec_en_missing'),
                    $this->asset('riasec.lifecycle_copy.professional_method_boundary', true, false, true, true, false, 'interpretation_copy', true, 'blocking_riasec_en_missing'),
                    $this->asset('riasec.result_dimension_labels', true, true, false, false, true, 'presentation_label', true),
                ],
            ],
            'SDS_20' => [
                'authority_sources' => [
                    'backend/app/Services/Report/Sds20ReportComposer.php',
                    'backend/app/Services/Sds20/Sds20PackLoader.php',
                    'backend/content_packs/SDS_20/v1/compiled/report.compiled.json',
                    'backend/content_packs/DEPRESSION_SCREENING_STANDARD/v1/compiled/report.compiled.json',
                ],
                'sensitive_claim_boundary' => 'self-assessment only; non-diagnostic and professional-help bounded',
                'assets' => [
                    $this->asset('sds20.report.blocks_by_locale', true, true, false, false, false, 'interpretation_copy', true),
                    $this->asset('depression_screening_standard.report.blocks_by_locale', true, true, false, false, false, 'interpretation_copy', true),
                    $this->asset('sds20.factor_labels', true, true, false, false, true, 'presentation_label', true),
                ],
            ],
            'CLINICAL_COMBO_68' => [
                'authority_sources' => [
                    'backend/app/Services/Report/ClinicalCombo68ReportComposer.php',
                    'backend/app/Services/ClinicalCombo/ClinicalComboPackLoader.php',
                    'backend/app/Services/ClinicalCombo/ClinicalComboBlockSelector.php',
                    'backend/content_packs/CLINICAL_COMBO_68/v1/compiled/report.compiled.json',
                ],
                'sensitive_claim_boundary' => 'self-assessment only; non-diagnostic, not treatment, professional-help bounded',
                'assets' => [
                    $this->asset('clinical_combo_68.free_blocks_by_locale', true, true, false, false, false, 'interpretation_copy', true),
                    $this->asset('clinical_combo_68.paid_action_anxiety_14d', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_action_burnout', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_action_depression_14d', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_action_ocd_erp_start', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_action_perfectionism_14d', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_perf_cm_mistakes', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_perf_da_doubts', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_perf_org_order', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_perf_pe_parental', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.paid_perf_ps_standards', true, false, true, true, false, 'interpretation_copy', true, 'blocking_clinical_paid_en_missing'),
                    $this->asset('clinical_combo_68.severity_labels', true, true, false, false, true, 'presentation_label', true),
                ],
            ],
            'IQ_INTELLIGENCE_QUOTIENT' => [
                'authority_sources' => [
                    'backend/app/Services/Report/IqReportBuilder.php',
                    'backend/app/Services/Iq/IqResultPayloadRedactor.php',
                ],
                'sensitive_claim_boundary' => 'online estimate and confidence-bound wording only; no clinical IQ authority',
                'assets' => [
                    $this->asset('iq.dimensions.visual_spatial_insight', true, false, true, true, false, 'interpretation_copy', true, 'blocking_iq_label_en_missing'),
                    $this->asset('iq.dimensions.visual_spatial_pattern_reasoning', true, false, true, true, false, 'interpretation_copy', true, 'blocking_iq_label_en_missing'),
                    $this->asset('iq.dimensions.numeric_pattern_reasoning', true, false, true, true, false, 'interpretation_copy', true, 'blocking_iq_label_en_missing'),
                    $this->asset('iq_pro.pdf_payload', false, false, true, false, false, 'interpretation_copy', true, 'contract_defined_not_implemented'),
                    $this->asset('iq_pro.certificate_payload', false, false, true, false, false, 'interpretation_copy', true, 'contract_defined_not_implemented'),
                    $this->asset('iq.score_band_labels', true, true, false, false, true, 'presentation_label', true),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(
        string $key,
        bool $hasZh,
        bool $hasEn,
        bool $missingEn,
        bool $fallbackToZhDetected,
        bool $presentationLabelOnly,
        string $copyKind,
        bool $sensitiveClaimBoundary,
        ?string $deferredReason = null
    ): array {
        $interpretationCopy = $copyKind === 'interpretation_copy';

        return [
            'key' => $key,
            'has_zh' => $hasZh,
            'has_en' => $hasEn,
            'missing_en' => $missingEn,
            'fallback_to_zh_detected' => $fallbackToZhDetected,
            'presentation_label_only' => $presentationLabelOnly,
            'interpretation_copy' => $interpretationCopy,
            'copy_kind' => $copyKind,
            'sensitive_claim_boundary' => $sensitiveClaimBoundary,
            'fail_closed_for_en' => $interpretationCopy && $missingEn,
            'deferred_reason' => $deferredReason,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $families
     * @return array<int, array<string, mixed>>
     */
    private function blockingIssues(array $families): array
    {
        $issues = [];

        foreach ($families as $family => $config) {
            /** @var array<int, array<string, mixed>> $assets */
            $assets = $config['assets'] ?? [];

            foreach ($assets as $asset) {
                if (! (bool) ($asset['fail_closed_for_en'] ?? false)) {
                    continue;
                }

                $issues[] = [
                    'family' => $family,
                    'asset_key' => $asset['key'],
                    'severity' => (bool) ($asset['sensitive_claim_boundary'] ?? false) ? 'p0_sensitive_or_claim_safe' : 'p1_result_report_copy',
                    'reason' => 'missing_english_interpretation_asset_must_not_render_zh_cn',
                    'fallback_to_zh_detected' => (bool) ($asset['fallback_to_zh_detected'] ?? false),
                    'deferred_reason' => $asset['deferred_reason'] ?? null,
                ];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, array<string, mixed>>  $families
     * @return array<string, int>
     */
    private function summary(array $families): array
    {
        $summary = [
            'family_count' => count($families),
            'asset_count' => 0,
            'missing_en_count' => 0,
            'fallback_to_zh_detected_count' => 0,
            'presentation_label_only_count' => 0,
            'interpretation_copy_count' => 0,
            'sensitive_claim_boundary_count' => 0,
            'fail_closed_count' => 0,
        ];

        foreach ($families as $config) {
            /** @var array<int, array<string, mixed>> $assets */
            $assets = $config['assets'] ?? [];

            foreach ($assets as $asset) {
                $summary['asset_count']++;
                $summary['missing_en_count'] += (int) (bool) ($asset['missing_en'] ?? false);
                $summary['fallback_to_zh_detected_count'] += (int) (bool) ($asset['fallback_to_zh_detected'] ?? false);
                $summary['presentation_label_only_count'] += (int) (bool) ($asset['presentation_label_only'] ?? false);
                $summary['interpretation_copy_count'] += (int) (bool) ($asset['interpretation_copy'] ?? false);
                $summary['sensitive_claim_boundary_count'] += (int) (bool) ($asset['sensitive_claim_boundary'] ?? false);
                $summary['fail_closed_count'] += (int) (bool) ($asset['fail_closed_for_en'] ?? false);
            }
        }

        return $summary;
    }
}
