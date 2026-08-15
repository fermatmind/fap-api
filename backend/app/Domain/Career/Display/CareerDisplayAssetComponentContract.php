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

    /** @param array<mixed> $payload */
    public static function hasExactCurrentPages(array $payload): bool
    {
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $allowed = array_merge(self::CURRENT_V4_2_ORDER, ['path', 'secondary_cta']);

        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)
                || array_diff(self::CURRENT_V4_2_ORDER, array_keys($page)) !== []
                || array_diff(array_keys($page), $allowed) !== []
                || array_key_exists('sections', $page)
                || array_key_exists('content_sections', $page)
                || self::containsPlaceholder($page)) {
                return false;
            }
        }

        $localeKeys = array_keys($pages);
        sort($localeKeys, SORT_STRING);

        return $localeKeys === ['en', 'zh'];
    }

    private static function containsPlaceholder(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['content_available'] ?? null) === false
            || str_starts_with((string) ($value['module_state'] ?? ''), 'pending_')
            || ($value['source'] ?? null) === 'component_order_contract') {
            return true;
        }
        foreach ($value as $child) {
            if (self::containsPlaceholder($child)) {
                return true;
            }
        }

        return false;
    }
}
