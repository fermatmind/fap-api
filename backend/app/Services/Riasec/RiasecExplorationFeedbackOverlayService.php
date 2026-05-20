<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Result;

final class RiasecExplorationFeedbackOverlayService
{
    private const SCHEMA_VERSION = 'riasec.exploration_feedback_overlay.v0.1';

    private const FEEDBACK_ACTION_LAB_ASSET_PATH = '/content_assets/riasec/feedback_action_lab_v1.zh-CN.jsonl';

    private const NEXT_EXPLORATION_NODES_ASSET_PATH = '/content_assets/riasec/next_exploration_nodes_v1.zh-CN.jsonl';

    /** @var list<array<string,mixed>>|null */
    private ?array $feedbackActionRowsCache = null;

    /** @var list<array<string,mixed>>|null */
    private ?array $nextExplorationNodeRowsCache = null;

    /**
     * @return array<string,mixed>
     */
    public function build(Result $result, array $projectionV2, bool $snapshotBound): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $topCode = (string) data_get($projectionV2, 'holland_code.code', '');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'safe_static_content_bridge_v0_1',
            'feedback_stream_status' => 'not_connected_v0_1',
            'scale_code' => 'RIASEC',
            'snapshot_bound' => $snapshotBound,
            'snapshot_identity' => [
                'snapshot_required' => true,
                'snapshot_bound' => $snapshotBound,
                'identity_scope' => 'projection_snapshot',
                'form_code' => (string) data_get($projectionV2, 'form.form_code', ''),
                'score_space_version' => (string) data_get($projectionV2, 'form.score_space_version', ''),
                'measured_holland_code' => (string) data_get($projectionV2, 'holland_code.code', ''),
            ],
            'measured_result_guard' => [
                'measured_holland_code' => (string) data_get($projectionV2, 'holland_code.code', ''),
                'scores_mutation_allowed' => false,
                'holland_code_mutation_allowed' => false,
                'report_snapshot_mutation_allowed' => false,
                'measurement_evidence_mutation_allowed' => false,
            ],
            'feedback_scope' => [
                'allowed' => [
                    'activity_resonance',
                    'task_interest_signal',
                    'occupation_example_helpfulness',
                    'exploration_next_step_interest',
                ],
                'not_allowed' => [
                    'score_override',
                    'holland_code_override',
                    'career_recommendation',
                    'job_fit_prediction',
                    'career_success_prediction',
                    'qualification_judgment',
                ],
            ],
            'surface_policy' => [
                'public_projection_allowed' => true,
                'share_pdf_exposure_allowed' => false,
                'raw_feedback_public_exposure_allowed' => false,
                'formal_report_mutation_allowed' => false,
            ],
            'read_model' => [
                'has_feedback' => false,
                'feedback_count' => 0,
                'latest_feedback_at' => null,
                'summary_status' => 'not_available_without_feedback_stream',
                'raw_feedback_included' => false,
            ],
            'claim_boundary' => [
                'feedback_is_measurement' => false,
                'feedback_changes_scores' => false,
                'feedback_changes_measured_holland_code' => false,
                'feedback_is_career_match' => false,
                'feedback_is_success_prediction' => false,
            ],
            'context' => [
                'form_code' => (string) ($payload['form_code'] ?? data_get($projectionV2, 'form.form_code', '')),
                'score_space_version' => (string) ($payload['score_space_version'] ?? data_get($projectionV2, 'form.score_space_version', '')),
            ],
            'action_lab_v1' => $this->actionLabPayload(),
            'next_exploration_nodes_v1' => $this->nextExplorationNodesPayload($topCode),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function actionLabPayload(): array
    {
        $rows = $this->feedbackActionRows();

        return [
            'schema_version' => 'riasec.feedback_action_lab_payload.v1',
            'content_authority' => 'backend_riasec_feedback_action_lab_assets',
            'content_version' => 'riasec_feedback_action_lab_v1.zh-CN',
            'status' => $rows === [] ? 'unavailable' : 'available_static_safe_bridge',
            'availability' => 'static_starter_actions_without_feedback_read_model',
            'read_model_dependency' => 'not_connected_v0_1',
            'missing_content_behavior' => 'omit_module_fail_closed',
            'frontend_fallback_allowed' => false,
            'frontend_renderer_required_for_visible_module' => true,
            'public_raw_feedback_allowed' => false,
            'affects_measured_code' => false,
            'affects_score' => false,
            'affects_snapshot' => false,
            'share_pdf_history_measured_payload_mutation_allowed' => false,
            'raw_feedback_included' => false,
            'read_model_dependent_actions' => [
                'status' => 'unavailable_without_feedback_stream',
                'fallback_behavior' => 'omit_module',
            ],
            'starter_actions' => $this->starterFeedbackActions($rows),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function starterFeedbackActions(array $rows): array
    {
        $selected = [];
        foreach ($rows as $row) {
            $eventType = (string) $row['event_type'];
            if (isset($selected[$eventType])) {
                continue;
            }

            $selected[$eventType] = [
                'event_type' => $eventType,
                'activity_key' => (string) $row['activity_key'],
                'user_copy' => (string) $row['user_copy'],
                'system_response' => (string) $row['system_response'],
                'next_step_copy' => (string) $row['next_step_copy'],
                'score_mutation_allowed' => false,
                'measured_holland_code_mutation_allowed' => false,
                'snapshot_mutation_allowed' => false,
                'share_pdf_exposure_allowed' => false,
                'frontend_fallback_allowed' => false,
            ];
        }

        return array_values($selected);
    }

    /**
     * @return array<string,mixed>
     */
    private function nextExplorationNodesPayload(string $topCode): array
    {
        $rows = $this->nextExplorationNodeRows();

        return [
            'schema_version' => 'riasec.next_exploration_nodes_payload.v1',
            'content_authority' => 'backend_riasec_next_exploration_node_assets',
            'content_version' => 'riasec_next_exploration_nodes_v1.zh-CN',
            'status' => $rows === [] ? 'unavailable' : 'available_static_safe_bridge',
            'selection_mode' => 'top_code_dimension_static_starter_nodes_without_feedback_read_model',
            'missing_content_behavior' => 'omit_module_fail_closed',
            'frontend_fallback_allowed' => false,
            'frontend_renderer_required_for_visible_module' => true,
            'public_raw_feedback_allowed' => false,
            'affects_measured_code' => false,
            'affects_score' => false,
            'affects_snapshot' => false,
            'creates_career_match' => false,
            'share_pdf_history_measured_payload_mutation_allowed' => false,
            'nodes' => $this->starterNextExplorationNodes($rows, $topCode),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function starterNextExplorationNodes(array $rows, string $topCode): array
    {
        $preferredDimensions = array_values(array_filter(str_split(strtoupper($topCode)), fn (string $dimension): bool => in_array($dimension, ['R', 'I', 'A', 'S', 'E', 'C'], true)));
        if ($preferredDimensions === []) {
            $preferredDimensions = ['R', 'I', 'A', 'S', 'E', 'C'];
        }

        $nodes = [];
        $seenTypes = [];
        foreach ($preferredDimensions as $dimension) {
            foreach ($rows as $row) {
                $nodeType = (string) $row['node_type'];
                if ((string) $row['dimension_hint'] !== $dimension || isset($seenTypes[$dimension.'_'.$nodeType])) {
                    continue;
                }

                $nodes[] = [
                    'node_id' => (string) $row['node_id'],
                    'node_type' => $nodeType,
                    'dimension_hint' => $dimension,
                    'title' => (string) $row['title'],
                    'summary' => (string) $row['summary'],
                    'instruction' => (string) $row['instruction'],
                    'estimated_time' => (string) $row['estimated_time'],
                    'creates_score_change' => false,
                    'creates_career_match' => false,
                    'frontend_fallback_allowed' => false,
                ];
                $seenTypes[$dimension.'_'.$nodeType] = true;

                if (count($nodes) >= 6) {
                    return $nodes;
                }
            }
        }

        return $nodes;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function feedbackActionRows(): array
    {
        if ($this->feedbackActionRowsCache !== null) {
            return $this->feedbackActionRowsCache;
        }

        $rows = [];
        foreach ($this->jsonlAssetRows(self::FEEDBACK_ACTION_LAB_ASSET_PATH) as $row) {
            if ($this->isValidFeedbackActionRow($row)) {
                $rows[] = $row;
            }
        }

        return $this->feedbackActionRowsCache = $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function nextExplorationNodeRows(): array
    {
        if ($this->nextExplorationNodeRowsCache !== null) {
            return $this->nextExplorationNodeRowsCache;
        }

        $rows = [];
        foreach ($this->jsonlAssetRows(self::NEXT_EXPLORATION_NODES_ASSET_PATH) as $row) {
            if ($this->isValidNextExplorationNodeRow($row)) {
                $rows[] = $row;
            }
        }

        return $this->nextExplorationNodeRowsCache = $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function jsonlAssetRows(string $relativePath): array
    {
        $path = dirname(__DIR__, 3).$relativePath;
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                return [];
            }
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidFeedbackActionRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.feedback_action_lab.v1') {
            return false;
        }
        if (($row['asset_version'] ?? null) !== 'riasec_feedback_action_lab_v1.zh-CN') {
            return false;
        }
        foreach (['event_type', 'activity_key', 'user_copy', 'system_response', 'next_step_copy'] as $field) {
            if (! is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                return false;
            }
        }

        return ($row['score_mutation_allowed'] ?? true) === false
            && ($row['measured_holland_code_mutation_allowed'] ?? true) === false
            && ($row['snapshot_mutation_allowed'] ?? true) === false
            && ($row['share_pdf_exposure_allowed'] ?? true) === false
            && ($row['frontend_fallback_allowed'] ?? true) === false;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidNextExplorationNodeRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.next_exploration_node.v1') {
            return false;
        }
        if (($row['asset_version'] ?? null) !== 'riasec_next_exploration_nodes_v1.zh-CN') {
            return false;
        }
        foreach (['node_id', 'node_type', 'dimension_hint', 'title', 'summary', 'instruction', 'estimated_time'] as $field) {
            if (! is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                return false;
            }
        }
        if (! in_array((string) $row['dimension_hint'], ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
            return false;
        }

        return ($row['creates_score_change'] ?? true) === false
            && ($row['creates_career_match'] ?? true) === false
            && ($row['frontend_fallback_allowed'] ?? true) === false;
    }
}
