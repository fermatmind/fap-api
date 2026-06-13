<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

final class SearchChannelQueueApprovalExecutor
{
    public function __construct(
        private readonly SearchChannelQueueAuditLogger $events,
    ) {}

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public function approve(
        array $queueItemIds,
        array $channels,
        ?string $approvalPhrase,
        ?string $approvalToken,
        string $actorId,
        bool $dryRun,
    ): array {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $allowedChannels = $this->allowedChannels($channels);
        $expectedPhrase = $this->approvalPhrase($queueItemIds, $allowedChannels);
        $expectedToken = hash('sha256', $expectedPhrase);
        $setupIssues = $this->setupIssues($queueItemIds, $allowedChannels);

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
            $itemResults[] = $this->blockedItem($missingId, null, ['queue_item_not_found'], $dryRun);
        }

        foreach ($items as $item) {
            $itemIssues = $this->validateItem($item, $allowedChannels);
            $itemResults[] = $itemIssues === []
                ? $this->readyItem($item, $dryRun)
                : $this->blockedItem((int) $item->id, $item, $itemIssues, $dryRun);
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
                expectedPhrase: $expectedPhrase,
                expectedToken: $expectedToken,
                issues: [],
                itemResults: $itemResults,
            );
        }

        $approvedResults = [];
        foreach ($items as $item) {
            $approvedResults[] = $this->approveOne($item, $actorId, $expectedToken);
        }

        $approveIssues = $this->flattenItemIssues($approvedResults);

        return $this->payload(
            status: $approveIssues === [] ? 'success' : 'failed',
            dryRun: false,
            queueItemIds: $queueItemIds,
            channels: $allowedChannels,
            expectedPhrase: $expectedPhrase,
            expectedToken: $expectedToken,
            issues: $approveIssues,
            itemResults: $approvedResults,
            writesAttempted: true,
            writesCommitted: $approveIssues === [],
        );
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     */
    public function approvalPhrase(array $queueItemIds, array $channels): string
    {
        return sprintf(
            'I explicitly approve SEARCH-CHANNEL-QUEUE-APPROVE approval for queue items %s channels %s.',
            implode(',', $queueItemIds),
            implode(',', $channels),
        );
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function allowedChannels(array $channels): array
    {
        $configured = array_values(config('seo_intel.search_channel_queue.live_submission.allowed_channels', []));

        if ($channels === []) {
            return ['indexnow', 'baidu_push'];
        }

        return array_values(array_intersect($channels, $configured));
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function setupIssues(array $queueItemIds, array $channels): array
    {
        $issues = [];

        if ($queueItemIds === []) {
            $issues[] = 'queue_ids_required';
        }

        if ($channels === []) {
            $issues[] = 'valid_channels_required';
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

        return $phraseMatches || $tokenMatches ? [] : ['bounded_queue_approval_required'];
    }

    /**
     * @param  list<string>  $allowedChannels
     * @return list<string>
     */
    private function validateItem(object $item, array $allowedChannels): array
    {
        $issues = [];
        $url = trim((string) $item->canonical_url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $channel = (string) $item->channel;

        if (! in_array($channel, $allowedChannels, true)) {
            $issues[] = 'channel_not_requested';
        }

        if ((string) $item->eligibility_state !== 'eligible') {
            $issues[] = 'item_not_eligible';
        }

        if ((string) $item->approval_state !== 'pending') {
            $issues[] = 'approval_state_not_pending';
        }

        if ((string) $item->execution_state !== 'dry_run_ready') {
            $issues[] = 'execution_state_not_dry_run_ready';
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

        if (! in_array((string) $item->source_authority, config('seo_intel.search_channel_queue.approved_source_authorities', []), true)) {
            $issues[] = 'source_authority_not_approved';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $issues[] = 'invalid_canonical_url';
        }

        if ($scheme !== 'https') {
            $issues[] = 'non_https_url_rejected';
        }

        if (! in_array($host, config('seo_intel.search_channel_queue.live_submission.allowed_hosts', []), true)) {
            $issues[] = 'host_not_allowed';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return array<string, mixed>
     */
    private function approveOne(object $item, string $actorId, string $approvalToken): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();

        $claimed = $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->where('approval_state', 'pending')
            ->where('execution_state', 'dry_run_ready')
            ->update([
                'approval_state' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            $fresh = $connection->table('seo_search_channel_queue_items')->where('id', (int) $item->id)->first();

            return $this->blockedItem((int) $item->id, $fresh ?? $item, ['queue_item_already_claimed_or_requeue_required'], false);
        }

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'search_channel_queue_approved', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'approval_token_hash' => hash('sha256', $approvalToken),
            'bounded_approval' => true,
        ], 'operator', $actorId);

        return [
            ...$this->itemBase($item, false),
            'status' => 'approved',
            'issues' => [],
            'approval_state' => 'approved',
            'approved_by' => $actorId,
            'approved_at' => $now->toJSON(),
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
     * @return array<string, mixed>
     */
    private function readyItem(object $item, bool $dryRun): array
    {
        return [
            ...$this->itemBase($item, $dryRun),
            'status' => 'ready_for_approval',
            'issues' => [],
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blockedItem(int $queueItemId, ?object $item, array $issues, bool $dryRun): array
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
        string $expectedPhrase,
        string $expectedToken,
        array $issues,
        array $itemResults,
        bool $writesAttempted = false,
        bool $writesCommitted = false,
    ): array {
        return [
            'runtime' => 'search_channel_queue_approval',
            'status' => $status,
            'dry_run' => $dryRun,
            'queue_item_ids' => $queueItemIds,
            'queue_item_count' => count($queueItemIds),
            'channels' => $channels,
            'approval_phrase' => $expectedPhrase,
            'approval_token' => $expectedToken,
            'issues' => $issues,
            'items' => $itemResults,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => $writesAttempted,
            'writes_committed' => $writesCommitted,
            'safety_flags' => [
                'queue_item_ids_required' => true,
                'pending_queue_state_required' => true,
                'dry_run_default' => true,
                'scheduler_enabled' => false,
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
                'raw_secret_output' => false,
                'cms_mutation' => false,
                'schema_hreflang_mutation' => false,
                'revalidation_triggered' => false,
            ],
        ];
    }
}
