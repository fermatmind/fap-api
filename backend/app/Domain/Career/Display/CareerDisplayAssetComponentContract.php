<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerDisplayAssetComponentContract
{
    /** @var list<string> */
    public const LEGACY_V4_2_ORDER = [
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

    /** @var list<string> */
    public const CURRENT_V4_2_ORDER = [
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
        'career_ai_description_block',
        'responsibilities_block',
        'work_context_block',
        'market_signal_card',
        'adjacent_career_comparison_table',
        'ai_impact_table',
        'career_risk_cards',
        'career_path_block',
        'contract_project_risk_block',
        'next_steps_block',
        'faq_block',
        'related_next_pages',
        'source_card',
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

    /** @param array<mixed> $order */
    public static function supports(array $order): bool
    {
        $order = array_values($order);

        return $order === self::LEGACY_V4_2_ORDER || $order === self::CURRENT_V4_2_ORDER;
    }

    /** @param array<mixed> $order */
    public static function isCurrent(array $order): bool
    {
        return array_values($order) === self::CURRENT_V4_2_ORDER;
    }
}
