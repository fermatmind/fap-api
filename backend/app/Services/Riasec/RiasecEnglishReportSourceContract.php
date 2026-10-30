<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Services\Content\RiasecPrivateResultCompileService;
use RuntimeException;

/** The EN13 report source set; neither PDF access nor a psychometric qualification. */
final class RiasecEnglishReportSourceContract
{
    public const ID = 'riasec.en13.report_sources.v1';

    public const AUTHORITY_ID = 'FERMATMIND_RIASEC_PRIVATE_RESULT_EN_REPORT';

    public const SURFACE_ASSET = 'report_surface_copy_v1.en.json';

    private const SURFACE_DIGEST = 'e0f1275beaf15a9b53fbde7a96c23c178bf99e8983e2775eebbf557a5eb093a3';

    // Preserve immutable 1.9 snapshots while admitting the reviewed low-band copy.
    private const REVISED_SURFACE_DIGEST = '4583f96ec3ae19abd4038af5515eab11ee3c0cb099fcb8fda6370db9d76e23e2';

    /** @param array<string,mixed> $assets */
    public static function assertSurface(array $assets): void
    {
        $surface = $assets[self::SURFACE_ASSET] ?? null;
        if (! is_array($surface)
            || ($surface['schema_version'] ?? null) !== 'riasec.report_surface_copy.v1'
            || ($surface['locale'] ?? null) !== 'en'
            || ($surface['frontend_fallback_allowed'] ?? true) !== false
            || ! in_array(self::digest($surface), [self::SURFACE_DIGEST, self::REVISED_SURFACE_DIGEST], true)) {
            throw new RuntimeException('RIASEC_EN_REPORT_SURFACE_SOURCE_INVALID');
        }
    }

    /** Derived from validated source identities and bytes, never an editable readiness flag. */
    public static function coverage(array $assets): array
    {
        self::assertSurface($assets);
        $digests = [];
        foreach (RiasecPrivateResultCompileService::sourceContract('1.9.0') as $path => $contract) {
            if (! str_contains($path, '.en.')) {
                continue;
            }
            $asset = $assets[$path] ?? null;
            if (! is_array($asset) || $asset === []) {
                throw new RuntimeException('RIASEC_EN_REPORT_SOURCE_MISSING:'.$path);
            }
            $rows = str_ends_with($path, '.jsonl') ? $asset : [$asset];
            foreach ($rows as $row) {
                if (($row['locale'] ?? null) !== 'en' || ($row['frontend_fallback_allowed'] ?? true) !== false) {
                    throw new RuntimeException('RIASEC_EN_REPORT_SOURCE_LOCALE_INVALID:'.$path);
                }
            }
            $digests[$path] = self::digest($asset);
        }
        ksort($digests, SORT_STRING);

        return [
            'schema_version' => 'riasec.report_source_coverage.v1',
            'contract_id' => self::ID,
            'locale' => 'en',
            'forms' => ['riasec_60', 'riasec_140'],
            'instance_quality_states' => ['normal'],
            'instance_quality_grades' => ['A'],
            'source_digests' => $digests,
            'dimensions' => array_column($assets['dimension_deep_copy_v1.en.json']['dimensions'], 'dimension_code'),
            'pairs' => array_column($assets['pair_blend_15_pairs_v1.en.jsonl'], 'pair_key'),
            'top3_groups' => array_column($assets['top3_code_chain_strategy_v1.en.jsonl'], 'unordered_top3_key'),
            'ordered_codes' => RiasecPrivateResultCompileService::englishOrderedCodeCoverage('1.9.0'),
            'required_field_groups' => ['measurement', 'profile_shape_fact', 'quality_boundary', 'visible_dimension_pair_top3', 'visible_140_context', 'exploration_or_explicit_empty', 'faq_and_method'],
        ];
    }

    /** Called only for the new package; old selected-module identities remain unchanged. */
    public static function sourceAuthority(array $payload): ?array
    {
        if (($payload['compiler']['version'] ?? null) !== '1.9.0') {
            return null;
        }
        $assets = (array) ($payload['assets'] ?? []);
        $compiler = new RiasecPrivateResultCompileService;
        $compiler->assertSelectedEnglishSources($assets, '1.9.0');
        $compiler->assertEnglishActivities($assets[RiasecPrivateResultCompileService::ENGLISH_ACTIVITY_ASSET], $assets['activity_task_examples_v1.zh-CN.jsonl']);
        $coverage = self::coverage($assets);
        $sourceInput = '';
        foreach (RiasecPrivateResultCompileService::sourceContract('1.9.0') as $path => $contract) {
            if (! isset($assets[$path])) {
                throw new RuntimeException('RIASEC_EN_REPORT_SOURCE_MISSING:'.$path);
            }
            $sourceInput .= $path."\0".self::digest($assets[$path])."\n";
        }
        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        if (($payload['schema'] ?? null) !== RiasecPrivateResultCompileService::SCHEMA
            || ($payload['authority_id'] ?? null) !== RiasecPrivateResultCompileService::AUTHORITY_ID
            || ($payload['locale'] ?? null) !== 'zh-CN'
            || ($payload['compiler']['schema'] ?? null) !== RiasecPrivateResultCompileService::COMPILER_SCHEMA
            || self::digest($coverage) !== self::digest($payload['coverage']['english_report_sources'] ?? null)
            || ! hash_equals(hash('sha256', $sourceInput), (string) ($payload['source_hash'] ?? ''))
            || ! hash_equals(self::digest($unsigned), (string) ($payload['compiled_hash'] ?? ''))) {
            throw new RuntimeException('RIASEC_EN_REPORT_SOURCE_BINDING_INVALID');
        }

        return [
            'schema_version' => 'fap.riasec.private_result_authority.v1',
            'authority_id' => self::AUTHORITY_ID,
            'mode' => 'canonical',
            'coverage_scope' => 'report_sources',
            'locale' => 'en',
            'report_contract_id' => self::ID,
            'source_coverage_hash' => self::digest($coverage),
            'source_hash' => $payload['source_hash'],
            'compiled_hash' => $payload['compiled_hash'],
            'compiled_schema' => $payload['schema'],
            'compiler_schema' => $payload['compiler']['schema'],
            'compiler_version' => $payload['compiler']['version'],
            'runtime_contract' => 'riasec.report.v1',
        ];
    }

    /**
     * $expected is rebuilt by the native source readers, not supplied by a client.
     * A complete legal view is distinct from ownership, entitlement and PDF readiness.
     */
    public static function validateProjection(array $projection, array $sourceAuthority, array $facts, array $expected): array
    {
        $errors = [];
        $require = static function (bool $valid, string $pointer, string $reason) use (&$errors): void {
            if (! $valid) {
                $errors[] = ['pointer' => $pointer, 'reason' => $reason];
            }
        };
        $require(($projection['locale'] ?? null) === 'en', '/locale', 'locale_mismatch');
        $require(($sourceAuthority['authority_id'] ?? null) === self::AUTHORITY_ID, '/private_result_authority', 'source_authority_missing');
        $require(data_get($projection, 'measurement_evidence.snapshot_bound') === true, '/measurement_evidence/snapshot_bound', 'snapshot_required');
        $scores = [];
        foreach ((array) data_get($projection, 'scores.dimensions', []) as $row) {
            $code = $row['code'] ?? '';
            $require(! array_key_exists($code, $scores), '/scores/dimensions', 'duplicate_dimension');
            $scores[$code] = $row['score'] ?? null;
        }
        $keys = array_keys($scores);
        sort($keys);
        $require($keys === ['A', 'C', 'E', 'I', 'R', 'S'], '/scores/dimensions', 'six_dimension_identities_required');
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $code) {
            $value = $facts['scores'][$code] ?? null;
            $require((is_int($value) || is_float($value)) && is_finite((float) $value)
                && $value >= 0 && $value <= 100 && isset($scores[$code])
                && (float) $scores[$code] === (float) $value, '/scores/dimensions/'.$code, 'score_missing_or_not_original_number');
        }
        $require(($projection['scale_code'] ?? null) === 'RIASEC', '/scale_code', 'scale_identity_required');
        $require(data_get($projection, 'scores.score_kind') === 'dimension_scores_0_100', '/scores/score_kind', 'normalized_interest_unit_required');
        $require(self::digest(data_get($projection, 'scores.dimensions')) === self::digest($expected['score_rows'] ?? null), '/scores/dimensions', 'native_numeric_rows_and_labels_required');
        $form = (string) data_get($projection, 'form.form_code', '');
        $measurement = (new RiasecMeasurementContract)->forFormCode($form, $form === 'riasec_140' ? 140 : 60);
        $require(self::digest($projection['claim_boundary'] ?? null) === self::digest($measurement['claim_boundary']), '/claim_boundary', 'measurement_claim_boundary_required');
        $require(self::digest($projection['dimension_score_band_contract_v1'] ?? null) === self::digest($measurement['scoring']['interpretation_band_contract']), '/dimension_score_band_contract_v1', 'non_percentile_band_contract_required');
        foreach (['form_code', 'question_count', 'form_kind', 'score_space_version'] as $field) {
            $require(in_array($form, ['riasec_60', 'riasec_140'], true)
                && data_get($projection, 'form.'.$field) === data_get($measurement, 'form.'.$field), '/form/'.$field, 'form_contract_mismatch');
        }
        foreach (['scoring_spec_version', 'form_version', 'content_package_version', 'interpretation_rule_version', 'quality_rule_version', 'measurement_contract_version'] as $field) {
            $require(is_string(data_get($projection, 'measurement_evidence.'.$field))
                && trim(data_get($projection, 'measurement_evidence.'.$field, '')) !== '', '/measurement_evidence/'.$field, 'version_required');
        }
        $require(data_get($projection, 'measurement_evidence.score_space_version') === data_get($measurement, 'form.score_space_version'), '/measurement_evidence/score_space_version', 'score_space_mismatch');
        $require(data_get($projection, 'measurement_evidence.normalization_method') === data_get($measurement, 'scoring.normalization_method'), '/measurement_evidence/normalization_method', 'unit_contract_mismatch');
        $code = (string) data_get($projection, 'holland_code.code', '');
        $require(preg_match('/\A[RIASEC]{3}\z/', $code) === 1 && count(array_unique(str_split($code))) === 3
            && $code === ($facts['top_code'] ?? null)
            && data_get($projection, 'interpretation_state.tie_display_v1.ordered_code') === $code, '/holland_code/code', 'code_snapshot_mismatch');
        $require(in_array(data_get($projection, 'interpretation_state.profile_shape'), RiasecDeepCopySlotRegistry::PROFILE_SHAPES, true), '/interpretation_state/profile_shape', 'profile_fact_required');
        $require(self::digest($projection['quality'] ?? null) === self::digest($expected['quality'] ?? null), '/quality', 'native_quality_facts_required');
        $require(self::digest(data_get($projection, 'quality.display_v1')) === self::digest($expected['quality_display'] ?? null), '/quality/display_v1', 'quality_source_mismatch');
        $require(self::digest(data_get($projection, 'interpretation_state.tie_display_v1')) === self::digest($expected['tie_display'] ?? null), '/interpretation_state/tie_display_v1', 'tie_fact_or_copy_mismatch');

        $require(self::digest($projection['module_visibility_policy'] ?? null) === self::digest($expected['module_policy'] ?? null), '/module_visibility_policy', 'native_visibility_policy_required');
        $require(($facts['original_form_code'] ?? null) === $form, '/form/form_code', 'original_supported_form_required');
        $require(($facts['measurement_form_code'] ?? null) === null || $facts['measurement_form_code'] === $form, '/form/form_code', 'original_measurement_form_conflict');
        $require(is_int($facts['answer_count'] ?? null) && $facts['answer_count'] === data_get($measurement, 'form.question_count'), '/form/question_count', 'original_completed_count_required');
        $require(($facts['quality_grade'] ?? null) === data_get($projection, 'quality.grade') && ($facts['quality_grade'] ?? null) === 'A', '/quality/grade', 'original_standard_quality_grade_required');
        $require(($facts['result_type_code'] ?? null) === $code, '/holland_code/code', 'original_result_code_required');
        foreach (['primary_type', 'secondary_type', 'tertiary_type'] as $index => $field) {
            $require(data_get($projection, 'holland_code.'.$field) === ($code[$index] ?? null), '/holland_code/'.$field, 'ordered_type_identity_mismatch');
        }
        foreach (['scoring_spec_version', 'form_version', 'content_package_version'] as $field) {
            $original = null;
            foreach ((array) ($facts['version_candidates'][$field] ?? []) as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $original = trim($candidate);
                    break;
                }
            }
            $require($original !== null && data_get($projection, 'measurement_evidence.'.$field) === $original, '/measurement_evidence/'.$field, 'original_snapshot_version_required');
        }
        $require(self::digest($projection['interpretation_state'] ?? null) === self::digest($expected['interpretation_state'] ?? null), '/interpretation_state', 'native_profile_and_tie_facts_required');
        $require(data_get($projection, 'measurement_evidence.measurement_contract_version') === RiasecMeasurementContract::SCHEMA_VERSION, '/measurement_evidence/measurement_contract_version', 'measurement_contract_mismatch');
        $require(data_get($projection, 'measurement_evidence.interpretation_rule_version') === RiasecInterpretationRuleContract::VERSION, '/measurement_evidence/interpretation_rule_version', 'interpretation_contract_mismatch');
        $require(data_get($projection, 'measurement_evidence.quality_rule_version') === RiasecQualityRuleContract::VERSION, '/measurement_evidence/quality_rule_version', 'quality_contract_mismatch');
        $require(self::digest($projection['result_summary_v1'] ?? null) === self::digest($expected['summary'] ?? null)
            && ($projection['result_summary_v1'] ?? []) !== [], '/result_summary_v1', 'snapshot_summary_source_required');
        $visibility = [];
        foreach ((array) data_get($projection, 'module_visibility_policy.modules', []) as $module) {
            $visibility[$module['key']] = $module['visibility'] ?? 'hidden';
        }
        $visible = static fn (array $slots): array => array_values(array_filter($slots, static fn (array $slot): bool => in_array($visibility[$slot['module_key'] ?? ''] ?? 'hidden', ['visible', 'collapsed'], true)));
        $slots = $visible((array) data_get($projection, 'deep_content_slots_v1.slots', []));
        $nativeSlots = $visible((array) ($expected['slots'] ?? []));
        $require(self::digest($slots) === self::digest($nativeSlots), '/deep_content_slots_v1/slots', 'selected_source_content_mismatch');
        $identities = [];
        foreach ($slots as $slot) {
            $require(($slot['locale'] ?? null) === 'en' && ($slot['status'] ?? null) === 'authored'
                && ($slot['content_status'] ?? null) === 'authored' && ($slot['frontend_fallback_allowed'] ?? true) === false,
                '/deep_content_slots_v1/slots/'.($slot['slot_id'] ?? ''), 'slot_locale_or_authorship_invalid');
            $require(self::hasEnglishText($slot['content']['title'] ?? $slot['content']['pair_label'] ?? $slot['content']['strategy_label'] ?? null)
                && self::hasEnglishText($slot['boundaries']['user_visible_boundary'] ?? null), '/deep_content_slots_v1/slots/'.($slot['slot_id'] ?? ''), 'required_reader_content_missing');
            $identities[$slot['slot_key']][] = $slot;
        }
        $dimensionCodes = array_map(static fn (array $slot): string => (string) data_get($slot, 'state.dimension_code', ''), $identities['dimension_deep_copy'] ?? []);
        sort($dimensionCodes);
        $require($dimensionCodes === ['A', 'C', 'E', 'I', 'R', 'S'], '/deep_content_slots_v1/slots/dimension_deep_copy', 'six_selected_dimension_sources_required');
        foreach (['hero_activity_chain' => 'triad_blend_copy', 'pair_blend' => 'pair_blend_copy', '140q_context_cards' => '140q_task_card_copy'] as $module => $key) {
            if (in_array($visibility[$module] ?? 'hidden', ['visible', 'collapsed'], true)) {
                $require(($key === '140q_task_card_copy' ? in_array(count($identities[$key] ?? []), [1, 2], true) : count($identities[$key] ?? []) === ($key === 'pair_blend_copy' ? 3 : 1)), '/deep_content_slots_v1/slots/'.$key, 'visible_source_module_missing');
            }
        }
        if (in_array($visibility['140q_context_cards'] ?? 'hidden', ['visible', 'collapsed'], true)) {
            foreach (['140q_environment_card_copy', '140q_role_card_copy'] as $key) {
                $require(in_array(count($identities[$key] ?? []), [1, 2], true), '/deep_content_slots_v1/slots/'.$key, 'visible_context_layer_missing');
            }
        }
        $lifecycle = (array) ($projection['lifecycle_copy_v1'] ?? []);
        foreach (['faq_items', 'method_boundaries', 'technical_note_summary_sections', 'professional_method_boundary_sections'] as $key) {
            $rows = $lifecycle[$key] ?? [];
            $require($rows !== [] && self::digest($rows) === self::digest($expected['lifecycle'][$key] ?? null), '/lifecycle_copy_v1/'.$key, 'required_lifecycle_source_mismatch');
        }
        foreach ((array) ($lifecycle['faq_items'] ?? []) as $index => $row) {
            $require(self::hasEnglishText($row['q'] ?? null) && self::hasEnglishText($row['a'] ?? null), '/lifecycle_copy_v1/faq_items/'.$index, 'faq_reader_missing');
        }
        foreach ((array) ($lifecycle['technical_note_summary_sections'] ?? []) as $index => $row) {
            $require(self::hasEnglishText($row['title'] ?? null) && self::hasEnglishText($row['copy'] ?? null)
                && array_key_exists('data_status', $row) && $row['data_status'] === null, '/lifecycle_copy_v1/technical_note_summary_sections/'.$index, 'technical_source_or_unknown_status_invalid');
        }
        $require(($lifecycle['locale'] ?? null) === 'en' && ($lifecycle['status'] ?? null) === 'available', '/lifecycle_copy_v1', 'lifecycle_locale_or_source_unavailable');
        if (in_array($visibility['activity_explorer'] ?? 'hidden', ['visible', 'collapsed'], true)) {
            $require(self::digest($projection['activity_explorer_v0_1'] ?? null) === self::digest($expected['activity'] ?? null), '/activity_explorer_v0_1', 'activity_source_or_explicit_empty_mismatch');
        }
        $restricted = data_get($projection, 'quality.quality_state') !== 'normal'
            || data_get($projection, 'quality.grade') !== 'A'
            || in_array(data_get($projection, 'interpretation_state.profile_shape'), ['low_clarity', 'unavailable'], true);
        if ($restricted) {
            $errors[] = ['pointer' => '/quality/quality_state', 'reason' => 'complete_report_source_contract_requires_normal_supported_reading'];
        }
        $view = [
            'source_coverage_hash' => $sourceAuthority['source_coverage_hash'] ?? null,
            'scale_code' => $projection['scale_code'] ?? null,
            'scores' => $projection['scores'] ?? null, 'form' => $projection['form'] ?? null,
            'claim_boundary' => $projection['claim_boundary'] ?? null,
            'dimension_score_band_contract_v1' => $projection['dimension_score_band_contract_v1'] ?? null,
            'measurement_evidence' => $projection['measurement_evidence'] ?? null,
            'holland_code' => $projection['holland_code'] ?? null,
            'interpretation_state' => $projection['interpretation_state'] ?? null,
            'quality' => $projection['quality'] ?? null, 'slots' => $slots,
            'summary' => $projection['result_summary_v1'] ?? null, 'module_policy' => $projection['module_visibility_policy'] ?? null,
            'lifecycle' => array_intersect_key($lifecycle, array_flip(['schema_version', 'locale', 'status', 'faq_items', 'method_boundaries', 'technical_note_summary_sections', 'professional_method_boundary_sections'])),
            'activity' => in_array($visibility['activity_explorer'] ?? 'hidden', ['visible', 'collapsed'], true) ? ($projection['activity_explorer_v0_1'] ?? null) : null,
        ];

        return [
            'schema_version' => 'riasec.report_content_validation.v1',
            'contract_id' => self::ID,
            'status' => $restricted ? 'restricted' : ($errors === [] ? 'complete' : 'incomplete'),
            'source_authority' => $sourceAuthority,
            'instance_hash' => self::digest($view),
            'raw_scores' => 'not_required_when_absent_from_snapshot_unknown',
            'errors' => $errors,
        ];
    }

    private static function hasEnglishText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('/\p{Han}/u', $value) !== 1;
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
