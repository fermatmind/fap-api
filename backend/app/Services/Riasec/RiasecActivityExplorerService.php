<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecActivityExplorerService
{
    private const SCHEMA_VERSION = 'riasec.activity_explorer.v0.1';

    private const CONTENT_VERSION = 'activity_task_examples_v1.zh-CN';

    private const SOURCE_STATUS = 'content_example_not_registry_match';

    private const SOURCE_NAME = 'FermatTest RIASEC Activity Task Examples v1';

    private const ACTIVITY_TASK_ASSET_PATH = 'content_assets/riasec/activity_task_examples_v1.zh-CN.jsonl';

    private const OCCUPATION_EXAMPLE_CONTENT_VERSION = 'occupation_examples_boundary_v1.zh-CN';

    private const OCCUPATION_EXAMPLE_SOURCE_NAME = 'FermatTest RIASEC Occupation Examples Boundary v1';

    private const OCCUPATION_EXAMPLE_ASSET_PATH = 'content_assets/riasec/occupation_examples_boundary_v1.zh-CN.jsonl';

    private const DIMENSION_ASSET_PATH = 'content_assets/riasec/dimension_deep_copy_v1.zh-CN.r3.json';

    private const ACTIVITY_TASK_SOURCE_STATUSES = [
        'content_example_not_registry_match',
        'commercial_expansion_candidate_not_runtime_imported',
    ];

    public function __construct(
        private readonly ?string $activityTaskAssetPath = null,
        private readonly ?string $occupationExampleAssetPath = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(?string $hollandCode, string $locale = 'zh-CN'): array
    {
        $normalizedLocale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $code = $this->normalizeCode($hollandCode);
        $dimensions = $this->dimensionsForCode($code);

        if ($normalizedLocale === 'en') {
            return $this->unavailableLocalePayload($code, $normalizedLocale);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'content_version' => self::CONTENT_VERSION,
            'status' => 'content_examples_only',
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'locale' => $normalizedLocale,
            'holland_code' => $code,
            'boundary' => [
                'occupation_examples_label' => $this->occupationExamplesLabel(),
                'occupation_examples_policy' => 'content_example_not_registry_match_without_reviewed_registry_source',
                'ranking_allowed' => false,
                'fit_score_allowed' => false,
                'success_prediction_allowed' => false,
                'qualification_judgment_allowed' => false,
                'occupation_examples_share_card_allowed' => false,
                'occupation_examples_pdf_default_visible' => false,
                'occupation_examples_history_default_visible' => false,
                'registry_source_connected' => false,
            ],
            'dimension_activity_families' => $this->dimensionFamilies($dimensions, $normalizedLocale),
            'code_activity_pack' => $this->codeActivityPack($code, $normalizedLocale),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function unavailableLocalePayload(string $code, string $locale): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'content_version' => 'activity_task_examples_v1.en.pending',
            'status' => 'unavailable',
            'reason' => 'locale_content_unavailable',
            'source_status' => 'locale_review_required',
            'locale' => $locale,
            'holland_code' => $code,
            'boundary' => [
                'frontend_fallback_allowed' => false,
                'missing_content_behavior' => 'omit_module_fail_closed',
                'ranking_allowed' => false,
                'fit_score_allowed' => false,
                'success_prediction_allowed' => false,
                'qualification_judgment_allowed' => false,
            ],
            'dimension_activity_families' => [],
            'code_activity_pack' => [
                'status' => 'unavailable',
                'reason' => 'locale_content_unavailable',
                'activities' => [],
                'occupation_examples' => [],
            ],
        ];
    }

    private function normalizeCode(?string $hollandCode): string
    {
        $code = strtoupper((string) preg_replace('/[^RIASEC]/i', '', (string) $hollandCode));

        return substr($code, 0, 3);
    }

    /**
     * @return list<string>
     */
    private function dimensionsForCode(string $code): array
    {
        $dimensions = [];
        foreach (str_split($code) as $dimension) {
            if (in_array($dimension, ['R', 'I', 'A', 'S', 'E', 'C'], true) && ! in_array($dimension, $dimensions, true)) {
                $dimensions[] = $dimension;
            }
        }

        return $dimensions;
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<array<string,mixed>>
     */
    private function dimensionFamilies(array $dimensions, string $locale): array
    {
        $rows = [];
        $contentByDimension = $this->dimensionContentByCode();
        foreach ($dimensions as $dimension) {
            $source = $contentByDimension[$dimension] ?? null;
            if ($source === null) {
                continue;
            }

            $rows[] = [
                'dimension' => $dimension,
                'label' => (string) ($source['title'] ?? ''),
                'core_drive' => (string) ($source['core_drive'] ?? ''),
                'activity_families' => array_values(array_map('strval', (array) ($source['interest_activity_focus'] ?? []))),
                'evidence_level' => 'theory_based_content_mapping',
                'source_status' => self::SOURCE_STATUS,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function codeActivityPack(string $code, string $locale): array
    {
        $activities = $this->fileBackedActivitiesForCode($code, $locale);

        if ($activities === []) {
            return [
                'status' => 'not_available_for_code_v1',
                'reason' => 'activity_task_examples_not_available',
                'activities' => [],
                'occupation_examples' => [],
            ];
        }

        return [
            'status' => 'available',
            'code' => $code,
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'activity_chain' => array_values(array_map(
                static fn (array $activity): string => (string) ($activity['activity_key'] ?? ''),
                $activities,
            )),
            'selection_policy' => [
                'ordering' => 'holland_code_order_then_asset_order',
                'per_dimension_limit' => 3,
                'source_status_required' => self::SOURCE_STATUS,
                'commercial_expansion_rows_publicly_selectable' => false,
                'dedupe_key' => 'activity_key',
                'fail_closed_on_invalid_or_incomplete_asset' => true,
                'not_a_recommendation' => true,
                'ranking_allowed' => false,
                'fit_score_allowed' => false,
            ],
            'activities' => $activities,
            'occupation_examples' => [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fileBackedActivitiesForCode(string $code, string $locale): array
    {
        $dimensions = $this->dimensionsForCode($code);
        if ($dimensions === []) {
            return [];
        }

        $rows = $this->loadActivityTaskAssetRows();
        if ($rows === []) {
            return [];
        }

        $selected = [];
        $seen = [];
        foreach ($dimensions as $dimension) {
            $perDimension = 0;
            foreach ($rows as $row) {
                if ($perDimension >= 3) {
                    break;
                }

                if (
                    isset($seen[$row['activity_key']])
                    || ($row['source_status'] ?? null) !== self::SOURCE_STATUS
                    || ! in_array($dimension, $row['dimensions'], true)
                ) {
                    continue;
                }

                $seen[$row['activity_key']] = true;
                $selected[] = $this->normalizeFileBackedActivity($row, $locale);
                $perDimension++;
            }

            if ($perDimension < 3) {
                return [];
            }
        }

        return count($selected) === count($dimensions) * 3 ? $selected : [];
    }

    /**
     * @return list<array{activity_key:string,source_status:string,dimensions:list<string>,activity_label:string,task_examples:list<string>,low_risk_validation:string,action_duration_options:array<string,string>}>
     */
    private function loadActivityTaskAssetRows(): array
    {
        static $cache = [];

        $assetPath = $this->activityTaskAssetPath ?? self::ACTIVITY_TASK_ASSET_PATH;
        if (array_key_exists($assetPath, $cache)) {
            return $cache[$assetPath];
        }

        $path = $this->resolveAssetPath($assetPath);
        if (! is_file($path) || ! is_readable($path)) {
            return $cache[$assetPath] = [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $cache[$assetPath] = [];
            }

            if (! is_array($decoded) || ! $this->isValidActivityTaskRow($decoded)) {
                return $cache[$assetPath] = [];
            }

            $rows[] = [
                'activity_key' => (string) $decoded['activity_key'],
                'source_status' => (string) $decoded['source_status'],
                'dimensions' => array_values(array_map('strval', (array) $decoded['dimensions'])),
                'activity_label' => (string) $decoded['activity_label'],
                'task_examples' => array_values(array_map('strval', (array) $decoded['task_examples'])),
                'low_risk_validation' => (string) $decoded['low_risk_validation'],
                'action_duration_options' => array_map('strval', (array) $decoded['action_duration_options']),
            ];
        }

        return $cache[$assetPath] = $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidActivityTaskRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.activity_task_example.v1') {
            return false;
        }

        if (($row['asset_version'] ?? null) !== 'riasec_activity_task_examples_v1.zh-CN') {
            return false;
        }

        if (($row['frontend_fallback_allowed'] ?? true) !== false || ($row['not_a_recommendation'] ?? false) !== true) {
            return false;
        }

        if (! in_array(($row['source_status'] ?? null), self::ACTIVITY_TASK_SOURCE_STATUSES, true)) {
            return false;
        }

        if (($row['activity_key'] ?? '') === '' || ! is_array($row['dimensions'] ?? null) || ! is_array($row['task_examples'] ?? null)) {
            return false;
        }

        foreach ((array) $row['dimensions'] as $dimension) {
            if (! in_array((string) $dimension, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
                return false;
            }
        }

        return count((array) $row['task_examples']) >= 3
            && is_string($row['activity_label'] ?? null)
            && is_string($row['low_risk_validation'] ?? null)
            && is_array($row['action_duration_options'] ?? null)
            && isset(
                $row['action_duration_options']['15min'],
                $row['action_duration_options']['30min'],
                $row['action_duration_options']['2h'],
                $row['action_duration_options']['1week'],
            );
    }

    /**
     * @param  array{activity_key:string,source_status:string,dimensions:list<string>,activity_label:string,task_examples:list<string>,low_risk_validation:string,action_duration_options:array<string,string>}  $row
     * @return array<string,mixed>
     */
    private function normalizeFileBackedActivity(array $row, string $locale): array
    {
        $nextExperiments = array_values(array_filter([
            $row['action_duration_options']['15min'] ?? null,
            $row['action_duration_options']['30min'] ?? null,
            $row['low_risk_validation'],
        ]));

        return [
            'activity_key' => $row['activity_key'],
            'riasec_dimensions' => $row['dimensions'],
            'activity_label' => $locale === 'zh-CN' ? $row['activity_label'] : $row['activity_key'],
            'activity_user_copy' => $row['low_risk_validation'],
            'content_version' => self::CONTENT_VERSION,
            'evidence_level' => 'backend_authoritative_activity_task_asset',
            'source_status' => $row['source_status'],
            'source_name' => self::SOURCE_NAME,
            'not_a_recommendation' => true,
            'ranking_allowed' => false,
            'fit_score_allowed' => false,
            'selection_boundary' => 'activity_example_not_recommendation',
            'task_examples' => $row['task_examples'],
            'occupation_examples' => $this->occupationExamplesForActivityDimensions($row['dimensions']),
            'next_experiments' => $nextExperiments,
        ];
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<array<string,mixed>>
     */
    private function occupationExamplesForActivityDimensions(array $dimensions): array
    {
        $rowsByDimension = $this->loadOccupationExampleRowsByDimension();
        if ($rowsByDimension === []) {
            return [];
        }

        $examples = [];
        $seen = [];
        foreach ($dimensions as $dimension) {
            foreach (($rowsByDimension[$dimension] ?? []) as $row) {
                if (count($examples) >= 2) {
                    break 2;
                }

                if (isset($seen[$row['record_id']])) {
                    continue;
                }

                $seen[$row['record_id']] = true;
                $examples[] = $this->normalizeOccupationExample($row);
            }
        }

        return $examples;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadOccupationExampleRowsByDimension(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $path = $this->resolveAssetPath($this->occupationExampleAssetPath ?? self::OCCUPATION_EXAMPLE_ASSET_PATH);
        if (! is_file($path) || ! is_readable($path)) {
            return $cache = [];
        }

        $rowsByDimension = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $cache = [];
            }

            if (! is_array($decoded) || ! $this->isValidOccupationExampleRow($decoded)) {
                return $cache = [];
            }

            $dimension = (string) $decoded['primary_activity_dimension'];
            $rowsByDimension[$dimension][] = $decoded;
        }

        return $cache = $rowsByDimension;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidOccupationExampleRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.occupation_example_boundary.v1') {
            return false;
        }

        if (($row['asset_version'] ?? null) !== 'riasec_occupation_examples_boundary_v1.zh-CN') {
            return false;
        }

        if (($row['source_status'] ?? null) !== self::SOURCE_STATUS) {
            return false;
        }

        if (($row['frontend_fallback_allowed'] ?? true) !== false || ($row['not_a_recommendation'] ?? false) !== true) {
            return false;
        }

        if (($row['source_url_allowed'] ?? true) !== false || ($row['fit_score_allowed'] ?? true) !== false) {
            return false;
        }

        $dimension = (string) ($row['primary_activity_dimension'] ?? '');
        if (! in_array($dimension, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
            return false;
        }

        foreach (['record_id', 'occupation_example', 'display_label', 'why_it_may_appear', 'education_boundary', 'skill_boundary', 'qualification_boundary', 'user_visible_boundary', 'reality_check'] as $field) {
            if (! is_string($row[$field] ?? null) || $row[$field] === '') {
                return false;
            }
        }

        return is_array($row['common_tasks'] ?? null)
            && count((array) $row['common_tasks']) >= 3
            && is_array($row['task_examples'] ?? null)
            && count((array) $row['task_examples']) >= 3
            && ! isset($row['source_url'], $row['onet_code'], $row['soc_code'], $row['fit_score'], $row['rank'], $row['success_prediction']);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeOccupationExample(array $row): array
    {
        return [
            'occupation_example' => (string) $row['occupation_example'],
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::OCCUPATION_EXAMPLE_SOURCE_NAME,
            'content_version' => self::OCCUPATION_EXAMPLE_CONTENT_VERSION,
            'display_label' => (string) $row['display_label'],
            'why_it_may_appear' => (string) $row['why_it_may_appear'],
            'common_tasks' => array_values(array_map('strval', (array) $row['common_tasks'])),
            'task_examples' => array_values(array_map('strval', (array) $row['task_examples'])),
            'education_boundary' => (string) $row['education_boundary'],
            'skill_boundary' => (string) $row['skill_boundary'],
            'qualification_boundary' => (string) $row['qualification_boundary'],
            'user_visible_boundary' => (string) $row['user_visible_boundary'],
            'reality_check' => (string) $row['reality_check'],
            'not_a_recommendation' => true,
            'examples_only' => true,
            'ranking_allowed' => false,
            'fit_score_allowed' => false,
            'success_prediction_allowed' => false,
            'qualification_judgment_allowed' => false,
            'public_surfaces' => [
                'result_page_allowed' => true,
                'share_card_allowed' => false,
                'pdf_default_visible' => false,
                'history_default_visible' => false,
            ],
        ];
    }

    private function resolveAssetPath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /** @return array<string,array<string,mixed>> */
    private function dimensionContentByCode(): array
    {
        $path = $this->resolveAssetPath(self::DIMENSION_ASSET_PATH);
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $rows = is_array($decoded['dimensions'] ?? null) ? $decoded['dimensions'] : [];
        $byCode = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['dimension_code'] ?? '');
            if (in_array($code, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
                $byCode[$code] = $row;
            }
        }

        return count($byCode) === 6 ? $byCode : [];
    }

    private function occupationExamplesLabel(): string
    {
        $rows = $this->loadOccupationExampleRowsByDimension();
        foreach ($rows as $dimensionRows) {
            $label = trim((string) ($dimensionRows[0]['display_label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }
}
