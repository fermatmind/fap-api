<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerDisplayAssetComponentContract
{
    /** @var list<string> */
    public const SUPPORTED_COMPONENTS = [
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
        if ($order === [] || ! array_is_list($order)) {
            return false;
        }

        $seen = [];
        foreach ($order as $componentId) {
            if (! is_string($componentId)
                || ! in_array($componentId, self::SUPPORTED_COMPONENTS, true)
                || isset($seen[$componentId])) {
                return false;
            }
            $seen[$componentId] = true;
        }

        return true;
    }

    /** @param array<mixed> $order */
    public static function isCurrent(array $order): bool
    {
        return self::supports($order);
    }

    /** @param array<mixed> $payload */
    public static function hasDeclaredPages(array $payload, array $componentOrder): bool
    {
        if (! self::supports($componentOrder)) {
            return false;
        }

        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $allowed = array_merge(self::SUPPORTED_COMPONENTS, ['path', 'secondary_cta']);

        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)
                || array_diff($componentOrder, array_keys($page)) !== []
                || array_diff(array_keys($page), $allowed) !== []
                || array_key_exists('sections', $page)
                || array_key_exists('content_sections', $page)
                || self::structuredComponentFailureCode($page, $locale, $componentOrder) !== null
                || self::containsPlaceholder($page)) {
                return false;
            }
        }

        $localeKeys = array_keys($pages);
        sort($localeKeys, SORT_STRING);

        return $localeKeys === ['en', 'zh'];
    }

    /** @param array<mixed> $payload */
    public static function pageFailureCode(array $payload, array $componentOrder): ?string
    {
        if (! self::supports($componentOrder)) {
            return 'CURRENT_DISPLAY_SURFACE_COMPONENT_ORDER_INVALID';
        }

        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)) {
                return 'CURRENT_DISPLAY_SURFACE_LOCALE_PAGE_MISSING';
            }
            if (array_diff($componentOrder, array_keys($page)) !== []) {
                return 'CURRENT_DISPLAY_SURFACE_COMPONENT_MISSING';
            }
            if (array_diff(array_keys($page), array_merge(self::SUPPORTED_COMPONENTS, ['path', 'secondary_cta'])) !== []) {
                return 'CURRENT_DISPLAY_SURFACE_COMPONENT_UNEXPECTED';
            }
            if (array_key_exists('sections', $page) || array_key_exists('content_sections', $page)) {
                return 'CURRENT_DISPLAY_SURFACE_LEGACY_SECTION_PRESENT';
            }
            $structuredFailure = self::structuredComponentFailureCode($page, $locale, $componentOrder);
            if ($structuredFailure !== null) {
                return $structuredFailure;
            }
            if (self::containsPlaceholder($page)) {
                return 'CURRENT_DISPLAY_SURFACE_PLACEHOLDER_PRESENT';
            }
        }

        $localeKeys = array_keys($pages);
        sort($localeKeys, SORT_STRING);

        return $localeKeys === ['en', 'zh']
            ? null
            : 'CURRENT_DISPLAY_SURFACE_LOCALE_SET_MISMATCH';
    }

    /** @param array<string,mixed> $page @param array<mixed> $componentOrder */
    private static function structuredComponentFailureCode(array $page, string $locale, array $componentOrder): ?string
    {
        $declaresQuickAnswers = in_array('career_quick_answers_block', $componentOrder, true);
        $declaresOnetFields = in_array('onet_structured_fields_block', $componentOrder, true);
        $quick = $page['career_quick_answers_block'] ?? null;
        $onet = $page['onet_structured_fields_block'] ?? null;
        if ($locale === 'en') {
            if ($declaresQuickAnswers
                && ! self::isSourceLocaleUnavailable($quick)
                && ! self::validPublishedQuickAnswers($quick, 'Career quick answers')) {
                return 'CURRENT_DISPLAY_SURFACE_EN_QUICK_ANSWERS_INVALID';
            }

            return $declaresOnetFields
                && ! self::isSourceLocaleUnavailable($onet)
                && ! self::validPublishedOnetFields($onet, 'O*NET structured fields')
                    ? 'CURRENT_DISPLAY_SURFACE_EN_ONET_FIELDS_INVALID'
                    : null;
        }
        $isAccountantsProfile = str_ends_with(
            (string) ($page['path'] ?? ''),
            '/accountants-and-auditors',
        );
        $expectedQuickHeading = $isAccountantsProfile ? null : '职业速答';
        $expectedOnetHeading = $isAccountantsProfile ? null : 'O*NET 结构化字段';
        if ($declaresQuickAnswers && (! is_array($quick)
            || ! self::exactKeys($quick, ['availability', 'schema_version', 'heading', 'items'])
            || ($quick['availability'] ?? null) !== 'published'
            || ($quick['schema_version'] ?? null) !== 'career.quick_answers.v1'
            || ! self::nonEmptyString($quick['heading'] ?? null)
            || ($expectedQuickHeading !== null && ($quick['heading'] ?? null) !== $expectedQuickHeading)
            || ! is_array($quick['items'] ?? null)
            || count($quick['items']) !== 3)) {
            return 'CURRENT_DISPLAY_SURFACE_ZH_QUICK_ANSWERS_INVALID';
        }
        foreach ($declaresQuickAnswers ? ['qa3', 'qa2', 'qa1'] : [] as $index => $key) {
            $item = $quick['items'][$index] ?? null;
            if (! is_array($item)
                || ! self::exactKeys($item, ['key', 'question', 'answer', 'table'])
                || ($item['key'] ?? null) !== $key
                || ! self::nonEmptyString($item['question'] ?? null)
                || ! self::nonEmptyString($item['answer'] ?? null)) {
                return 'CURRENT_DISPLAY_SURFACE_ZH_QUICK_ANSWER_ITEM_INVALID';
            }
            if (! is_array($item['table'] ?? null)
                || ! self::exactKeys($item['table'], ['rows'])
                || ! self::validRows($item['table']['rows'] ?? null)) {
                return 'CURRENT_DISPLAY_SURFACE_ZH_QUICK_ANSWER_TABLE_INVALID';
            }
        }
        if ($declaresOnetFields && (! is_array($onet)
            || ! self::exactKeys($onet, ['availability', 'schema_version', 'heading', 'rows'])
            || ($onet['availability'] ?? null) !== 'published'
            || ($onet['schema_version'] ?? null) !== 'career.onet_structured_fields.v1'
            || ! self::nonEmptyString($onet['heading'] ?? null)
            || ($expectedOnetHeading !== null && ($onet['heading'] ?? null) !== $expectedOnetHeading))) {
            return 'CURRENT_DISPLAY_SURFACE_ZH_ONET_FIELDS_INVALID';
        }

        return $declaresOnetFields && ! self::validRows($onet['rows'] ?? null)
            ? 'CURRENT_DISPLAY_SURFACE_ZH_ONET_ROWS_INVALID'
            : null;
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

    private static function validPublishedQuickAnswers(mixed $quick, ?string $heading): bool
    {
        if (! is_array($quick) || ! self::exactKeys($quick, ['availability', 'schema_version', 'heading', 'items'])
            || ($quick['availability'] ?? null) !== 'published'
            || ($quick['schema_version'] ?? null) !== 'career.quick_answers.v1'
            || ! self::nonEmptyString($quick['heading'] ?? null)
            || ($heading !== null && ($quick['heading'] ?? null) !== $heading)
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

        return true;
    }

    private static function validPublishedOnetFields(mixed $onet, ?string $heading): bool
    {
        return is_array($onet)
            && self::exactKeys($onet, ['availability', 'schema_version', 'heading', 'rows'])
            && ($onet['availability'] ?? null) === 'published'
            && ($onet['schema_version'] ?? null) === 'career.onet_structured_fields.v1'
            && self::nonEmptyString($onet['heading'] ?? null)
            && ($heading === null || ($onet['heading'] ?? null) === $heading)
            && self::validRows($onet['rows'] ?? null);
    }

    private static function isSourceLocaleUnavailable(mixed $component): bool
    {
        return is_array($component)
            && self::exactKeys($component, ['availability', 'reason_code'])
            && ($component['availability'] ?? null) === 'unavailable'
            && ($component['reason_code'] ?? null) === 'source_locale_unavailable';
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
