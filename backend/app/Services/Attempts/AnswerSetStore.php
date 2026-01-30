<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnswerSetStore
{
    public function storeFinalAnswers(Attempt $attempt, array $answers, int $durationMs, string $scoringSpecVersion): array
    {
        if (!Schema::hasTable('attempt_answer_sets')) {
            return [
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'attempt_answer_sets missing.',
            ];
        }

        $normalized = $this->canonicalizeAnswers($answers);
        $canonicalJson = $this->encodeJson($normalized);
        $answersHash = hash('sha256', $canonicalJson);
        $compressed = $this->compressJson($canonicalJson);

        $now = now();
        DB::table('attempt_answer_sets')->updateOrInsert([
            'attempt_id' => (string) $attempt->id,
        ], [
            'org_id' => (int) ($attempt->org_id ?? 0),
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'scoring_spec_version' => $scoringSpecVersion !== '' ? $scoringSpecVersion : null,
            'answers_json' => $compressed,
            'answers_hash' => $answersHash,
            'question_count' => count($normalized),
            'duration_ms' => $durationMs,
            'submitted_at' => $now,
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'answers_hash' => $answersHash,
            'answers_json' => $compressed,
            'canonical_json' => $canonicalJson,
            'question_count' => count($normalized),
        ];
    }

    public function canonicalizeAnswers(array $answers): array
    {
        $normalized = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }

            $entry = [
                'question_id' => $qid,
                'question_index' => isset($answer['question_index']) && is_numeric($answer['question_index'])
                    ? (int) $answer['question_index']
                    : null,
                'question_type' => isset($answer['question_type']) ? (string) $answer['question_type'] : null,
                'code' => isset($answer['code']) ? (string) $answer['code'] : null,
                'answer' => $answer['answer'] ?? ($answer['value'] ?? null),
            ];

            $entry['answer'] = $this->sortKeysRecursively($entry['answer']);
            $normalized[$qid] = $entry;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    public function canonicalJson(array $answers): string
    {
        return $this->encodeJson($this->canonicalizeAnswers($answers));
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function compressJson(string $json): string
    {
        $encoded = gzencode($json, 9);
        if ($encoded === false) {
            return base64_encode($json);
        }
        return base64_encode($encoded);
    }

    private function sortKeysRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = $this->sortKeysRecursively($item);
            }
            return $value;
        }

        $result = [];
        foreach ($value as $item) {
            $result[] = $this->sortKeysRecursively($item);
        }
        return $result;
    }
}
