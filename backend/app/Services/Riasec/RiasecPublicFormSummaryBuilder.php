<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;

final class RiasecPublicFormSummaryBuilder
{
    public function build(?Attempt $attempt, ?Result $result = null): ?array
    {
        $formCode = trim((string) data_get($attempt?->answers_summary_json, 'meta.form_code', ''));
        if ($formCode === '') {
            $formCode = trim((string) data_get($result?->result_json, 'form_code', ''));
        }
        if ($formCode === '') {
            $formCode = ((int) ($attempt?->question_count ?? 0)) >= 140 ? 'riasec_140' : 'riasec_60';
        }

        $questionCount = (int) ($attempt?->question_count ?? 0);
        if ($questionCount <= 0) {
            $questionCount = str_contains($formCode, '140') ? 140 : 60;
        }

        return [
            'form_code' => $formCode,
            'question_count' => $questionCount,
            'form_kind' => str_contains($formCode, '140') ? 'enhanced' : 'standard',
            'estimated_minutes' => str_contains($formCode, '140') ? 18 : 8,
        ];
    }
}
