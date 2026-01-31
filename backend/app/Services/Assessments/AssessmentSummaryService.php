<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\Result;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Collection;

class AssessmentSummaryService
{
    public function __construct(private ScaleRegistry $registry)
    {
    }

    public function buildSummary(Assessment $assessment): array
    {
        $orgId = (int) $assessment->org_id;

        $assignments = AssessmentAssignment::query()
            ->where('org_id', $orgId)
            ->where('assessment_id', (int) $assessment->id)
            ->get(['attempt_id', 'completed_at']);

        $total = $assignments->count();
        $completedAssignments = $assignments->filter(function ($row) {
            return $row->completed_at !== null;
        });

        $completed = $completedAssignments->count();
        $attemptIds = $completedAssignments
            ->pluck('attempt_id')
            ->filter(function ($val) {
                return is_string($val) && trim($val) !== '';
            })
            ->map(function ($val) {
                return trim((string) $val);
            })
            ->values()
            ->all();

        $results = $attemptIds !== []
            ? Result::query()
                ->where('org_id', $orgId)
                ->whereIn('attempt_id', $attemptIds)
                ->get(['attempt_id', 'type_code', 'scores_pct', 'result_json'])
            : collect();

        $driverType = $this->resolveDriverType($assessment);

        return [
            'completion_rate' => [
                'completed' => $completed,
                'total' => $total,
            ],
            'due_at' => $assessment->due_at?->toISOString(),
            'window' => [
                'start_at' => $assessment->created_at?->toISOString(),
                'end_at' => $assessment->due_at?->toISOString(),
            ],
            'score_distribution' => $this->scoreDistribution($driverType, $results),
            'dimension_means' => $this->dimensionMeans($driverType, $results),
        ];
    }

    private function resolveDriverType(Assessment $assessment): string
    {
        $code = strtoupper(trim((string) $assessment->scale_code));
        if ($code === '') {
            return '';
        }

        $row = $this->registry->getByCode($code, (int) $assessment->org_id);
        return strtoupper(trim((string) ($row['driver_type'] ?? '')));
    }

    private function scoreDistribution(string $driverType, Collection $results): array
    {
        $counts = [];

        foreach ($results as $row) {
            $label = '';

            $typeCode = trim((string) ($row->type_code ?? ''));
            if ($typeCode !== '') {
                $label = strtoupper($typeCode);
            } else {
                $payload = $row->result_json;
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    $payload = is_array($decoded) ? $decoded : null;
                }
                if (is_array($payload)) {
                    $score = $payload['final_score'] ?? $payload['raw_score'] ?? null;
                    if (is_numeric($score)) {
                        $label = (string) $score;
                    }
                }
            }

            if ($label === '') {
                continue;
            }

            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = [
                'label' => $label,
                'count' => $count,
            ];
        }

        return $out;
    }

    private function dimensionMeans(string $driverType, Collection $results): array
    {
        if ($driverType === 'MBTI') {
            return $this->mbtiMeans($results);
        }

        if ($driverType === 'GENERIC_LIKERT') {
            return $this->likertMeans($results);
        }

        return [];
    }

    private function mbtiMeans(Collection $results): array
    {
        $sum = [];
        $count = [];
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        foreach ($results as $row) {
            $scores = $row->scores_pct;
            if (is_string($scores)) {
                $decoded = json_decode($scores, true);
                $scores = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($scores)) {
                $payload = $row->result_json;
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    $payload = is_array($decoded) ? $decoded : null;
                }
                $scores = is_array($payload['axis_scores_json']['scores_pct'] ?? null)
                    ? $payload['axis_scores_json']['scores_pct']
                    : [];
            }

            foreach ($dims as $dim) {
                $val = $scores[$dim] ?? null;
                if (is_numeric($val)) {
                    $sum[$dim] = ($sum[$dim] ?? 0) + (float) $val;
                    $count[$dim] = ($count[$dim] ?? 0) + 1;
                }
            }
        }

        $means = [];
        foreach ($sum as $dim => $total) {
            $div = (int) ($count[$dim] ?? 0);
            if ($div > 0) {
                $means[$dim] = round($total / $div, 2);
            }
        }

        return $means;
    }

    private function likertMeans(Collection $results): array
    {
        $sum = [];
        $count = [];

        foreach ($results as $row) {
            $payload = $row->result_json;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($payload)) {
                continue;
            }

            $dimensions = $payload['breakdown_json']['dimensions'] ?? [];
            if (!is_array($dimensions)) {
                continue;
            }

            foreach ($dimensions as $dim => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $val = $info['score'] ?? null;
                if (!is_numeric($val)) {
                    continue;
                }
                $sum[$dim] = ($sum[$dim] ?? 0) + (float) $val;
                $count[$dim] = ($count[$dim] ?? 0) + 1;
            }
        }

        $means = [];
        foreach ($sum as $dim => $total) {
            $div = (int) ($count[$dim] ?? 0);
            if ($div > 0) {
                $means[$dim] = round($total / $div, 2);
            }
        }

        return $means;
    }
}
