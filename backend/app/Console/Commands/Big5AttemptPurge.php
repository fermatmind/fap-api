<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Attempts\AttemptDataLifecycleService;
use Illuminate\Console\Command;

final class Big5AttemptPurge extends Command
{
    protected $signature = 'big5:attempt:purge
        {attempt_id : Attempt UUID}
        {--org_id=0 : Organization id}
        {--reason=user_request : Purge reason}';

    protected $description = 'Purge BIG5 attempt data and invalidate report artifacts.';

    public function handle(AttemptDataLifecycleService $lifecycle): int
    {
        $attemptId = trim((string) $this->argument('attempt_id'));
        $orgId = (int) $this->option('org_id');
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $reason = 'user_request';
        }

        if ($attemptId === '') {
            $this->error('attempt_id is required.');

            return 1;
        }
        if ($orgId < 0) {
            $this->error('org_id must be >= 0.');

            return 1;
        }

        $result = $lifecycle->purgeAttempt($attemptId, $orgId, [
            'reason' => $reason,
            'scale_code' => 'BIG5_OCEAN',
        ]);

        if (!($result['ok'] ?? false)) {
            $this->line('status=failed');
            $this->line('error=' . strtoupper((string) ($result['error'] ?? 'UNKNOWN')));

            return 1;
        }

        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $this->line('status=success');
        $this->line('attempt_id=' . (string) ($result['attempt_id'] ?? $attemptId));
        $this->line('org_id=' . (string) ($result['org_id'] ?? $orgId));
        $this->line('results_deleted=' . (string) (($counts['results_deleted'] ?? 0)));
        $this->line('report_snapshots_deleted=' . (string) (($counts['report_snapshots_deleted'] ?? 0)));
        $this->line('shares_deleted=' . (string) (($counts['shares_deleted'] ?? 0)));
        $this->line('report_jobs_deleted=' . (string) (($counts['report_jobs_deleted'] ?? 0)));
        $this->line('attempts_redacted=' . (string) (($counts['attempts_redacted'] ?? 0)));

        return 0;
    }
}

