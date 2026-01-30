<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnswerRowWriter
{
    public function writeRows(Attempt $attempt, array $answers, int $durationMs): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => true, 'skipped' => true, 'rows' => 0];
        }

        if (!Schema::hasTable('attempt_answer_rows')) {
            return ['ok' => false, 'error' => 'TABLE_MISSING', 'message' => 'attempt_answer_rows missing.'];
        }

        $rows = [];
        $now = now();
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }

            $rows[] = [
                'attempt_id' => (string) $attempt->id,
                'org_id' => (int) ($attempt->org_id ?? 0),
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'question_id' => $qid,
                'question_index' => isset($answer['question_index']) && is_numeric($answer['question_index'])
                    ? (int) $answer['question_index']
                    : 0,
                'question_type' => isset($answer['question_type'])
                    ? (string) $answer['question_type']
                    : (string) ($answer['type'] ?? ''),
                'answer_json' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => $durationMs,
                'submitted_at' => $now,
                'created_at' => $now,
            ];
        }

        if (empty($rows)) {
            return ['ok' => true, 'rows' => 0];
        }

        DB::table('attempt_answer_rows')->upsert(
            $rows,
            ['attempt_id', 'question_id', 'submitted_at'],
            ['org_id', 'scale_code', 'question_index', 'question_type', 'answer_json', 'duration_ms', 'submitted_at']
        );

        return ['ok' => true, 'rows' => count($rows)];
    }

    private function isEnabled(): bool
    {
        $mode = strtolower((string) config('fap_attempts.answer_rows_write_mode', 'off'));
        return $mode === 'on' || $mode === 'true' || $mode === '1';
    }
}
