<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

final class EnneagramAnalyticsMetricCatalog
{
    /**
     * @return list<array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->metric(
                'top_gap_distribution',
                'Top Gap 分布',
                '同一 form 内 Top1 与 Top2 gap 的分布，用于观察 clear / close-call 语气的结构基础。',
                'results.result_json.enneagram_public_projection_v2.classification.dominance_gap_abs',
                '所有已生成 ENNEAGRAM projection v2 的结果',
                ['results', 'report_snapshots'],
                'operational',
                '不要求 Day7 反馈样本',
                '只公开聚合后的 gap 分布，不公开单次结果原始答题。',
                true,
            ),
            $this->metric(
                'close_call_rate',
                'Close-call Rate',
                'interpretation_scope 为 close_call 的结果占比。',
                'interpretation_scope = close_call 的结果数',
                '所有已生成 ENNEAGRAM projection v2 的结果',
                ['results', 'report_snapshots'],
                'operational',
                '样本达到稳定出题覆盖即可长期跟踪',
                '只对聚合结果做比例统计。',
                true,
            ),
            $this->metric(
                'diffuse_rate',
                'Diffuse Rate',
                'interpretation_scope 为 diffuse 的结果占比。',
                'interpretation_scope = diffuse 的结果数',
                '所有已生成 ENNEAGRAM projection v2 的结果',
                ['results', 'report_snapshots'],
                'operational',
                '样本达到稳定出题覆盖即可长期跟踪',
                '只对聚合结果做比例统计。',
                true,
            ),
            $this->metric(
                'low_quality_rate',
                'Low-quality Rate',
                '触发 low_quality 边界的结果占比。',
                'classification.low_quality_status != not_triggered 的结果数',
                '所有已生成 ENNEAGRAM projection v2 的结果',
                ['results', 'report_snapshots'],
                'operational',
                '样本达到稳定出题覆盖即可长期跟踪',
                '仅使用策略输出，不回溯原始答案。',
                true,
            ),
            $this->metric(
                'pair_frequency',
                'Pair Frequency',
                'close-call pair 在结果中的出现频率，用于更新 pair library 与 close-call 覆盖重点。',
                'classification.close_call_pair 或 dynamics.close_call_pair 的 pair 计数',
                '所有 close_call 结果',
                ['results', 'report_snapshots'],
                'operational',
                '样本达到稳定 close-call 覆盖即可长期跟踪',
                '只统计 pair 级聚合频率。',
                true,
            ),
            $this->metric(
                'top1_resonance_rate',
                'Top1 Resonance Rate',
                'Day7 反馈把 final_resonance 指向 top1 的比例。',
                'day7 final_resonance = top1 的 observation 数',
                '所有提交 Day7 反馈的 observation',
                ['enneagram_observation_states'],
                'collecting',
                '需要稳定 Day7 回收样本后才适合公开数值',
                '只统计 observation 聚合结果，不展示个人确认内容。',
                true,
            ),
            $this->metric(
                'top2_resonance_rate',
                'Top2 Resonance Rate',
                'Day7 反馈把 final_resonance 指向 top2 的比例。',
                'day7 final_resonance = top2 的 observation 数',
                '所有提交 Day7 反馈的 observation',
                ['enneagram_observation_states'],
                'collecting',
                '需要稳定 Day7 回收样本后才适合公开数值',
                '只统计 observation 聚合结果，不展示个人确认内容。',
                true,
            ),
            $this->metric(
                'retake_consistency_index',
                'Retake Consistency Index',
                '同一用户在同一 form 上重测后 Top1/Top3 的一致性指标。',
                '满足 retake 条件的同 form attempt 对中保持一致的计数',
                '所有满足同 form retake 条件的 ENNEAGRAM attempt 对',
                ['attempts', 'results'],
                'pending_sample',
                '需要累计稳定的同 form retake 样本后才适合公开',
                '只做匿名聚合，不返回任何个人 retake 链路。',
                true,
            ),
            $this->metric(
                'e105_fc144_agreement',
                'E105 / FC144 Agreement',
                '同一用户在 E105 与 FC144 间 Top1/Top3 的重叠度定义位。',
                '满足 form switch 条件的 attempt 对中 Top1 或 Top3 重叠的计数',
                '所有满足 E105 与 FC144 配对条件的 ENNEAGRAM attempt 对',
                ['attempts', 'results'],
                'collecting',
                '需要双 form 配对样本积累后才适合公开',
                '不把 agreement 解释成跨 form 可直接数值比较。',
                true,
            ),
            $this->metric(
                'close_call_conversion',
                'Close-call Conversion',
                'close-call 结果进一步进入 FC144 或 Day7 完成反馈的转化定义位。',
                'close_call 结果后续产生 FC144 或 Day7 行为的计数',
                '所有 close_call 结果',
                ['results', 'attempts', 'enneagram_observation_states', 'events'],
                'collecting',
                '需要 close-call 后续行为样本稳定后才适合公开',
                '只看聚合行为漏斗，不看个人决策理由。',
                true,
            ),
            $this->metric(
                'misidentification_matrix',
                'Misidentification Matrix',
                '初次 Top1 与 Day7 自我观察确认之间的转移矩阵定义位。',
                'Top1 -> user_confirmed_type 的配对计数',
                '所有提交 Day7 且给出 user_confirmed_type 的 observation',
                ['results', 'enneagram_observation_states'],
                'pending_sample',
                '需要更长时间的 Day7 样本积累后才适合公开',
                '只公开矩阵级别聚合，不公开个人确认记录。',
                true,
            ),
            $this->metric(
                'observation_completion_rate',
                'Observation Completion Rate',
                '已分配 observation 中达到 Day7 或 completion_rate=100 的比例。',
                '完成 observation 的状态数',
                '所有已分配 observation 的状态数',
                ['enneagram_observation_states'],
                'operational',
                '当前 observation 表即可稳定计算',
                '只统计任务完成率，不公开用户反馈正文。',
                true,
            ),
            $this->metric(
                'day7_return_rate',
                'Day7 Return Rate',
                '已分配 7 天观察任务后，最终提交 Day7 feedback 的比例。',
                'day7_submitted_at 非空的 observation 数',
                '所有 assigned_at 非空的 observation 数',
                ['enneagram_observation_states'],
                'operational',
                '当前 observation 表即可稳定计算',
                '只统计任务回收率，不公开用户反馈正文。',
                true,
            ),
            $this->metric(
                'form_switch_rate',
                'Form Switch Rate',
                '用户在 ENNEAGRAM 内从 E105 切换到 FC144，或从一个 form 进入另一个 form 的比例定义位。',
                '存在 form 切换行为的用户/attempt 计数',
                '所有满足 ENNEAGRAM 多次测量条件的用户/attempt',
                ['attempts', 'results'],
                'collecting',
                '需要足够多的 form switch 样本后才适合公开',
                '只公开聚合比例，不公开用户级 form 迁移轨迹。',
                true,
            ),
            $this->metric(
                'low_quality_close_call_relation',
                'Low-quality / Close-call Relation',
                'low_quality 与 close_call 在同一批结果中的关联分布。',
                '触发 low_quality 与 close_call 条件的交叉计数',
                '所有已生成 ENNEAGRAM projection v2 的结果',
                ['results', 'report_snapshots'],
                'operational',
                '当前策略输出即可稳定计算交叉分布',
                '只看聚合策略分布，不对个人结果做标签化外推。',
                true,
            ),
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    public function dataStatusSummary(): array
    {
        $summary = [
            'operational' => [],
            'collecting' => [],
            'pending_sample' => [],
            'unavailable' => [],
        ];

        foreach ($this->definitions() as $definition) {
            $status = (string) ($definition['data_status'] ?? 'unavailable');
            $summary[$status][] = (string) $definition['metric_key'];
        }

        return $summary;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function publicDefinitions(): array
    {
        $definitions = [];
        foreach ($this->definitions() as $definition) {
            if (! (bool) ($definition['technical_note_visible'] ?? false)) {
                continue;
            }

            $rawStatus = (string) ($definition['data_status'] ?? 'unavailable');
            $definition['data_status_source'] = $rawStatus;
            $definition['data_status'] = $this->mapTechnicalNoteStatus($rawStatus);
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string,list<string>>
     */
    public function publicDataStatusSummary(): array
    {
        $summary = [
            'currently_operational' => [],
            'collecting_data' => [],
            'pending_sample' => [],
            'unavailable' => [],
        ];

        foreach ($this->publicDefinitions() as $definition) {
            $status = (string) ($definition['data_status'] ?? 'unavailable');
            $summary[$status][] = (string) $definition['metric_key'];
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function metric(
        string $metricKey,
        string $label,
        string $description,
        string $numeratorSource,
        string $denominatorSource,
        array $requiredSources,
        string $dataStatus,
        string $minimumSampleGuidance,
        string $privacyNotes,
        bool $technicalNoteVisible,
    ): array {
        return [
            'metric_key' => $metricKey,
            'label' => $label,
            'description' => $description,
            'numerator_source' => $numeratorSource,
            'denominator_source' => $denominatorSource,
            'required_sources' => $requiredSources,
            'data_status' => $dataStatus,
            'minimum_sample_guidance' => $minimumSampleGuidance,
            'privacy_notes' => $privacyNotes,
            'technical_note_visible' => $technicalNoteVisible,
        ];
    }

    private function mapTechnicalNoteStatus(string $status): string
    {
        return match (trim($status)) {
            'operational' => 'currently_operational',
            'collecting' => 'collecting_data',
            'pending_sample' => 'pending_sample',
            default => 'unavailable',
        };
    }
}
