<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnswerRowWriter
{
    public function writeRows(Attempt $attempt, array $answers, int $durationMs): array
    {
        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === 'CLINICAL_COMBO_68') {
            return ['ok' => true, 'skipped' => true, 'rows' => 0];
        }

        $isSds20 = $scaleCode === 'SDS_20';
        if (!$this->isEnabled() && !$isSds20) {
            return ['ok' => true, 'skipped' => true, 'rows' => 0];
        }

        $rows = [];
        $now = now();
        $canWriteIdentity = $this->shouldWriteScaleIdentityColumns() && $this->attemptAnswerRowsHasIdentityColumns();
        $identity = $canWriteIdentity
            ? $this->resolveScaleIdentityValues($attempt)
            : ['scale_code_v2' => null, 'scale_uid' => null];

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
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

        DB::table('attempt_answer_rows')->upsert(
            $rows,
            $uniqueBy,
            $updateColumns
        );

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

    private function attemptAnswerRowsHasIdentityColumns(): bool
    {
        return Schema::hasTable('attempt_answer_rows')
            && Schema::hasColumn('attempt_answer_rows', 'scale_code_v2')
            && Schema::hasColumn('attempt_answer_rows', 'scale_uid');
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveScaleIdentityValues(Attempt $attempt): array
    {
        $scaleCodeV2 = strtoupper(trim((string) ($attempt->scale_code_v2 ?? '')));
        $scaleUid = trim((string) ($attempt->scale_uid ?? ''));

        if ($scaleCodeV2 !== '' && $scaleUid !== '') {
            return [
                'scale_code_v2' => $scaleCodeV2,
                'scale_uid' => $scaleUid,
            ];
        }

        $scaleCodeV1 = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if ($scaleCodeV1 === '') {
            return [
                'scale_code_v2' => $scaleCodeV2 !== '' ? $scaleCodeV2 : null,
                'scale_uid' => $scaleUid !== '' ? $scaleUid : null,
            ];
        }

        $v1ToV2 = (array) config('scale_identity.code_map_v1_to_v2', []);
        $uidMap = (array) config('scale_identity.scale_uid_map', []);

        if ($scaleCodeV2 === '') {
            $mappedV2 = strtoupper(trim((string) ($v1ToV2[$scaleCodeV1] ?? '')));
            if ($mappedV2 !== '') {
                $scaleCodeV2 = $mappedV2;
            }
        }

        if ($scaleUid === '') {
            $mappedUid = trim((string) ($uidMap[$scaleCodeV1] ?? ''));
            if ($mappedUid !== '') {
                $scaleUid = $mappedUid;
            }
        }

        return [
            'scale_code_v2' => $scaleCodeV2 !== '' ? $scaleCodeV2 : null,
            'scale_uid' => $scaleUid !== '' ? $scaleUid : null,
        ];
    }
}
