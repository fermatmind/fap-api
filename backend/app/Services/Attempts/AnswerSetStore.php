<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AnswerSetStore
{
    public function __construct(
        private readonly ScaleIdentityWriteProjector $identityProjector,
    ) {}

    public function storeFinalAnswers(Attempt $attempt, array $answers, int $durationMs, string $scoringSpecVersion): array
    {
        $normalized = $this->canonicalizeAnswers($answers);
        $canonicalJson = $this->encodeJson($normalized);
        $answersHash = hash('sha256', $canonicalJson);
        $compressed = $this->compressJson($canonicalJson);
        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        $isSensitiveScale = in_array($scaleCode, ['CLINICAL_COMBO_68', 'SDS_20'], true);
        $storedAnswersJson = $isSensitiveScale ? null : $compressed;

        $now = now();
        $upsertValues = [
            'org_id' => (int) ($attempt->org_id ?? 0),
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'scoring_spec_version' => $scoringSpecVersion !== '' ? $scoringSpecVersion : null,
            'answers_json' => $storedAnswersJson,
            'answers_hash' => $answersHash,
            'question_count' => count($normalized),
            'duration_ms' => $durationMs,
            'submitted_at' => $now,
            'created_at' => $now,
        ];

        $canWriteIdentity = $this->shouldWriteScaleIdentityColumns();
        if ($canWriteIdentity) {
            $identity = $this->identityProjector->projectFromAttempt($attempt);
            $upsertValues['scale_code_v2'] = $identity['scale_code_v2'];
            $upsertValues['scale_uid'] = $identity['scale_uid'];
        }

        try {
            DB::table('attempt_answer_sets')->updateOrInsert([
                'attempt_id' => (string) $attempt->id,
            ], $upsertValues);
        } catch (QueryException $exception) {
            if (! $canWriteIdentity || ! $this->isMissingScaleIdentityColumnError($exception)) {
                throw $exception;
            }

            unset($upsertValues['scale_code_v2'], $upsertValues['scale_uid']);
            DB::table('attempt_answer_sets')->updateOrInsert([
                'attempt_id' => (string) $attempt->id,
            ], $upsertValues);
        }

        return [
            'ok' => true,
            'answers_hash' => $answersHash,
            'answers_json' => $storedAnswersJson,
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
        // Keep a deterministic base64 payload and avoid gzip OOM on low-memory CI lanes.
        return base64_encode($json);
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

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function isMissingScaleIdentityColumnError(QueryException $exception): bool
    {
        $sqlState = strtoupper(trim((string) ($exception->errorInfo[0] ?? '')));
        $message = strtolower($exception->getMessage());

        if ($sqlState === '42S22') {
            return true;
        }

        return str_contains($message, 'no such column')
            || str_contains($message, 'has no column named')
            || str_contains($message, 'unknown column');
    }
}
