<?php

namespace App\Services\Assessment;

use App\Models\Attempt;
use App\Models\Result;

class GenericReportBuilder
{
    public function build(Attempt $attempt, Result $result): array
    {
        $resultJson = $result->result_json;
        if (!is_array($resultJson)) {
            $resultJson = [];
        }

        $rawScore = $resultJson['raw_score'] ?? null;
        $finalScore = $resultJson['final_score'] ?? null;
        $breakdown = $resultJson['breakdown_json'] ?? [];
        if (!is_array($breakdown)) {
            $breakdown = [];
        }

        $severity = null;
        if (is_array($breakdown['severity'] ?? null)) {
            $severity = $breakdown['severity'];
        }

        $typeCode = $resultJson['type_code'] ?? $result->type_code ?? null;
        $typeCode = is_string($typeCode) && $typeCode !== '' ? $typeCode : null;

        return [
            'schema_version' => 'report.v0.3',
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'summary' => [
                'title' => (string) ($attempt->scale_code ?? 'Assessment Report'),
                'raw_score' => $rawScore,
                'final_score' => $finalScore,
                'severity' => $severity,
                'type_code' => $typeCode,
            ],
            'scores' => $resultJson['axis_scores_json'] ?? null,
            'breakdown' => $breakdown,
            'generated_at' => now()->toISOString(),
        ];
    }
}
