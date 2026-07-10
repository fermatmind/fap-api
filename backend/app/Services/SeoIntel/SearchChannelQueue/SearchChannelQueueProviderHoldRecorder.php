<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

final class SearchChannelQueueProviderHoldRecorder
{
    private const REASON = 'transport_security_unavailable';

    public function __construct(
        private readonly SearchChannelQueueAuditLogger $events,
    ) {}

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public function record(
        array $queueItemIds,
        array $channels,
        ?string $reason,
        ?string $approvalPhrase,
        ?string $approvalToken,
        string $actorId,
        bool $dryRun,
    ): array {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $channels = array_values(array_intersect($channels === [] ? ['baidu_push'] : $channels, ['baidu_push']));
        $reason = strtolower(trim((string) ($reason ?: self::REASON)));
        $expectedPhrase = $this->approvalPhrase($queueItemIds, $channels, $reason);
        $expectedToken = hash('sha256', $expectedPhrase);
        $issues = $this->setupIssues($queueItemIds, $channels, $reason);

        $items = $queueItemIds === []
            ? collect()
            : $connection->table('seo_search_channel_queue_items')->whereIn('id', $queueItemIds)->orderBy('id')->get();
        $foundIds = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $itemResults = [];

        foreach (array_values(array_diff($queueItemIds, $foundIds)) as $missingId) {
            $itemResults[] = $this->blockedItem($missingId, null, ['queue_item_not_found'], $dryRun);
        }

        foreach ($items as $item) {
            $evidence = $this->transportSecurityEvidence((int) $item->id);
            $itemIssues = $this->validateItem($item, $evidence);
            $itemResults[] = $itemIssues === []
                ? $this->readyItem($item, $evidence, $dryRun)
                : $this->blockedItem((int) $item->id, $item, $itemIssues, $dryRun, $evidence);
        }

        if (! $dryRun && ! $this->approvalMatches($approvalPhrase, $approvalToken, $expectedPhrase, $expectedToken)) {
            $issues[] = 'provider_hold_approval_required';
        }

        $issues = array_values(array_unique([
            ...$issues,
            ...$this->flattenItemIssues($itemResults),
        ]));

        if ($issues !== [] || $dryRun) {
            return $this->payload(
                status: $issues === [] ? 'success' : 'blocked',
                dryRun: $dryRun,
                queueItemIds: $queueItemIds,
                channels: $channels,
                reason: $reason,
                expectedPhrase: $expectedPhrase,
                expectedToken: $expectedToken,
                issues: $issues,
                itemResults: $itemResults,
            );
        }

        $writeResults = [];
        foreach ($items as $item) {
            $writeResults[] = $this->recordOne(
                item: $item,
                evidence: $this->transportSecurityEvidence((int) $item->id),
                reason: $reason,
                actorId: $actorId,
                approvalToken: $expectedToken,
            );
        }
        $writeIssues = $this->flattenItemIssues($writeResults);

        return $this->payload(
            status: $writeIssues === [] ? 'success' : 'failed',
            dryRun: false,
            queueItemIds: $queueItemIds,
            channels: $channels,
            reason: $reason,
            expectedPhrase: $expectedPhrase,
            expectedToken: $expectedToken,
            issues: $writeIssues,
            itemResults: $writeResults,
            writesAttempted: true,
            writesCommitted: $writeIssues === [],
        );
    }

    /** @param list<int> $queueItemIds @param list<string> $channels */
    public function approvalPhrase(array $queueItemIds, array $channels, string $reason): string
    {
        return sprintf(
            'I explicitly approve SEARCH-CHANNEL-PROVIDER-HOLD record for queue items %s channels %s reason %s.',
            implode(',', $queueItemIds),
            implode(',', $channels),
            $reason,
        );
    }

    /** @param list<int> $queueItemIds @param list<string> $channels @return list<string> */
    private function setupIssues(array $queueItemIds, array $channels, string $reason): array
    {
        return array_values(array_filter([
            $queueItemIds === [] ? 'queue_ids_required' : null,
            $channels !== ['baidu_push'] ? 'baidu_push_channel_required' : null,
            $reason !== self::REASON ? 'transport_security_unavailable_reason_required' : null,
        ]));
    }

    private function approvalMatches(?string $phrase, ?string $token, string $expectedPhrase, string $expectedToken): bool
    {
        return ($phrase !== null && hash_equals($expectedPhrase, $phrase))
            || ($token !== null && hash_equals($expectedToken, strtolower($token)));
    }

    /** @param array<string,mixed>|null $evidence @return list<string> */
    private function validateItem(object $item, ?array $evidence): array
    {
        $issues = [];

        if ((string) $item->channel !== 'baidu_push') {
            $issues[] = 'baidu_push_channel_required';
        }
        if ((string) $item->eligibility_state !== 'eligible') {
            $issues[] = 'item_not_eligible';
        }
        if ((string) $item->approval_state !== 'approved') {
            $issues[] = 'approval_state_not_approved';
        }
        if (! in_array((string) $item->execution_state, ['dry_run_ready', 'submit_failed'], true)) {
            $issues[] = 'execution_state_not_holdable';
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
        if ($evidence === null) {
            $issues[] = 'baidu_secure_endpoint_available_use_live_submit';
        }

        return array_values(array_unique($issues));
    }

    /** @return array<string,mixed>|null */
    private function transportSecurityEvidence(int $queueItemId): ?array
    {
        $endpoint = trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.endpoint'));
        $endpointHost = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $validHttps = filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) === 'https'
            && $endpointHost !== '';

        if (! $validHttps) {
            return [
                'kind' => 'configured_endpoint_not_https',
                'endpoint_host' => $endpointHost !== '' ? $endpointHost : null,
                'submission_event_id' => null,
            ];
        }

        $event = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_events')
            ->where('queue_item_id', $queueItemId)
            ->where('event_type', 'bounded_live_submission_response')
            ->orderByDesc('id')
            ->first();
        $payload = $event === null ? null : json_decode((string) $event->event_payload, true);

        if (! is_array($payload)
            || ($payload['channel'] ?? null) !== 'baidu_push'
            || ($payload['execution_state'] ?? null) !== 'submit_failed'
            || ($payload['http_status'] ?? null) !== null
            || trim((string) ($payload['exception_class'] ?? '')) === ''
            || strtolower((string) ($payload['endpoint_host'] ?? '')) !== $endpointHost) {
            return null;
        }

        return [
            'kind' => 'verified_https_transport_failure',
            'endpoint_host' => $endpointHost,
            'submission_event_id' => (int) $event->id,
        ];
    }

    /** @param array<string,mixed>|null $evidence @return array<string,mixed> */
    private function recordOne(object $item, ?array $evidence, string $reason, string $actorId, string $approvalToken): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $fromState = (string) $item->execution_state;
        if ($evidence === null) {
            return $this->blockedItem((int) $item->id, $item, ['queue_item_already_changed_requeue_required'], false, $evidence);
        }

        $updated = $connection->transaction(function () use ($connection, $item, $fromState, $evidence, $reason, $actorId, $approvalToken): int {
            $updated = $connection->table('seo_search_channel_queue_items')
                ->where('id', (int) $item->id)
                ->where('channel', 'baidu_push')
                ->where('approval_state', 'approved')
                ->whereIn('execution_state', ['dry_run_ready', 'submit_failed'])
                ->update(['execution_state' => 'provider_security_hold', 'updated_at' => now()]);

            if ($updated === 1) {
                $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'search_channel_provider_security_hold_recorded', [
                    'channel' => 'baidu_push',
                    'url_hash' => (string) $item->url_hash,
                    'from_execution_state' => $fromState,
                    'to_execution_state' => 'provider_security_hold',
                    'reason' => $reason,
                    'evidence_kind' => $evidence['kind'],
                    'endpoint_host' => $evidence['endpoint_host'],
                    'submission_event_id' => $evidence['submission_event_id'],
                    'approval_token_hash' => hash('sha256', $approvalToken),
                    'external_calls_attempted' => false,
                    'search_submission_attempted' => false,
                ], 'operator', $actorId);
            }

            return $updated;
        });

        if ($updated !== 1) {
            return $this->blockedItem((int) $item->id, $item, ['queue_item_already_changed_requeue_required'], false, $evidence);
        }

        return [
            ...$this->itemBase($item, false),
            'status' => 'provider_security_hold_recorded',
            'issues' => [],
            'execution_state' => 'provider_security_hold',
            'security_evidence' => $evidence,
            'writes_attempted' => true,
            'writes_committed' => true,
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function readyItem(object $item, array $evidence, bool $dryRun): array
    {
        return [
            ...$this->itemBase($item, $dryRun),
            'status' => 'ready_for_provider_security_hold',
            'issues' => [],
            'security_evidence' => $evidence,
        ];
    }

    /** @param list<string> $issues @param array<string,mixed>|null $evidence @return array<string,mixed> */
    private function blockedItem(int $queueItemId, ?object $item, array $issues, bool $dryRun, ?array $evidence = null): array
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
            'security_evidence' => $evidence,
            'writes_attempted' => false,
            'writes_committed' => false,
        ];
    }

    /** @return array<string,mixed> */
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

    /** @param list<array<string,mixed>> $items @return list<string> */
    private function flattenItemIssues(array $items): array
    {
        $issues = [];
        foreach ($items as $item) {
            foreach (($item['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @param  list<string>  $issues
     * @param  list<array<string,mixed>>  $itemResults
     * @return array<string,mixed>
     */
    private function payload(string $status, bool $dryRun, array $queueItemIds, array $channels, string $reason, string $expectedPhrase, string $expectedToken, array $issues, array $itemResults, bool $writesAttempted = false, bool $writesCommitted = false): array
    {
        return [
            'runtime' => 'search_channel_provider_security_hold',
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
                'exact_approval_required' => true,
                'transport_security_evidence_required' => true,
                'external_api_calls' => false,
                'token_output' => false,
                'tls_verification_disabled' => false,
                'cms_mutation' => false,
                'schema_hreflang_mutation' => false,
            ],
        ];
    }
}
