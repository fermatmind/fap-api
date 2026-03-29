<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillAttemptAnswerSetsScaleIdentity extends Command
{
    protected $signature = 'ops:backfill-attempt-answer-sets-scale-identity
        {--chunk=1000 : Chunk size}
        {--dry-run : Compute and report only; do not write updates}';

    protected $description = 'Backfill attempt_answer_sets.scale_code_v2 and attempt_answer_sets.scale_uid from scale_code';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('attempt_answer_sets')) {
            $this->warn('attempt_answer_sets table missing, skipping.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('attempt_answer_sets', 'scale_code_v2') || ! Schema::hasColumn('attempt_answer_sets', 'scale_uid')) {
            $this->warn('attempt_answer_sets scale identity columns missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skippedUnknown = 0;
        $lastAttemptId = '';

        do {
            $rows = DB::table('attempt_answer_sets')
                ->select(['attempt_id', 'scale_code', 'scale_code_v2', 'scale_uid'])
                ->where(function ($q): void {
                    $q->whereNull('scale_code_v2')
                        ->orWhere('scale_code_v2', '')
                        ->orWhereNull('scale_uid')
                        ->orWhere('scale_uid', '');
                })
                ->when($lastAttemptId !== '', fn ($q) => $q->where('attempt_id', '>', $lastAttemptId))
                ->orderBy('attempt_id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $attemptId = trim((string) ($row->attempt_id ?? ''));
                if ($attemptId === '') {
                    continue;
                }
                $lastAttemptId = $attemptId;
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

                    DB::table('attempt_answer_sets')
                        ->where('attempt_id', $attemptId)
                        ->update($payload);
                }

                $updated++;
            }
        } while (true);

        $this->info(sprintf(
            'backfill_attempt_answer_sets_scale_identity scanned=%d updated=%d skipped_unknown=%d dry_run=%d',
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
