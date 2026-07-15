<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\Bridge\BigFiveCareerBridgeAuditor;
use App\Domain\Career\Bridge\BigFiveCareerBridgeContract;

final class BigFiveCareerBridgeAuditFixture
{
    /** @return array<string, mixed> */
    public static function bigFiveAuthority(string $locale = 'en'): array
    {
        return [
            'projection_kind' => BigFiveCareerBridgeAuditor::BIG_FIVE_PROJECTION_KIND,
            'projection_version' => BigFiveCareerBridgeAuditor::BIG_FIVE_PROJECTION_VERSION,
            'items' => [self::bigFiveItem($locale)],
        ];
    }

    /** @return array<string, mixed> */
    public static function careerProjection(string $locale = 'en'): array
    {
        return [
            'projection_kind' => BigFiveCareerBridgeContract::CAREER_PROJECTION_KIND,
            'projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => [[
                'slug' => 'software-engineers',
                'locale' => $locale === 'zh-CN' ? 'zh' : $locale,
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'search_visible' => true,
                'sitemap_live' => true,
                'llms_live' => true,
                'llms_full_live' => true,
                'canonical_url' => 'https://fermatmind.com/'.($locale === 'zh-CN' ? 'zh' : $locale).'/career/jobs/software-engineers',
                'canonical_self' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
                'blockers' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function candidates(string $careerHash, string $locale = 'en'): array
    {
        return [
            'candidate_kind' => BigFiveCareerBridgeAuditor::CANDIDATE_KIND,
            'candidate_version' => BigFiveCareerBridgeAuditor::CANDIDATE_VERSION,
            'rows' => [[
                'input' => self::input($careerHash, $locale),
                'output' => self::output($careerHash, $locale),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function input(string $careerHash, string $locale = 'en'): array
    {
        $bigFive = self::bigFiveItem($locale);
        $careerLocale = $locale === 'zh-CN' ? 'zh' : $locale;

        return [
            'bridge_contract_version' => BigFiveCareerBridgeContract::INPUT_CONTRACT_VERSION,
            'bridge_id' => 'bridge-001',
            'locale' => $locale,
            'big_five_asset_identity' => $bigFive['asset_id'],
            'big_five_primary_status' => 'published',
            'big_five_published_revision_id' => 123,
            'big_five_public_projection_hash' => str_repeat('a', 64),
            'big_five_claim_permissions' => true,
            'big_five_source_permission' => true,
            'big_five_reviewer_permission' => true,
            'big_five_date_permission' => true,
            'career_canonical_slug' => 'software-engineers',
            'career_runtime_projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
            'career_runtime_projection_hash' => $careerHash,
            'career_publish_eligibility' => true,
            'private_data_absent' => true,
            'big_five_projection' => $bigFive,
            'career_projection' => [
                'projection_kind' => BigFiveCareerBridgeContract::CAREER_PROJECTION_KIND,
                'projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
                'projection_hash' => $careerHash,
                'occupation_id' => 'occupation-001',
                'canonical_slug' => 'software-engineers',
                'locale' => $careerLocale,
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'release_gate_pass' => true,
                'publish_eligibility' => true,
                'public_projection_ready' => true,
                'blockers' => [],
            ],
            'signal_policy' => [
                'primary_career_interest_signal' => BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL,
                'big_five_role' => BigFiveCareerBridgeContract::BIG_FIVE_ROLE,
                'claim_mode' => BigFiveCareerBridgeContract::CLAIM_MODE,
                'occupation_ranking_allowed' => false,
                'hiring_use_allowed' => false,
                'outcome_prediction_allowed' => false,
                'pseo_expansion_allowed' => false,
            ],
            'privacy_boundary' => self::privacyBoundary(),
        ];
    }

    /** @return array<string, mixed> */
    public static function output(string $careerHash, string $locale = 'en'): array
    {
        $careerLocale = $locale === 'zh-CN' ? 'zh' : $locale;

        return [
            'contract_version' => BigFiveCareerBridgeContract::OUTPUT_CONTRACT_VERSION,
            'bridge_id' => 'bridge-001',
            'status' => BigFiveCareerBridgeContract::STATUS_PUBLISHED_PROJECTION_READY,
            'claim_mode' => BigFiveCareerBridgeContract::CLAIM_MODE,
            'public_reader_allowed' => true,
            'source_locks' => [
                'big_five_asset_id' => self::assetId($locale),
                'big_five_locale' => $locale,
                'big_five_published_revision_id' => 123,
                'big_five_public_projection_hash' => str_repeat('a', 64),
                'career_occupation_id' => 'occupation-001',
                'career_canonical_slug' => 'software-engineers',
                'career_locale' => $careerLocale,
                'career_projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
                'career_runtime_projection_hash' => $careerHash,
            ],
            'content' => [
                'reflection_signals' => ['Notice which work rhythms help you stay engaged.'],
                'environment_questions' => ['How much structure helps you do your best work?'],
                'feedback_and_structure_preferences' => ['Compare frequent feedback with independent review cycles.'],
                'possible_friction_cues' => ['Explore how you respond when priorities change quickly.'],
                'exploration_examples' => ['Interview people about day-to-day constraints.'],
                'boundary_copy' => ['Use this alongside interests, skills, values, experience, and constraints.'],
            ],
            'claim_boundary' => [
                'big_five_role' => BigFiveCareerBridgeContract::BIG_FIVE_ROLE,
                'primary_career_interest_signal' => BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL,
                'recommendation_authority' => false,
                'ranking_allowed' => false,
                'hiring_use_allowed' => false,
                'outcome_prediction_allowed' => false,
                'pseo_allowed' => false,
            ],
            'privacy_boundary' => self::privacyBoundary(),
            'discoverability_changes' => false,
            'blockers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function bigFiveItem(string $locale): array
    {
        return [
            'authority_surface' => 'personality_public_content_asset',
            'source_kind' => BigFiveCareerBridgeContract::BIG_FIVE_SOURCE_KIND,
            'framework' => 'big_five',
            'asset_id' => self::assetId($locale),
            'locale' => $locale,
            'primary_status' => 'published',
            'is_public' => true,
            'published_revision_id' => 123,
            'selected_revision_id' => 123,
            'selected_revision_source' => BigFiveCareerBridgeContract::SELECTED_REVISION_SOURCE,
            'public_projection_hash' => str_repeat('a', 64),
            'working_revision_id' => 456,
            'public_projection_ready' => true,
            'visible_evidence' => [
                'claim_permission' => true,
                'source_permission' => true,
                'reviewer_permission' => true,
                'visible_date_permission' => true,
            ],
            'working_or_draft_revision_used' => false,
            'generated_authority_package_used' => false,
        ];
    }

    /** @return array<string, false> */
    private static function privacyBoundary(): array
    {
        return [
            'contains_private_assessment_data' => false,
            'contains_user_identifiers' => false,
            'contains_attempt_or_report_links' => false,
            'contains_order_or_payment_data' => false,
        ];
    }

    private static function assetId(string $locale): string
    {
        return $locale === 'zh-CN'
            ? 'model_hub:zh-CN:/zh/personality/big-five'
            : 'model_hub:en:/en/personality/big-five';
    }
}
