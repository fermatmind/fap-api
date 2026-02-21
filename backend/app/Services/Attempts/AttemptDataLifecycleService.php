<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttemptDataLifecycleService
{
    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function purgeAttempt(string $attemptId, int $orgId, array $context = []): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_ID_REQUIRED',
            ];
        }

        $attempt = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if ($attempt === null) {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
            ];
        }

        $counts = [
            'results_deleted' => 0,
            'report_snapshots_deleted' => 0,
            'shares_deleted' => 0,
            'report_jobs_deleted' => 0,
            'attempts_redacted' => 0,
        ];

        DB::transaction(function () use ($attemptId, $orgId, &$counts, $context): void {
            $counts['results_deleted'] = DB::table('results')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->delete();

            $counts['report_snapshots_deleted'] = DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->delete();

            if (Schema::hasTable('shares')) {
                $counts['shares_deleted'] = DB::table('shares')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }

            if (Schema::hasTable('report_jobs')) {
                $counts['report_jobs_deleted'] = DB::table('report_jobs')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }

            $updates = [
                'answers_hash' => null,
                'answers_storage_path' => null,
                'result_json' => null,
                'type_code' => null,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('attempts', 'answers_json')) {
                $updates['answers_json'] = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (Schema::hasColumn('attempts', 'answers_summary_json')) {
                $updates['answers_summary_json'] = json_encode([
                    'stage' => 'purged',
                    'reason' => (string) ($context['reason'] ?? 'user_request'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (Schema::hasColumn('attempts', 'calculation_snapshot_json')) {
                $updates['calculation_snapshot_json'] = json_encode([
                    'purged' => true,
                    'purged_at' => now()->toIso8601String(),
                    'reason' => (string) ($context['reason'] ?? 'user_request'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (Schema::hasColumn('attempts', 'norm_version')) {
                $updates['norm_version'] = null;
            }

            $counts['attempts_redacted'] = DB::table('attempts')
                ->where('id', $attemptId)
                ->where('org_id', $orgId)
                ->update($updates);

            if (Schema::hasTable('data_lifecycle_requests')) {
                DB::table('data_lifecycle_requests')->insert([
                    'org_id' => $orgId,
                    'request_type' => 'attempt_purge',
                    'status' => 'done',
                    'requested_by_admin_user_id' => null,
                    'approved_by_admin_user_id' => null,
                    'subject_ref' => $attemptId,
                    'reason' => (string) ($context['reason'] ?? 'user_request'),
                    'result' => 'success',
                    'payload_json' => json_encode([
                        'attempt_id' => $attemptId,
                        'scale_code' => (string) ($context['scale_code'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'result_json' => json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'approved_at' => now(),
                    'executed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'counts' => $counts,
        ];
    }
}

