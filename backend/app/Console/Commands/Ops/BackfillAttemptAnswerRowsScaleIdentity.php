<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillAttemptAnswerRowsScaleIdentity extends Command
{
    protected $signature = 'ops:backfill-attempt-answer-rows-scale-identity
        {--chunk=1000 : Chunk size}
        {--dry-run : Compute and report only; do not write updates}';

    protected $description = 'Backfill attempt_answer_rows.scale_code_v2 and attempt_answer_rows.scale_uid from scale_code';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('attempt_answer_rows')) {
            $this->warn('attempt_answer_rows table missing, skipping.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('attempt_answer_rows', 'scale_code_v2') || ! Schema::hasColumn('attempt_answer_rows', 'scale_uid')) {
            $this->warn('attempt_answer_rows scale identity columns missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skippedUnknown = 0;

        $lastAttemptId = '';
        $lastQuestionId = '';
        $lastSubmittedAt = '1970-01-01 00:00:00';

        do {
            $rows = DB::table('attempt_answer_rows')
                ->select(['attempt_id', 'question_id', 'submitted_at', 'scale_code', 'scale_code_v2', 'scale_uid'])
                ->where(function ($q): void {
                    $q->whereNull('scale_code_v2')
                        ->orWhere('scale_code_v2', '')
                        ->orWhereNull('scale_uid')
                        ->orWhere('scale_uid', '');
                })
                ->when($lastAttemptId !== '', function ($q) use ($lastAttemptId, $lastQuestionId, $lastSubmittedAt): void {
                    $q->where(function ($cursor) use ($lastAttemptId, $lastQuestionId, $lastSubmittedAt): void {
                        $cursor->where('attempt_id', '>', $lastAttemptId)
                            ->orWhere(function ($second) use ($lastAttemptId, $lastQuestionId): void {
                                $second->where('attempt_id', $lastAttemptId)
                                    ->where('question_id', '>', $lastQuestionId);
                            })
                            ->orWhere(function ($third) use ($lastAttemptId, $lastQuestionId, $lastSubmittedAt): void {
                                $third->where('attempt_id', $lastAttemptId)
                                    ->where('question_id', $lastQuestionId)
                                    ->whereRaw("coalesce(submitted_at, '1970-01-01 00:00:00') > ?", [$lastSubmittedAt]);
                            });
                    });
                })
                ->orderBy('attempt_id')
                ->orderBy('question_id')
                ->orderBy('submitted_at')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $attemptId = trim((string) ($row->attempt_id ?? ''));
                $questionId = trim((string) ($row->question_id ?? ''));
                if ($attemptId === '' || $questionId === '') {
                    continue;
                }

                $submittedAtRaw = (string) ($row->submitted_at ?? '');
                $submittedAt = trim($submittedAtRaw);
                if ($submittedAt === '') {
                    $submittedAt = '1970-01-01 00:00:00';
                }

                $lastAttemptId = $attemptId;
                $lastQuestionId = $questionId;
                $lastSubmittedAt = $submittedAt;

                $scanned++;

                $legacyCode = $this->resolveLegacyCode($row);
                if ($legacyCode === '') {
                    $skippedUnknown++;

                    continue;
                }

                $identity = $this->identityResolver->resolveByAnyCode($legacyCode);
                if (! is_array($identity) || ! (bool) ($identity['is_known'] ?? false)) {
                    $skippedUnknown++;

                    continue;
                }

                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? '')));
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
                if ($resolvedV2 === '') {
                    $skippedUnknown++;

                    continue;
                }

                $currentV2 = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
                $currentUid = trim((string) ($row->scale_uid ?? ''));
                $needsV2 = ($currentV2 === '' || $currentV2 !== $resolvedV2);
                $needsUid = ($resolvedUid !== '' && $currentUid !== $resolvedUid);
                if (! $needsV2 && ! $needsUid) {
                    continue;
                }

                if (! $dryRun) {
                    $payload = [
                        'scale_code_v2' => $resolvedV2,
                    ];
                    if ($resolvedUid !== '') {
                        $payload['scale_uid'] = $resolvedUid;
                    }

                    $updateQuery = DB::table('attempt_answer_rows')
                        ->where('attempt_id', $attemptId)
                        ->where('question_id', $questionId);

                    if (trim((string) ($row->submitted_at ?? '')) === '') {
                        $updateQuery->whereNull('submitted_at');
                    } else {
                        $updateQuery->where('submitted_at', $row->submitted_at);
                    }

                    $updateQuery->update($payload);
                }

                $updated++;
            }
        } while (true);

        $this->info(sprintf(
            'backfill_attempt_answer_rows_scale_identity scanned=%d updated=%d skipped_unknown=%d dry_run=%d',
            $scanned,
            $updated,
            $skippedUnknown,
            $dryRun ? 1 : 0
        ));

        return self::SUCCESS;
    }

    private function resolveLegacyCode(object $row): string
    {
        $legacy = strtoupper(trim((string) ($row->scale_code ?? '')));
        if ($legacy !== '') {
            return $legacy;
        }

        $attemptId = trim((string) ($row->attempt_id ?? ''));
        if ($attemptId === '' || ! Schema::hasTable('attempts')) {
            return '';
        }

        $attempt = DB::table('attempts')
            ->select(['scale_code'])
            ->where('id', $attemptId)
            ->first();
        if (! $attempt) {
            return '';
        }

        return strtoupper(trim((string) ($attempt->scale_code ?? '')));
    }
}
