<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use LogicException;

/**
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface cms_translation_revision
 * @review-surface content_page
 * @review-surface content_page_external_evidence_gate
 * @review-surface support_article
 * @review-surface interpretation_guide
 * @review-surface research_report
 * @review-surface editorial_review
 * @review-surface personality_public_content_asset
 * @review-surface personality_public_content_asset_revision_review
 * @review-surface big_five_v2_editorial_revision
 * @review-surface mbti_approval_batch
 * @review-surface mbti_cross_type_comparison_authority
 * @review-surface enneagram_review_binder
 * @review-surface riasec_content_release_review
 */
final class ReviewPolicyRegistry
{
    public const SCHEMA_VERSION = 'review-policy-registry.v1';

    /**
     * @var list<array<string, mixed>>
     */
    private const SURFACES = [
        ['article', 'cms', 'R1', 'App\\Models\\Article', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['article_translation_revision', 'cms', 'R1', 'App\\Models\\ArticleTranslationRevision', 'private_only', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['cms_translation_revision', 'cms', 'R1', 'App\\Models\\CmsTranslationRevision', 'private_only', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['content_page', 'cms', 'R1', 'App\\Models\\ContentPage', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['content_page_external_evidence_gate', 'cms', 'R4', 'App\\Models\\ContentPage', 'normalized_contract_pending_pr6', true, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['support_article', 'cms', 'R1', 'App\\Models\\SupportArticle', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['interpretation_guide', 'cms', 'R1', 'App\\Models\\InterpretationGuide', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['research_report', 'cms', 'R4', 'App\\Models\\ResearchReport', 'normalized_contract_pending_pr6', true, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['editorial_review', 'cms', 'R1', 'App\\Models\\EditorialReview', 'private_only', false, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['personality_public_content_asset', 'personality', 'R1', 'App\\Models\\PersonalityPublicContentAsset', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['personality_public_content_asset_revision_review', 'personality', 'R1', 'App\\Models\\PersonalityPublicContentAssetRevisionReview', 'private_only', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['big_five_v2_editorial_revision', 'personality', 'R1', 'App\\Models\\BigFiveV2EditorialRevision', 'private_only', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['mbti_approval_batch', 'personality', 'R1', 'App\\Console\\Commands\\PersonalityAgentApprovalQueueCommand', 'private_only', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['mbti_cross_type_comparison_authority', 'personality', 'R1', 'App\\Models\\MbtiCrossTypeComparisonAuthority', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['enneagram_review_binder', 'personality', 'R2', 'App\\Services\\Enneagram\\AuthorityV2\\EnneagramPublicAuthorityV223ReviewEvidenceBinder', 'private_only', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['riasec_content_release_review', 'personality', 'R2', 'App\\Services\\Riasec', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-PERSONALITY-REVIEW-03'],
        ['career_trust_manifest', 'career', 'R1', 'App\\Models\\TrustManifest', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_occupation_truth_metric_review', 'career', 'R4', 'App\\Models\\OccupationTruthMetric', 'private_only', true, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_editorial_patch', 'career', 'R1', 'App\\Services\\Career', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_occupation_directory_review', 'career', 'R1', 'App\\Domain\\Career', 'normalized_contract_pending_pr6', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_salary_asset_review', 'career', 'R1', 'App\\Models\\CareerJobSalaryAsset', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_ai_impact_asset_review', 'career', 'R1', 'App\\Models\\CareerJobAiImpactAsset', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['career_import_publish_readiness', 'career', 'R2', 'App\\Services\\Career', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['seo_agent_draft_review', 'seo', 'R1', 'App\\Services\\SeoAgent', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['seo_canary_approval', 'seo', 'R2', 'App\\Console\\Commands\\SeoAgentArticleCmsPublishCanaryCommand', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['search_submission_queue_approval', 'seo', 'R2', 'App\\Services\\SeoIntel', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['seo_claim_risk_review', 'seo', 'R4', 'App\\Services\\SeoAgent', 'private_only', true, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['content_package_approval', 'seo', 'R2', 'App\\Services\\SeoAgent', 'private_only', false, 'SOLO-OWNER-CAREER-SEO-REVIEW-04'],
        ['admin_approval', 'ops', 'R3', 'App\\Models\\AdminApproval', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['refund_approval', 'ops', 'R3', 'App\\Actions\\Commerce', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['manual_benefit_grant_approval', 'ops', 'R3', 'App\\Services\\Approvals', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['benefit_revoke_approval', 'ops', 'R3', 'App\\Services\\Approvals', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['payment_event_reprocess_approval', 'ops', 'R3', 'App\\Services\\Approvals', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['rollback_release_approval', 'ops', 'R3', 'App\\Services\\Approvals', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['data_lifecycle_approval', 'ops', 'R3', 'App\\Services\\Attempts', 'private_only', false, 'SOLO-OWNER-OPS-APPROVAL-05'],
        ['daily_giving_operator_approval', 'cms', 'R4', 'App\\Models\\DailyGivingRecord', 'normalized_contract_pending_pr6', true, 'SOLO-OWNER-CMS-REVIEW-02'],
        ['media_library_operator_approval', 'cms', 'R4', 'App\\Models\\MediaAsset', 'private_only', true, 'SOLO-OWNER-CMS-REVIEW-02'],
    ];

    private const CMS_ADAPTER_SURFACES = [
        'article',
        'article_translation_revision',
        'cms_translation_revision',
        'content_page',
        'support_article',
        'interpretation_guide',
        'research_report',
        'editorial_review',
    ];

    private const PERSONALITY_ADAPTER_SURFACES = [
        'personality_public_content_asset',
        'personality_public_content_asset_revision_review',
        'big_five_v2_editorial_revision',
        'mbti_approval_batch',
        'mbti_cross_type_comparison_authority',
        'enneagram_review_binder',
        'riasec_content_release_review',
    ];

    /**
     * @return list<array{
     *   surface_id: string,
     *   domain: string,
     *   risk_tier: string,
     *   authority_layer: string,
     *   current_model_or_service: string,
     *   review_mode: string,
     *   compact_attestation_supported: bool,
     *   same_actor_allowed: bool,
     *   step_up_required: bool,
     *   production_execution_separate: bool,
     *   public_projection: string,
     *   external_evidence_required: bool,
     *   migration_pr: string,
     *   adapter_status: string
     * }>
     */
    public static function all(): array
    {
        $rows = array_map(static function (array $surface): array {
            [
                $surfaceId,
                $domain,
                $riskTier,
                $currentModelOrService,
                $publicProjection,
                $externalEvidenceRequired,
                $migrationPr,
            ] = $surface;

            return [
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'risk_tier' => $riskTier,
                'authority_layer' => 'CMS/backend',
                'current_model_or_service' => $currentModelOrService,
                'review_mode' => 'solo_owner',
                'compact_attestation_supported' => true,
                'same_actor_allowed' => true,
                'step_up_required' => $riskTier === 'R3',
                'production_execution_separate' => true,
                'public_projection' => $publicProjection,
                'external_evidence_required' => $externalEvidenceRequired,
                'migration_pr' => $migrationPr,
                'adapter_status' => self::adapterStatus($surfaceId, $externalEvidenceRequired),
            ];
        }, self::SURFACES);

        self::assertValid($rows);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function inventory(): array
    {
        $surfaces = self::all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'review_mode' => 'solo_owner',
            'review_source' => 'owner_operator_attestation',
            'surface_count' => count($surfaces),
            'surfaces' => $surfaces,
            'boundaries' => [
                'human_review_is_production_authorization' => false,
                'external_evidence_can_be_created_by_attestation' => false,
                'automated_checks_remain_required' => true,
                'high_risk_step_up_remains_required' => true,
                'public_reviewer_identity_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function policy(string $surfaceId): array
    {
        foreach (self::all() as $row) {
            if ($row['surface_id'] === $surfaceId) {
                return $row;
            }
        }

        throw new LogicException('Review surface is not registered: '.$surfaceId.'.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function assertValid(array $rows): void
    {
        $required = [
            'surface_id',
            'domain',
            'risk_tier',
            'authority_layer',
            'current_model_or_service',
            'review_mode',
            'compact_attestation_supported',
            'same_actor_allowed',
            'step_up_required',
            'production_execution_separate',
            'public_projection',
            'external_evidence_required',
            'migration_pr',
            'adapter_status',
        ];
        $seen = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== $required) {
                throw new LogicException('Review policy registry row has an invalid schema.');
            }
            $surfaceId = (string) $row['surface_id'];
            if ($surfaceId === '' || isset($seen[$surfaceId])) {
                throw new LogicException('Review policy registry contains a missing or duplicate surface ID.');
            }
            $seen[$surfaceId] = true;
            if (! in_array($row['risk_tier'], ['R1', 'R2', 'R3', 'R4'], true)
                || ! is_string($row['public_projection'])
                || $row['public_projection'] === '') {
                throw new LogicException('Review policy registry risk tier or public projection is invalid.');
            }
            if ($row['risk_tier'] === 'R3' && $row['step_up_required'] !== true) {
                throw new LogicException('R3 review policy surfaces must require step-up authorization.');
            }
            if ($row['external_evidence_required'] === true && $row['risk_tier'] !== 'R4') {
                throw new LogicException('External-evidence review policy surfaces must be classified R4.');
            }
        }
    }

    private static function adapterStatus(string $surfaceId, bool $externalEvidenceRequired): string
    {
        if ($surfaceId === 'content_page_external_evidence_gate') {
            return 'external_evidence_gate_preserved';
        }
        if (in_array($surfaceId, self::CMS_ADAPTER_SURFACES, true)) {
            return $externalEvidenceRequired
                ? 'compact_attestation_adapter_active_external_evidence_still_required'
                : 'compact_attestation_adapter_active';
        }
        if (in_array($surfaceId, self::PERSONALITY_ADAPTER_SURFACES, true)) {
            return 'compact_attestation_adapter_active';
        }

        return 'policy_registered_adapter_pending';
    }
}
