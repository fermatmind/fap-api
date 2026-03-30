<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\AttemptChainAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AttemptChainAudit extends Command
{
    protected $signature = 'ops:attempt-chain-audit
        {--attempt-id= : Inspect one attempt id exactly}
        {--window-hours= : Recent window to scan when --attempt-id is omitted}
        {--limit= : Max recent rows per source table}
        {--pending-timeout-minutes= : Threshold for pending/running submissions}
        {--strict= : Exit non-zero when findings exist}
        {--json=1 : Output JSON payload}';

    protected $description = 'Audit bad attempt ids and missing attempt/submission/result/projection chains.';

    public function __construct(
        private readonly AttemptChainAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $policy = is_array(config('ops.attempt_chain_audit')) ? config('ops.attempt_chain_audit') : [];

        $attemptId = $this->normalizeString($this->option('attempt-id'));
        $windowHours = $this->resolvePositiveInt($this->option('window-hours'), (int) ($policy['window_hours'] ?? 24), 24);
        $limit = $this->resolvePositiveInt($this->option('limit'), (int) ($policy['limit'] ?? 200), 200);
        $pendingTimeoutMinutes = $this->resolvePositiveInt(
            $this->option('pending-timeout-minutes'),
            (int) ($policy['pending_timeout_minutes'] ?? 15),
            15
        );
        $strict = $this->resolveStrict($policy);

        $payload = $this->auditService->audit($attemptId, $windowHours, $limit, $pendingTimeoutMinutes);
        $findingsTotal = (int) data_get($payload, 'summary.finding_total', 0);

        if ($findingsTotal > 0) {
            Log::warning('ATTEMPT_CHAIN_AUDIT_FINDINGS', [
                'selection' => $payload['selection'] ?? [],
                'summary' => $payload['summary'] ?? [],
                'inspected_count' => (int) ($payload['inspected_count'] ?? 0),
            ]);
        }

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $selection = is_array($payload['selection'] ?? null) ? $payload['selection'] : [];
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

            $this->info('attempt_chain_audit');
            $this->line(sprintf(
                'attempt_id=%s window_hours=%d limit=%d pending_timeout_minutes=%d strict=%s inspected=%d findings=%d',
                (string) ($selection['attempt_id'] ?? 'recent'),
                (int) ($selection['window_hours'] ?? 0),
                (int) ($selection['limit'] ?? 0),
                (int) ($selection['pending_timeout_minutes'] ?? 0),
                $strict ? '1' : '0',
                (int) ($payload['inspected_count'] ?? 0),
                (int) ($summary['finding_total'] ?? 0),
            ));

            $byIssueCode = is_array($summary['by_issue_code'] ?? null) ? $summary['by_issue_code'] : [];
            foreach ($byIssueCode as $issueCode => $count) {
                $this->line(sprintf('issue=%s total=%d', (string) $issueCode, (int) $count));
            }

            $inspections = is_array($payload['inspections'] ?? null) ? $payload['inspections'] : [];
            foreach ($inspections as $inspection) {
                if (! is_array($inspection)) {
                    continue;
                }

                $findings = is_array($inspection['findings'] ?? null) ? $inspection['findings'] : [];
                if ($findings === []) {
                    continue;
                }

                $codes = [];
                foreach ($findings as $finding) {
                    if (! is_array($finding)) {
                        continue;
                    }

                    $issueCode = trim((string) ($finding['issue_code'] ?? ''));
                    if ($issueCode !== '') {
                        $codes[] = $issueCode;
                    }
                }

                $this->warn(sprintf(
                    '%s source=%s issues=%s',
                    (string) ($inspection['attempt_id'] ?? ''),
                    (string) ($inspection['source'] ?? 'attempt'),
                    implode(',', $codes)
                ));
            }
        }

        if ($strict && $findingsTotal > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function resolveStrict(array $policy): bool
    {
        $strictOption = $this->option('strict');
        if ($strictOption === null || $strictOption === '') {
            return (bool) ($policy['strict_default'] ?? false);
        }

        return $this->isTruthy($strictOption);
    }

    private function resolvePositiveInt(mixed $value, int $configured, int $fallback): int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        if ($configured > 0) {
            return $configured;
        }

        return $fallback;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
