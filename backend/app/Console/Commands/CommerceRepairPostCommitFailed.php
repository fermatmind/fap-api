<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Commerce\ReprocessPaymentEventJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommerceRepairPostCommitFailed extends Command
{
    protected $signature = 'commerce:repair-post-commit-failed
        {--org_id=0 : Organization id}
        {--older_than_minutes=5 : Ignore very fresh failures}
        {--limit=50 : Max events to queue}
        {--max_attempts=12 : Skip very old events after too many attempts}
        {--include_semantic_rejects=1 : Also retry orphan/rejected semantic failures}
        {--dry-run=0 : Preview candidates only}
        {--json=0 : Output json summary}';

    protected $description = 'Queue replay for post-commit failures and repairable semantic rejects.';

    public function handle(): int
    {
        $orgId = max(0, (int) $this->option('org_id'));
        $olderThanMinutes = max(0, (int) $this->option('older_than_minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $maxAttempts = max(1, (int) $this->option('max_attempts'));
        $includeSemanticRejects = $this->isTruthy($this->option('include_semantic_rejects'));
        $dryRun = $this->isTruthy($this->option('dry-run'));

        $events = $this->collectEvents($orgId, $olderThanMinutes, $limit, $maxAttempts, $includeSemanticRejects);

        $summary = [
            'dry_run' => $dryRun,
            'candidate_count' => count($events),
            'queued_count' => 0,
            'results' => [],
        ];

        foreach ($events as $event) {
            $summary['results'][] = [
                'payment_event_id' => (string) ($event->id ?? ''),
                'provider_event_id' => (string) ($event->provider_event_id ?? ''),
                'order_no' => (string) ($event->order_no ?? ''),
                'status' => (string) ($event->status ?? ''),
                'last_error_code' => (string) ($event->last_error_code ?? ''),
            ];

            if ($dryRun) {
                continue;
            }

            DB::table('payment_events')
                ->where('id', (string) ($event->id ?? ''))
                ->where('org_id', (int) ($event->org_id ?? $orgId))
                ->update([
                    'handle_status' => 'queued',
                    'updated_at' => now(),
                ]);

            ReprocessPaymentEventJob::dispatch(
                (string) ($event->id ?? ''),
                (int) ($event->org_id ?? $orgId),
                'scheduled_payment_repair',
                (string) Str::uuid(),
            );

            $summary['queued_count']++;
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array<int,object>
     */
    private function collectEvents(
        int $orgId,
        int $olderThanMinutes,
        int $limit,
        int $maxAttempts,
        bool $includeSemanticRejects
    ): array {
        $cutoff = now()->subMinutes($olderThanMinutes);
        $semanticRejectCodes = [
            'ORDER_NOT_FOUND',
            'PROVIDER_MISMATCH',
            'AMOUNT_MISMATCH',
            'CURRENCY_MISMATCH',
            'ATTEMPT_OWNER_MISMATCH',
            'ATTEMPT_SCALE_MISMATCH',
            'SKU_NOT_FOUND',
            'BENEFIT_CODE_NOT_FOUND',
            'ATTEMPT_REQUIRED',
        ];

        return DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('updated_at', '<=', $cutoff)
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($statusQuery) use ($includeSemanticRejects, $semanticRejectCodes): void {
                $statusQuery->where('status', 'post_commit_failed');

                if (! $includeSemanticRejects) {
                    return;
                }

                $statusQuery->orWhere(function ($semanticQuery) use ($semanticRejectCodes): void {
                    $semanticQuery->whereIn('status', ['rejected', 'orphan'])
                        ->whereIn('last_error_code', $semanticRejectCodes);
                });
            })
            ->where(function ($queueQuery): void {
                $queueQuery->whereNull('handle_status')
                    ->orWhere('handle_status', '!=', 'queued');
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('Commerce payment-event repair summary');
        $this->line('dry_run='.(string) ($summary['dry_run'] ? '1' : '0'));
        $this->line('candidate_count='.(string) $summary['candidate_count']);
        $this->line('queued_count='.(string) $summary['queued_count']);
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
