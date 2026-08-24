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

    /** @var list<string> */
    public const CURRENT_V4_3_ORDER = [
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
        'career_quick_answers_block',
        'onet_structured_fields_block',
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

        return $order === self::LEGACY_V4_2_ORDER
            || $order === self::CURRENT_V4_2_ORDER
            || $order === self::CURRENT_V4_3_ORDER;
    }

    /** @param array<mixed> $order */
    public static function isCurrent(array $order): bool
    {
        return array_values($order) === self::CURRENT_V4_3_ORDER;
    }

    /** @param array<mixed> $payload */
    public static function hasExactCurrentPages(array $payload): bool
    {
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $allowed = array_merge(self::CURRENT_V4_3_ORDER, ['path', 'secondary_cta']);

        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)
                || array_diff(self::CURRENT_V4_3_ORDER, array_keys($page)) !== []
                || array_diff(array_keys($page), $allowed) !== []
                || array_key_exists('sections', $page)
                || array_key_exists('content_sections', $page)
                || ! self::validStructuredComponents($page, $locale)
                || self::containsPlaceholder($page)) {
                return false;
            }
        }

        $localeKeys = array_keys($pages);
        sort($localeKeys, SORT_STRING);

        return $localeKeys === ['en', 'zh'];
    }

    /** @param array<mixed> $payload */
    public static function hasExactPagesForVersion(array $payload, string $version): bool
    {
        if ($version === 'v4.3') {
            return self::hasExactCurrentPages($payload);
        }
        if ($version !== 'v4.2') {
            return false;
        }
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $allowed = array_merge(self::CURRENT_V4_2_ORDER, ['path', 'secondary_cta']);
        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)
                || array_diff(self::CURRENT_V4_2_ORDER, array_keys($page)) !== []
                || array_diff(array_keys($page), $allowed) !== []
                || self::containsPlaceholder($page)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $order */
    public static function matchesVersion(array $order, string $version): bool
    {
        return array_values($order) === match ($version) {
            'v4.3' => self::CURRENT_V4_3_ORDER,
            'v4.2' => self::CURRENT_V4_2_ORDER,
            default => [],
        };
    }

    /** @param array<string,mixed> $page */
    private static function validStructuredComponents(array $page, string $locale): bool
    {
        $quick = $page['career_quick_answers_block'] ?? null;
        $onet = $page['onet_structured_fields_block'] ?? null;
        if ($locale === 'en') {
            $unavailable = [
                'availability' => 'unavailable',
                'reason_code' => 'source_locale_unavailable',
            ];

            return $quick === $unavailable && $onet === $unavailable;
        }
        if (! is_array($quick) || ! self::exactKeys($quick, ['availability', 'schema_version', 'heading', 'items'])
            || ($quick['availability'] ?? null) !== 'published'
            || ($quick['schema_version'] ?? null) !== 'career.quick_answers.v1'
            || ! self::nonEmptyString($quick['heading'] ?? null)
            || ! is_array($quick['items'] ?? null) || count($quick['items']) !== 3) {
            return false;
        }
        foreach (['qa3', 'qa2', 'qa1'] as $index => $key) {
            $item = $quick['items'][$index] ?? null;
            if (! is_array($item) || ! self::exactKeys($item, ['key', 'question', 'answer', 'table'])
                || ($item['key'] ?? null) !== $key
                || ! self::nonEmptyString($item['question'] ?? null)
                || ! self::nonEmptyString($item['answer'] ?? null)
                || ! is_array($item['table'] ?? null)
                || ! self::exactKeys($item['table'], ['rows'])
                || ! self::validRows($item['table']['rows'] ?? null)) {
                return false;
            }
        }

        return is_array($onet)
            && self::exactKeys($onet, ['availability', 'schema_version', 'heading', 'rows'])
            && ($onet['availability'] ?? null) === 'published'
            && ($onet['schema_version'] ?? null) === 'career.onet_structured_fields.v1'
            && self::nonEmptyString($onet['heading'] ?? null)
            && self::validRows($onet['rows'] ?? null);
    }

    private static function validRows(mixed $rows): bool
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! self::exactKeys($row, ['label', 'value', 'alternate_value', 'secondary_value'])
                || ! self::nonEmptyString($row['label'] ?? null)
                || ! self::nonEmptyString($row['value'] ?? null)
                || (($row['alternate_value'] ?? null) !== null && ! self::nonEmptyString($row['alternate_value']))
                || (($row['secondary_value'] ?? null) !== null && ! self::nonEmptyString($row['secondary_value']))) {
                return false;
            }
        }

        return true;
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param array<mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
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
