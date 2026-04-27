<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

final class EnneagramAssetPublicPayloadSanitizer
{
    public const INTERNAL_FIELDS = [
        'selection_guidance',
        'editor_note',
        'qa_note',
        'safety_note',
        'codex_policy',
        'qa_policy',
        'replacement_policy',
        'governance_metadata',
        'body_quality',
        'avoid_when',
        'import_policy',
        'copy_quality_policy',
        'freeze_policy',
        'final_product_owner_decision',
        'production_blockers_before_public_launch',
        'preflight_self_check',
        'coverage_check',
        'review_status',
    ];

    private const ALLOWED_ITEM_FIELDS = [
        'asset_key',
        'asset_type',
        'category',
        'module_key',
        'body_zh',
        'short_body_zh',
        'cta_zh',
        'content_maturity',
        'evidence_level',
        'version',
        'pair_key',
        'canonical_pair_key',
        'type_a',
        'type_b',
        'title_zh',
        'commercial_summary',
        'page1_close_call_summary',
        'shared_surface_similarity',
        'core_motivation_difference',
        'fear_difference',
        'stress_reaction_difference',
        'work_difference',
        'relationship_difference',
        'seven_day_observation_question',
        'resonance_feedback_prompt',
        'micro_discrimination_prompt',
        'day1_observation_prompt',
        'day3_reflection_prompt',
        'day7_convergence_prompt',
        'fallback',
        'directional',
        'scene_axis',
        'scene_domain',
        'scene_label_zh',
        'fc144_recommendation_context',
        'recommendation_strategy',
    ];

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function sanitizeItem(array $item): array
    {
        $payload = [];
        foreach (self::ALLOWED_ITEM_FIELDS as $field) {
            if (array_key_exists($field, $item)) {
                $payload[$field] = $item[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function stripInternalMetadata(array $payload): array
    {
        foreach (self::INTERNAL_FIELDS as $field) {
            unset($payload[$field]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripInternalMetadata($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function internalMetadataLeaks(array $payload): array
    {
        $leaks = [];
        $this->collectLeaks($payload, '', $leaks);

        return array_values(array_unique($leaks));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $leaks
     */
    private function collectLeaks(array $payload, string $prefix, array &$leaks): void
    {
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, self::INTERNAL_FIELDS, true)) {
                $leaks[] = $path;
            }
            if (is_array($value)) {
                $this->collectLeaks($value, $path, $leaks);
            }
        }
    }
}
