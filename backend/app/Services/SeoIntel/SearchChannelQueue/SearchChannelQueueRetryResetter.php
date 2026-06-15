<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

final class SearchChannelQueueRetryResetter
{
    public function __construct(
        private readonly SearchChannelQueueAuditLogger $events,
    ) {}

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public function reset(
        array $queueItemIds,
        array $channels,
        ?string $reason,
        ?string $approvalPhrase,
        ?string $approvalToken,
        string $actorId,
        bool $dryRun,
    ): array {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $allowedChannels = $this->allowedChannels($channels);
        $reason = $this->normalizedReason($reason);
        $expectedPhrase = $this->approvalPhrase($queueItemIds, $allowedChannels, $reason);
        $expectedToken = hash('sha256', $expectedPhrase);
        $setupIssues = $this->setupIssues($queueItemIds, $allowedChannels, $reason);

        $items = $queueItemIds === []
            ? collect()
            : $connection->table('seo_search_channel_queue_items')
                ->whereIn('id', $queueItemIds)
                ->orderBy('id')
                ->get();

        $foundIds = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $missingIds = array_values(array_diff($queueItemIds, $foundIds));
        $itemResults = [];

        foreach ($missingIds as $missingId) {
            $itemResults[] = $this->blockedItem($missingId, null, null, ['queue_item_not_found'], $dryRun);
        }

        foreach ($items as $item) {
            $latestFailure = $this->latestSubmissionFailure((int) $item->id);
            $itemIssues = $this->validateItem($item, $allowedChannels, $latestFailure);
            $itemResults[] = $itemIssues === []
                ? $this->readyItem($item, $latestFailure, $dryRun)
                : $this->blockedItem((int) $item->id, $item, $latestFailure, $itemIssues, $dryRun);
        }

        $approvalIssues = $dryRun ? [] : $this->approvalIssues($approvalPhrase, $approvalToken, $expectedPhrase, $expectedToken);
        $issues = array_values(array_unique([
            ...$setupIssues,
            ...$approvalIssues,
            ...$this->flattenItemIssues($itemResults),
        ]));

        if ($issues !== []) {
            return $this->payload(
                status: 'blocked',
                dryRun: $dryRun,
                queueItemIds: $queueItemIds,
                channels: $allowedChannels,
                reason: $reason,
                expectedPhrase: $expectedPhrase,
                expectedToken: $expectedToken,
                issues: $issues,
                itemResults: $itemResults,
            );
        }

        if ($dryRun) {
            return $this->payload(
                status: 'success',
                dryRun: true,
                queueItemIds: $queueItemIds,
                channels: $allowedChannels,
                reason: $reason,
                expectedPhrase: $expectedPhrase,
                expectedToken: $expectedToken,
                issues: [],
                itemResults: $itemResults,
            );
        }

        $resetResults = [];
        foreach ($items as $item) {
            $resetResults[] = $this->resetOne($item, $reason, $actorId, $expectedToken);
        }

        $resetIssues = $this->flattenItemIssues($resetResults);

        return $this->payload(
            status: $resetIssues === [] ? 'success' : 'failed',
            dryRun: false,
            queueItemIds: $queueItemIds,
            channels: $allowedChannels,
            reason: $reason,
            expectedPhrase: $expectedPhrase,
            expectedToken: $expectedToken,
            issues: $resetIssues,
            itemResults: $resetResults,
            writesAttempted: true,
            writesCommitted: $resetIssues === [],
        );
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     */
    public function approvalPhrase(array $queueItemIds, array $channels, string $reason): string
    {
        return sprintf(
            'I explicitly approve SEARCH-CHANNEL-QUEUE-RETRY-RESET reset for queue items %s channels %s reason %s.',
            implode(',', $queueItemIds),
            implode(',', $channels),
            $reason,
        );
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function allowedChannels(array $channels): array
    {
        $configured = array_values(config('seo_intel.search_channel_queue.live_submission.allowed_channels', []));
        $requested = $channels === [] ? ['baidu_push'] : $channels;

        return array_values(array_intersect($requested, $configured, ['baidu_push']));
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function setupIssues(array $queueItemIds, array $channels, string $reason): array
    {
        $issues = [];

        if ($queueItemIds === []) {
            $issues[] = 'queue_ids_required';
        }

        if ($channels !== ['baidu_push']) {
            $issues[] = 'baidu_push_channel_required';
        }

        if ($reason !== 'provider_quota_reset') {
            $issues[] = 'provider_quota_reset_reason_required';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function approvalIssues(?string $approvalPhrase, ?string $approvalToken, string $expectedPhrase, string $expectedToken): array
    {
        $phraseMatches = $approvalPhrase !== null && hash_equals($expectedPhrase, $approvalPhrase);
        $tokenMatches = $approvalToken !== null && hash_equals($expectedToken, strtolower($approvalToken));

        return $phraseMatches || $tokenMatches ? [] : ['bounded_retry_reset_approval_required'];
    }

    /**
     * @param  list<string>  $allowedChannels
     * @param  array<string, mixed>|null  $latestFailure
     * @return list<string>
     */
    private function validateItem(object $item, array $allowedChannels, ?array $latestFailure): array
    {
        $issues = [];

        if (! in_array((string) $item->channel, $allowedChannels, true)) {
            $issues[] = 'channel_not_requested';
        }

        if ((string) $item->channel !== 'baidu_push') {
            $issues[] = 'baidu_push_channel_required';
        }

        if ((string) $item->eligibility_state !== 'eligible') {
            $issues[] = 'item_not_eligible';
        }

        if ((string) $item->approval_state !== 'approved') {
            $issues[] = 'approval_state_not_approved';
        }

        if ((string) $item->execution_state !== 'submit_failed') {
            $issues[] = 'execution_state_not_submit_failed';
        }

        if ((string) $item->indexability_state !== 'indexable') {
            $issues[] = 'non_indexable_rejected';
        }

        if ((string) $item->claim_boundary_state !== 'claim_safe') {
            $issues[] = 'claim_unsafe_rejected';
        }

        if ((bool) $item->private_flow) {
            $issues[] = 'private_flow_rejected';
        }

        if ($latestFailure === null) {
            $issues[] = 'latest_bounded_submission_failure_not_found';
        } elseif (! $this->latestFailureWasOverQuota($latestFailure)) {
            $issues[] = 'latest_failure_not_provider_quota_exhausted';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestSubmissionFailure(int $queueItemId): ?array
    {
        $event = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_events')
            ->where('queue_item_id', $queueItemId)
            ->where('event_type', 'bounded_live_submission_response')
            ->orderByDesc('id')
            ->first();

        if ($event === null) {
            return null;
        }

        $payload = json_decode((string) $event->event_payload, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $latestFailure
     */
    private function latestFailureWasOverQuota(array $latestFailure): bool
    {
        $diagnostic = strtolower(trim(implode(' ', array_filter([
            (string) ($latestFailure['provider_error_code'] ?? ''),
            (string) ($latestFailure['provider_error_message'] ?? ''),
        ]))));

        return (int) ($latestFailure['http_status'] ?? 0) === 400
            && str_contains($diagnostic, 'over quota');
    }

    /**
     * @return array<string, mixed>
     */
    private function resetOne(object $item, string $reason, string $actorId, string $approvalToken): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();

        $updated = $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->where('channel', 'baidu_push')
            ->where('approval_state', 'approved')
            ->where('execution_state', 'submit_failed')
            ->update([
                'execution_state' => 'dry_run_ready',
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            $fresh = $connection->table('seo_search_channel_queue_items')->where('id', (int) $item->id)->first();

            return $this->blockedItem((int) $item->id, $fresh ?? $item, null, ['queue_item_already_changed_requeue_required'], false);
        }

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'search_channel_queue_retry_reset', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'from_execution_state' => 'submit_failed',
            'to_execution_state' => 'dry_run_ready',
            'reason' => $reason,
            'approval_token_hash' => hash('sha256', $approvalToken),
            'external_calls_attempted' => false,
        ], 'operator', $actorId);

        return [
            ...$this->itemBase($item, false),
            'status' => 'reset',
            'issues' => [],
            'execution_state' => 'dry_run_ready',
            'writes_attempted' => true,
            'writes_committed' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $itemResults
     * @return list<string>
     */
    private function flattenItemIssues(array $itemResults): array
    {
        $issues = [];

        foreach ($itemResults as $result) {
            foreach (($result['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>|null  $latestFailure
     * @return array<string, mixed>
     */
    private function readyItem(object $item, ?array $latestFailure, bool $dryRun): array
    {
        return [
            ...$this->itemBase($item, $dryRun),
            'status' => 'ready_for_retry_reset',
            'issues' => [],
            'latest_failure' => $this->failureSummary($latestFailure),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $latestFailure
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blockedItem(int $queueItemId, ?object $item, ?array $latestFailure, array $issues, bool $dryRun): array
    {
        return [
            'queue_item_id' => $queueItemId,
            'channel' => $item === null ? null : (string) $item->channel,
            'canonical_url' => $item === null ? null : (string) $item->canonical_url,
            'url_hash' => $item === null ? null : (string) $item->url_hash,
            'dry_run' => $dryRun,
            'status' => 'blocked',
            'issues' => array_values(array_unique($issues)),
            'approval_state' => $item === null ? null : (string) $item->approval_state,
            'execution_state' => $item === null ? null : (string) $item->execution_state,
            'latest_failure' => $this->failureSummary($latestFailure),
            'writes_attempted' => false,
            'writes_committed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemBase(object $item, bool $dryRun): array
    {
        return [
            'queue_item_id' => (int) $item->id,
            'channel' => (string) $item->channel,
            'canonical_url' => (string) $item->canonical_url,
            'url_hash' => (string) $item->url_hash,
            'dry_run' => $dryRun,
            'approval_state' => (string) $item->approval_state,
            'execution_state' => (string) $item->execution_state,
            'writes_attempted' => false,
            'writes_committed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $latestFailure
     * @return array<string, mixed>|null
     */
    private function failureSummary(?array $latestFailure): ?array
    {
        if ($latestFailure === null) {
            return null;
        }

        return [
            'http_status' => $latestFailure['http_status'] ?? null,
            'execution_state' => $latestFailure['execution_state'] ?? null,
            'provider_error_code' => $latestFailure['provider_error_code'] ?? null,
            'provider_error_message' => $latestFailure['provider_error_message'] ?? null,
        ];
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @param  list<string>  $issues
     * @param  list<array<string, mixed>>  $itemResults
     * @return array<string, mixed>
     */
    private function payload(
        string $status,
        bool $dryRun,
        array $queueItemIds,
        array $channels,
        string $reason,
        string $expectedPhrase,
        string $expectedToken,
        array $issues,
        array $itemResults,
        bool $writesAttempted = false,
        bool $writesCommitted = false,
    ): array {
        return [
            'runtime' => 'search_channel_queue_retry_reset',
            'status' => $status,
            'dry_run' => $dryRun,
            'queue_item_ids' => $queueItemIds,
            'queue_item_count' => count($queueItemIds),
            'channels' => $channels,
            'reason' => $reason,
            'approval_phrase' => $expectedPhrase,
            'approval_token' => $expectedToken,
            'issues' => $issues,
            'items' => $itemResults,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => $writesAttempted,
            'writes_committed' => $writesCommitted,
            'safety_flags' => [
                'baidu_push_only' => true,
                'dry_run_default' => true,
                'approved_queue_state_required' => true,
                'submit_failed_state_required' => true,
                'over_quota_failure_required' => true,
                'external_api_calls' => false,
                'search_submission_attempted' => false,
                'cms_mutation' => false,
                'schema_hreflang_mutation' => false,
            ],
        ];
    }

    private function normalizedReason(?string $reason): string
    {
        $reason = strtolower(trim((string) $reason));

        return $reason === '' ? 'provider_quota_reset' : $reason;
    }
}
