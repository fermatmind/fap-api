<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use App\Services\SeoIntel\SearchChannelSubmissionStatusNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SearchChannelQueueLiveSubmissionExecutor
{
    public function __construct(
        private readonly SearchChannelQueueAuditLogger $events,
        private readonly SearchChannelSubmissionStatusNormalizer $statusNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function submit(int $queueItemId, ?string $approvalPhrase, string $actorId, bool $dryRun): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $item = $connection->table('seo_search_channel_queue_items')->where('id', $queueItemId)->first();

        if ($item === null) {
            return $this->blocked($queueItemId, null, ['queue_item_not_found'], $dryRun);
        }

        $expectedPhrase = $this->approvalPhrase((int) $item->id, (string) $item->channel, (string) $item->canonical_url);
        $validationIssues = $this->validateItem($item);
        $gateIssues = $dryRun ? [] : $this->validateLiveGates($item, $approvalPhrase, $expectedPhrase);
        $issues = array_values(array_unique([...$validationIssues, ...$gateIssues]));

        if ($issues !== []) {
            return [
                ...$this->basePayload($item, $dryRun, $expectedPhrase),
                'status' => 'blocked',
                'issues' => $issues,
            ];
        }

        if ($dryRun) {
            return [
                ...$this->basePayload($item, true, $expectedPhrase),
                'status' => 'success',
                'issues' => [],
            ];
        }

        $now = now();
        $endpoint = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.endpoint');
        $key = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key');
        $keyLocation = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location');
        $canonicalUrl = (string) $item->canonical_url;
        $host = (string) parse_url($canonicalUrl, PHP_URL_HOST);

        $claimed = $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->where('approval_state', 'pending')
            ->where('execution_state', 'dry_run_ready')
            ->update([
                'approval_state' => 'approved',
                'execution_state' => 'submitting',
                'approved_by' => $actorId,
                'approved_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            return [
                ...$this->basePayload($item, false, $expectedPhrase),
                'status' => 'blocked',
                'issues' => ['queue_item_already_claimed'],
                'writes_attempted' => true,
            ];
        }

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'live_submission_approved', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'approval_phrase_hash' => hash('sha256', $expectedPhrase),
        ], 'operator', $actorId);

        $httpStatus = null;
        $accepted = false;
        $exceptionClass = null;

        try {
            $response = Http::timeout(max(1, (int) config('seo_intel.search_channel_queue.live_submission.indexnow.timeout_seconds', 10)))
                ->asJson()
                ->post($endpoint, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => $keyLocation,
                    'urlList' => [$canonicalUrl],
                ]);

            $httpStatus = $response->status();
            $accepted = $response->successful();
        } catch (Throwable $exception) {
            $exceptionClass = $exception::class;
        }

        $submissionStatus = $this->statusNormalizer->normalize($accepted ? 'accepted' : 'failed');
        $executionState = $accepted ? 'submitted' : 'submit_failed';

        $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->update([
                'execution_state' => $executionState,
                'updated_at' => now(),
            ]);

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'live_submission_response', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
            'http_status' => $httpStatus,
            'submission_status' => $submissionStatus,
            'exception_class' => $exceptionClass,
        ], 'system', 'seo-intel:search-channel-submit');

        return [
            ...$this->basePayload($item, false, $expectedPhrase),
            'status' => $accepted ? 'success' : 'failed',
            'issues' => $accepted ? [] : ['submission_failed'],
            'external_calls_attempted' => true,
            'search_submission_attempted' => true,
            'writes_attempted' => true,
            'writes_committed' => true,
            'submission_status' => $submissionStatus,
            'execution_state' => $executionState,
            'http_status' => $httpStatus,
        ];
    }

    public function approvalPhrase(int $queueItemId, string $channel, string $canonicalUrl): string
    {
        return sprintf(
            'I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item %d channel %s URL %s.',
            $queueItemId,
            $channel,
            $canonicalUrl,
        );
    }

    /**
     * @return list<string>
     */
    private function validateItem(object $item): array
    {
        $issues = [];
        $url = trim((string) $item->canonical_url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array((string) $item->channel, config('seo_intel.search_channel_queue.live_submission.allowed_channels', []), true)) {
            $issues[] = 'channel_not_live_submission_enabled';
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

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function validateLiveGates(object $item, ?string $approvalPhrase, string $expectedPhrase): array
    {
        $issues = [];

        if ($approvalPhrase !== $expectedPhrase) {
            $issues[] = 'approval_phrase_mismatch';
        }

        if (! (bool) config('seo_intel.search_channel_queue.live_submission.enabled', false)) {
            $issues[] = 'live_submission_gate_disabled';
        }

        if (! (bool) config('seo_intel.search_channel_queue.live_submission.external_api_calls_enabled', false)) {
            $issues[] = 'external_api_gate_disabled';
        }

        if ((string) $item->channel === 'indexnow' && ! (bool) config('seo_intel.indexnow_live_api_enabled', false)) {
            $issues[] = 'indexnow_live_api_disabled';
        }

        if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key')) === '') {
            $issues[] = 'indexnow_key_missing';
        }

        if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location')) === '') {
            $issues[] = 'indexnow_key_location_missing';
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(object $item, bool $dryRun, string $approvalPhrase): array
    {
        return [
            'runtime' => 'search_channel_live_submission',
            'queue_item_id' => (int) $item->id,
            'channel' => (string) $item->channel,
            'canonical_url' => (string) $item->canonical_url,
            'dry_run' => $dryRun,
            'approval_phrase' => $approvalPhrase,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'submission_status' => 'not_attempted',
            'execution_state' => (string) $item->execution_state,
            'safety_flags' => [
                'scheduler_enabled' => false,
                'bulk_submission' => false,
                'raw_secret_output' => false,
                'sitemap_llms_behavior_changed' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(int $queueItemId, ?object $item, array $issues, bool $dryRun): array
    {
        return [
            'runtime' => 'search_channel_live_submission',
            'status' => 'blocked',
            'queue_item_id' => $queueItemId,
            'channel' => $item === null ? null : (string) $item->channel,
            'canonical_url' => $item === null ? null : (string) $item->canonical_url,
            'dry_run' => $dryRun,
            'approval_phrase' => null,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'submission_status' => 'not_attempted',
            'execution_state' => $item === null ? null : (string) $item->execution_state,
            'issues' => $issues,
            'safety_flags' => [
                'scheduler_enabled' => false,
                'bulk_submission' => false,
                'raw_secret_output' => false,
                'sitemap_llms_behavior_changed' => false,
            ],
        ];
    }
}
