<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AnswerRowWriter
{
    public function __construct(
        private readonly ScaleIdentityWriteProjector $identityProjector,
    ) {}

    public function writeRows(Attempt $attempt, array $answers, int $durationMs): array
    {
        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === 'CLINICAL_COMBO_68') {
            return ['ok' => true, 'skipped' => true, 'rows' => 0];
        }

        $isSds20 = $scaleCode === 'SDS_20';
        if (! $this->isEnabled() && ! $isSds20) {
            return ['ok' => true, 'skipped' => true, 'rows' => 0];
        }

        $rows = [];
        $now = now();
        $canWriteIdentity = $this->shouldWriteScaleIdentityColumns();
        $identity = $canWriteIdentity
            ? $this->identityProjector->projectFromAttempt($attempt)
            : ['scale_code_v2' => null, 'scale_uid' => null];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }

            $answerPayload = $isSds20
                ? [
                    'question_id' => $qid,
                    'redacted' => true,
                ]
                : $answer;

            $row = [
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
                'answer_json' => json_encode($answerPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => $durationMs,
                'submitted_at' => $now,
                'created_at' => $now,
            ];

            if ($canWriteIdentity) {
                $row['scale_code_v2'] = $identity['scale_code_v2'];
                $row['scale_uid'] = $identity['scale_uid'];
            }

            $rows[] = $row;
        }

        if (empty($rows)) {
            return ['ok' => true, 'rows' => 0];
        }

        $driver = DB::connection()->getDriverName();

        // SQLite 的 ON CONFLICT 必须命中真实 UNIQUE/PK；当前表在 sqlite 口径下应使用 (attempt_id, question_id)
        $uniqueBy = ['attempt_id', 'question_id'];

        // MySQL 的 upsert 实际走 ON DUPLICATE KEY，不依赖 uniqueBy；保持与分区键口径一致即可
        if ($driver === 'mysql') {
            $uniqueBy = ['attempt_id', 'question_id', 'submitted_at'];
        }

        $updateColumns = ['org_id', 'scale_code', 'question_index', 'question_type', 'answer_json', 'duration_ms', 'submitted_at'];
        if ($canWriteIdentity) {
            $updateColumns[] = 'scale_code_v2';
            $updateColumns[] = 'scale_uid';
        }

        try {
            DB::table('attempt_answer_rows')->upsert(
                $rows,
                $uniqueBy,
                $updateColumns
            );
        } catch (QueryException $exception) {
            if (! $canWriteIdentity || ! $this->isMissingScaleIdentityColumnError($exception)) {
                throw $exception;
            }

            foreach ($rows as &$row) {
                unset($row['scale_code_v2'], $row['scale_uid']);
            }
            unset($row);

            $fallbackColumns = array_values(array_filter(
                $updateColumns,
                static fn (string $column): bool => ! in_array($column, ['scale_code_v2', 'scale_uid'], true)
            ));

            DB::table('attempt_answer_rows')->upsert(
                $rows,
                $uniqueBy,
                $fallbackColumns
            );
        }

        return ['ok' => true, 'rows' => count($rows)];
    }

    private function isEnabled(): bool
    {
        $mode = strtolower((string) config('fap_attempts.answer_rows_write_mode', 'off'));

        return $mode === 'on' || $mode === 'true' || $mode === '1';
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
