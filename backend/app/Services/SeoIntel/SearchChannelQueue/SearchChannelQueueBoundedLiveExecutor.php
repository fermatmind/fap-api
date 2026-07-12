<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use App\Services\SeoIntel\SearchChannelSubmissionStatusNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SearchChannelQueueBoundedLiveExecutor
{
    private const ENNEAGRAM_ARTIFACT_PATTERN = '/^[0-9a-f]{64}$/';

    private const ENNEAGRAM_PAGE_TYPE = 'personality_public_content_asset';

    private const ENNEAGRAM_TARGET_COUNT = 116;

    public function __construct(
        private readonly SearchChannelQueueAuditLogger $events,
        private readonly SearchChannelSubmissionStatusNormalizer $statusNormalizer,
        private readonly SearchChannelQueueEligibilityEvaluator $eligibilityEvaluator,
    ) {}

    /**
     * Submit the exact artifact-bound Enneagram IndexNow cohort in two explicit phases.
     * The canary is the lowest stable URL hash; the batch is one provider request for
     * the remaining 115 URLs. No URL, queue id, credential, or provider body is emitted.
     *
     * @return array<string, mixed>
     */
    public function submitEnneagramIndexNow(
        string $phase,
        string $artifactSha256,
        ?string $operatorToken,
        string $actorId,
        bool $live,
    ): array {
        $phase = strtolower(trim($phase));
        $artifactSha256 = strtolower(trim($artifactSha256));
        $actorId = substr((string) preg_replace('/[^A-Za-z0-9:_@.-]/', '_', $actorId), 0, 128);
        $issues = [];

        if (! in_array($phase, ['canary', 'batch'], true)) {
            $issues[] = 'phase_not_allowed';
        }
        if (preg_match(self::ENNEAGRAM_ARTIFACT_PATTERN, $artifactSha256) !== 1) {
            $issues[] = 'artifact_sha256_invalid';
        }
        $expectedToken = 'ENNEAGRAM-SEARCH-INDEXNOW-SUBMIT-01:'.$phase.':'.$artifactSha256;
        if ($live && ($operatorToken === null || ! hash_equals($expectedToken, trim($operatorToken)))) {
            $issues[] = 'artifact_bound_operator_token_mismatch';
        }

        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $items = $connection->table('seo_search_channel_queue_items')
            ->where('channel', 'indexnow')
            ->where('page_entity_type', self::ENNEAGRAM_PAGE_TYPE)
            ->orderBy('url_hash')
            ->get();
        $state = $this->enneagramState($items->all());
        $issues = array_values(array_unique([...$issues, ...$state['issues']]));

        if ($issues === []) {
            if ($phase === 'canary' && ! in_array($state['submitted_count'], [0, 1, self::ENNEAGRAM_TARGET_COUNT], true)) {
                $issues[] = 'canary_state_not_exact';
            }
            if ($phase === 'batch' && ! in_array($state['submitted_count'], [1, self::ENNEAGRAM_TARGET_COUNT], true)) {
                $issues[] = 'batch_requires_one_successful_canary';
            }
        }

        $alreadyCompleted = $phase === 'canary'
            ? $state['submitted_count'] >= 1
            : $state['submitted_count'] === self::ENNEAGRAM_TARGET_COUNT;
        $selected = $phase === 'canary'
            ? ($items->isEmpty() ? collect() : $items->take(1))
            : $items->filter(static fn (object $item): bool => (string) $item->execution_state === 'dry_run_ready')->values();
        $expectedSelectionCount = $phase === 'canary' ? 1 : 115;

        if ($issues === [] && ! $alreadyCompleted && $selected->count() !== $expectedSelectionCount) {
            $issues[] = 'phase_target_count_mismatch';
        }
        if ($issues !== []) {
            return $this->enneagramPayload('blocked', $phase, $artifactSha256, $live, $state, $selected->count(), $issues);
        }
        if ($alreadyCompleted) {
            return $this->enneagramPayload('already_completed', $phase, $artifactSha256, $live, $state, 0, []);
        }
        if (! $live) {
            return $this->enneagramPayload('dry_run_ready', $phase, $artifactSha256, false, $state, $selected->count(), []);
        }

        $gateIssues = $this->enneagramLiveGateIssues();
        if ($gateIssues !== []) {
            return $this->enneagramPayload('blocked', $phase, $artifactSha256, true, $state, $selected->count(), $gateIssues);
        }

        $ids = $selected->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $urls = $selected->pluck('canonical_url')->map(static fn (mixed $url): string => (string) $url)->all();
        $now = now();

        try {
            $connection->transaction(function () use ($connection, $ids, $actorId, $now): void {
                $claimed = $connection->table('seo_search_channel_queue_items')
                    ->whereIn('id', $ids)
                    ->where('approval_state', 'pending')
                    ->where('execution_state', 'dry_run_ready')
                    ->update([
                        'approval_state' => 'approved',
                        'execution_state' => 'submitting',
                        'approved_by' => $actorId,
                        'approved_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($claimed !== count($ids)) {
                    throw new \RuntimeException('indexnow_phase_claim_count_mismatch');
                }
            });
        } catch (Throwable) {
            return $this->enneagramPayload('blocked', $phase, $artifactSha256, true, $state, count($ids), ['queue_claim_count_mismatch']);
        }

        foreach ($selected as $item) {
            $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'enneagram_indexnow_phase_started', [
                'phase' => $phase,
                'url_hash' => (string) $item->url_hash,
                'artifact_sha256' => $artifactSha256,
                'cohort_size' => count($ids),
            ], 'operator', $actorId);
        }

        $submission = $this->submitEnneagramIndexNowRequest($urls);
        $accepted = (bool) $submission['accepted'];
        $executionState = $accepted ? 'submitted' : 'submit_failed';
        $connection->table('seo_search_channel_queue_items')
            ->whereIn('id', $ids)
            ->where('execution_state', 'submitting')
            ->update(['execution_state' => $executionState, 'updated_at' => now()]);

        foreach ($selected as $item) {
            $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'enneagram_indexnow_phase_response', [
                'phase' => $phase,
                'url_hash' => (string) $item->url_hash,
                'http_status' => $submission['http_status'],
                'accepted' => $accepted,
                'endpoint_host' => $submission['endpoint_host'],
                'reason_code' => $accepted ? 'provider_accepted' : 'provider_rejected_or_unavailable',
            ], 'system', 'enneagram-indexnow-production-ops');
        }

        $readback = $this->enneagramState($connection->table('seo_search_channel_queue_items')
            ->where('channel', 'indexnow')
            ->where('page_entity_type', self::ENNEAGRAM_PAGE_TYPE)
            ->orderBy('url_hash')
            ->get()
            ->all());

        return $this->enneagramPayload(
            $accepted ? 'success' : 'failed',
            $phase,
            $artifactSha256,
            true,
            $readback,
            count($ids),
            $accepted ? [] : ['provider_rejected_or_unavailable'],
            externalCallsAttempted: true,
            submittedCount: $accepted ? count($ids) : 0,
            failedCount: $accepted ? 0 : count($ids),
            httpStatus: $submission['http_status'],
        );
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public function submit(
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
        $setupIssues = $this->setupIssues($queueItemIds, $allowedChannels, $dryRun);

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

        $liveResults = [];
        foreach ($items as $item) {
            $liveResults[] = $this->submitOne($item, $actorId, $expectedToken);
        }

        $liveIssues = $this->flattenItemIssues($liveResults);

        return $this->payload(
            status: $liveIssues === [] ? 'success' : 'failed',
            dryRun: false,
            queueItemIds: $queueItemIds,
            channels: $allowedChannels,
            expectedPhrase: $expectedPhrase,
            expectedToken: $expectedToken,
            issues: $liveIssues,
            itemResults: $liveResults,
            externalCallsAttempted: true,
            searchSubmissionAttempted: true,
            writesAttempted: true,
            writesCommitted: true,
        );
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     */
    public function approvalPhrase(array $queueItemIds, array $channels): string
    {
        return sprintf(
            'I explicitly approve SEARCH-CHANNEL-BOUNDED-LIVE-EXECUTOR live submission for queue items %s channels %s.',
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
            return $configured;
        }

        return array_values(array_intersect($channels, $configured));
    }

    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function setupIssues(array $queueItemIds, array $channels, bool $dryRun): array
    {
        $issues = [];

        if ($queueItemIds === []) {
            $issues[] = 'queue_ids_required';
        }

        if ($channels === []) {
            $issues[] = 'valid_channels_required';
        }

        if (! $dryRun) {
            if (! (bool) config('seo_intel.search_channel_queue.live_submission.enabled', false)) {
                $issues[] = 'live_submission_gate_disabled';
            }
            if (! (bool) config('seo_intel.search_channel_queue.live_submission.external_api_calls_enabled', false)) {
                $issues[] = 'external_api_gate_disabled';
            }
            if (in_array('indexnow', $channels, true) && ! (bool) config('seo_intel.indexnow_live_api_enabled', false)) {
                $issues[] = 'indexnow_live_api_disabled';
            }
            if (in_array('baidu_push', $channels, true) && ! (bool) config('seo_intel.baidu_live_api_enabled', false)) {
                $issues[] = 'baidu_live_api_disabled';
            }
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

        return $phraseMatches || $tokenMatches ? [] : ['bounded_live_approval_required'];
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
        $executionState = (string) $item->execution_state;

        if (! in_array($channel, $allowedChannels, true)) {
            $issues[] = 'channel_not_requested';
        }

        if ((string) $item->eligibility_state !== 'eligible') {
            $issues[] = 'item_not_eligible';
        }

        if ((string) $item->approval_state !== 'approved') {
            $issues[] = 'approval_state_not_approved';
        }

        if ($executionState === 'submitted') {
            $issues[] = 'queue_item_already_submitted_requeue_required';
        } elseif ($executionState !== 'dry_run_ready') {
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

        $eligibility = $this->eligibilityEvaluator->evaluate([
            ...(array) $item,
            'is_private_flow' => (bool) $item->private_flow,
        ]);
        foreach ($eligibility->reasonCodes as $reasonCode) {
            $issues[] = 'eligibility_'.$reasonCode;
        }

        if ($channel === 'indexnow') {
            if (! $this->isHttpsEndpoint((string) config('seo_intel.search_channel_queue.live_submission.indexnow.endpoint'))) {
                $issues[] = 'indexnow_endpoint_not_https';
            }

            if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key')) === '') {
                $issues[] = 'indexnow_key_missing';
            }

            if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location')) === '') {
                $issues[] = 'indexnow_key_location_missing';
            }
        } elseif ($channel === 'baidu_push') {
            if (! $this->isHttpsEndpoint((string) config('seo_intel.search_channel_queue.live_submission.baidu.endpoint'))) {
                $issues[] = 'baidu_endpoint_not_https';
            }

            if (trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.endpoint')) === '') {
                $issues[] = 'baidu_endpoint_missing';
            }

            if (trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.site')) === '') {
                $issues[] = 'baidu_site_missing';
            }

            if (trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.token')) === '') {
                $issues[] = 'baidu_token_missing';
            }
        } else {
            $issues[] = 'unsupported_live_submission_channel';
        }

        return array_values(array_unique($issues));
    }

    private function isHttpsEndpoint(string $endpoint): bool
    {
        return filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) === 'https'
            && is_string(parse_url($endpoint, PHP_URL_HOST));
    }

    /**
     * @return array<string, mixed>
     */
    private function submitOne(object $item, string $actorId, string $approvalToken): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();

        $claimed = $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->where('approval_state', 'approved')
            ->where('execution_state', 'dry_run_ready')
            ->update([
                'execution_state' => 'submitting',
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            $fresh = $connection->table('seo_search_channel_queue_items')->where('id', (int) $item->id)->first();

            return $this->blockedItem((int) $item->id, $fresh ?? $item, ['queue_item_already_claimed_or_requeue_required'], false);
        }

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'bounded_live_submission_started', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'approval_token_hash' => hash('sha256', $approvalToken),
            'global_live_gates_required' => true,
        ], 'operator', $actorId);

        $submission = $this->submitToChannel((string) $item->channel, (string) $item->canonical_url);
        $platformActionRequired = $this->platformActionRequired($submission);
        $accepted = (bool) $submission['accepted'];
        $submissionStatus = $this->statusNormalizer->normalize($accepted ? 'accepted' : 'failed');
        $executionState = $accepted ? 'submitted' : ($platformActionRequired ? 'platform_action_required' : 'submit_failed');
        $issues = $accepted ? [] : ($platformActionRequired ? ['platform_action_required'] : ['submission_failed']);

        $connection->table('seo_search_channel_queue_items')
            ->where('id', (int) $item->id)
            ->update([
                'execution_state' => $executionState,
                'updated_at' => now(),
            ]);

        $this->events->log($connection, (int) $item->id, is_numeric($item->batch_id) ? (int) $item->batch_id : null, 'bounded_live_submission_response', [
            'channel' => (string) $item->channel,
            'url_hash' => (string) $item->url_hash,
            'endpoint_host' => $submission['endpoint_host'],
            'http_status' => $submission['http_status'],
            'submission_status' => $submissionStatus,
            'execution_state' => $executionState,
            'exception_class' => $submission['exception_class'],
            'provider_error_code' => $submission['provider_error_code'],
            'provider_error_message' => $submission['provider_error_message'],
        ], 'system', 'seo-intel:search-channel-submit-approved');

        return [
            ...$this->itemBase($item, false),
            'status' => $accepted ? 'success' : 'failed',
            'issues' => $issues,
            'external_calls_attempted' => true,
            'search_submission_attempted' => true,
            'writes_attempted' => true,
            'writes_committed' => true,
            'submission_status' => $submissionStatus,
            'execution_state' => $executionState,
            'http_status' => $submission['http_status'],
            'provider_error_code' => $submission['provider_error_code'],
            'provider_error_message' => $submission['provider_error_message'],
        ];
    }

    /**
     * @return array{accepted: bool, http_status: ?int, exception_class: ?class-string, endpoint_host: ?string, provider_error_code: ?string, provider_error_message: ?string}
     */
    private function submitToChannel(string $channel, string $canonicalUrl): array
    {
        return match ($channel) {
            'indexnow' => $this->submitIndexNow($canonicalUrl),
            'baidu_push' => $this->submitBaiduPush($canonicalUrl),
            default => [
                'accepted' => false,
                'http_status' => null,
                'exception_class' => null,
                'endpoint_host' => null,
                'provider_error_code' => 'unsupported_channel',
                'provider_error_message' => null,
            ],
        };
    }

    /**
     * @return array{accepted: bool, http_status: ?int, exception_class: ?class-string, endpoint_host: ?string, provider_error_code: ?string, provider_error_message: ?string}
     */
    private function submitIndexNow(string $canonicalUrl): array
    {
        $endpoint = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.endpoint');
        $key = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key');
        $keyLocation = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location');
        $host = (string) parse_url($canonicalUrl, PHP_URL_HOST);

        try {
            $response = Http::timeout(max(1, (int) config('seo_intel.search_channel_queue.live_submission.indexnow.timeout_seconds', 10)))
                ->asJson()
                ->post($endpoint, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => $keyLocation,
                    'urlList' => [$canonicalUrl],
                ]);

            return [
                'accepted' => $response->successful(),
                'http_status' => $response->status(),
                'exception_class' => null,
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
                'provider_error_code' => null,
                'provider_error_message' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'accepted' => false,
                'http_status' => null,
                'exception_class' => $exception::class,
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
                'provider_error_code' => null,
                'provider_error_message' => null,
            ];
        }
    }

    /**
     * @return array{accepted: bool, http_status: ?int, exception_class: ?class-string, endpoint_host: ?string, provider_error_code: ?string, provider_error_message: ?string}
     */
    private function submitBaiduPush(string $canonicalUrl): array
    {
        $endpoint = (string) config('seo_intel.search_channel_queue.live_submission.baidu.endpoint');
        $site = (string) config('seo_intel.search_channel_queue.live_submission.baidu.site');
        $token = (string) config('seo_intel.search_channel_queue.live_submission.baidu.token');
        $endpointWithQuery = $endpoint.'?site='.$site.'&token='.rawurlencode($token);

        try {
            $response = Http::timeout(max(1, (int) config('seo_intel.search_channel_queue.live_submission.baidu.timeout_seconds', 10)))
                ->withBody($canonicalUrl, 'text/plain')
                ->post($endpointWithQuery);

            $body = $response->json();
            $accepted = $response->successful() && (int) data_get(is_array($body) ? $body : [], 'success', 0) >= 1;
            $diagnostics = $this->sanitizedProviderDiagnostics($response, [$canonicalUrl, $site, $token]);

            return [
                'accepted' => $accepted,
                'http_status' => $response->status(),
                'exception_class' => null,
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
                'provider_error_code' => $accepted ? null : $diagnostics['provider_error_code'],
                'provider_error_message' => $accepted ? null : $diagnostics['provider_error_message'],
            ];
        } catch (Throwable $exception) {
            return [
                'accepted' => false,
                'http_status' => null,
                'exception_class' => $exception::class,
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
                'provider_error_code' => null,
                'provider_error_message' => null,
            ];
        }
    }

    /**
     * @param  array{accepted: bool, http_status: ?int, exception_class: ?class-string, endpoint_host: ?string, provider_error_code: ?string, provider_error_message: ?string}  $submission
     */
    private function platformActionRequired(array $submission): bool
    {
        $diagnostic = strtolower(trim(((string) ($submission['provider_error_code'] ?? '')).' '.((string) ($submission['provider_error_message'] ?? ''))));

        if ($diagnostic === '') {
            return false;
        }

        foreach (['site init', 'site_init', 'not initialized', 'not_initialized', 'site not verified', 'site not exist', 'site not found', '站点未', '未验证', '未在站长平台'] as $needle) {
            if (str_contains($diagnostic, $needle)) {
                return true;
            }
        }

        return false;
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
            'status' => 'ready',
            'issues' => [],
        ];
    }

    /**
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
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'submission_status' => 'not_attempted',
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
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'submission_status' => 'not_attempted',
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
        bool $externalCallsAttempted = false,
        bool $searchSubmissionAttempted = false,
        bool $writesAttempted = false,
        bool $writesCommitted = false,
    ): array {
        return [
            'runtime' => 'search_channel_bounded_live_submission',
            'status' => $status,
            'dry_run' => $dryRun,
            'queue_item_ids' => $queueItemIds,
            'queue_item_count' => count($queueItemIds),
            'channels' => $channels,
            'approval_phrase' => $expectedPhrase,
            'approval_token' => $expectedToken,
            'issues' => $issues,
            'items' => $itemResults,
            'external_calls_attempted' => $externalCallsAttempted,
            'search_submission_attempted' => $searchSubmissionAttempted,
            'writes_attempted' => $writesAttempted,
            'writes_committed' => $writesCommitted,
            'safety_flags' => [
                'global_live_gates_required' => true,
                'queue_item_ids_required' => true,
                'approved_queue_state_required' => true,
                'dry_run_default' => true,
                'scheduler_enabled' => false,
                'bulk_submission' => false,
                'raw_secret_output' => false,
                'cms_mutation' => false,
                'schema_hreflang_mutation' => false,
                'revalidation_triggered' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $sensitiveValues
     * @return array{provider_error_code: ?string, provider_error_message: ?string}
     */
    private function sanitizedProviderDiagnostics(Response $response, array $sensitiveValues): array
    {
        $body = $response->json();
        $payload = is_array($body) ? $body : [];
        $errorCode = $this->firstScalarString($payload, ['error', 'error_code', 'errno', 'status', 'code']);
        $errorMessage = $this->firstScalarString($payload, ['message', 'error_msg', 'msg', 'reason']);

        return [
            'provider_error_code' => $this->redactSensitiveText($errorCode, $sensitiveValues),
            'provider_error_message' => $this->redactSensitiveText($errorMessage, $sensitiveValues),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstScalarString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $sensitiveValues
     */
    private function redactSensitiveText(?string $value, array $sensitiveValues): ?string
    {
        if ($value === null) {
            return null;
        }

        $redacted = $value;

        foreach ($sensitiveValues as $sensitiveValue) {
            if (trim($sensitiveValue) !== '') {
                $redacted = str_replace($sensitiveValue, '[redacted]', $redacted);
            }
        }

        $redacted = (string) preg_replace('~https?://\\S+~i', '[redacted_url]', $redacted);
        $redacted = (string) preg_replace('/\\b[A-Za-z0-9_-]{16,}\\b/', '[redacted_token]', $redacted);

        return substr(trim($redacted), 0, 200);
    }

    /**
     * @param  list<object>  $items
     * @return array{target_count:int,pending_count:int,submitting_count:int,submitted_count:int,failed_count:int,issues:list<string>}
     */
    private function enneagramState(array $items): array
    {
        $issues = [];
        if (count($items) !== self::ENNEAGRAM_TARGET_COUNT) {
            $issues[] = 'queue_target_count_mismatch';
        }

        $urls = [];
        $hashes = [];
        $pending = 0;
        $submitting = 0;
        $submitted = 0;
        $failed = 0;

        foreach ($items as $item) {
            $url = trim((string) ($item->canonical_url ?? ''));
            $hash = strtolower(trim((string) ($item->url_hash ?? '')));
            $urls[] = $url;
            $hashes[] = $hash;

            $eligibility = $this->eligibilityEvaluator->evaluate([
                ...(array) $item,
                'is_private_flow' => (bool) ($item->private_flow ?? false),
            ]);
            if (! $eligibility->eligible) {
                $issues[] = 'queue_item_eligibility_invalid';
            }
            if ((string) ($item->channel ?? '') !== 'indexnow'
                || (string) ($item->page_entity_type ?? '') !== self::ENNEAGRAM_PAGE_TYPE) {
                $issues[] = 'queue_identity_mismatch';
            }
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), config('seo_intel.search_channel_queue.live_submission.allowed_hosts', []), true)) {
                $issues[] = 'queue_canonical_not_public_https';
            }
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || ! hash_equals(hash('sha256', $url), $hash)) {
                $issues[] = 'queue_url_hash_invalid';
            }

            $approvalState = (string) ($item->approval_state ?? '');
            $executionState = (string) ($item->execution_state ?? '');
            if ($approvalState === 'pending' && $executionState === 'dry_run_ready') {
                $pending++;
            } elseif ($approvalState === 'approved' && $executionState === 'submitting') {
                $submitting++;
            } elseif ($approvalState === 'approved' && $executionState === 'submitted') {
                $submitted++;
            } else {
                $failed++;
                $issues[] = 'queue_item_state_not_allowed';
            }
        }

        if (count(array_unique($urls)) !== count($items) || count(array_unique($hashes)) !== count($items)) {
            $issues[] = 'queue_identity_not_unique';
        }
        if ($submitting !== 0) {
            $issues[] = 'queue_item_inflight';
        }

        return [
            'target_count' => count($items),
            'pending_count' => $pending,
            'submitting_count' => $submitting,
            'submitted_count' => $submitted,
            'failed_count' => $failed,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @return list<string> */
    private function enneagramLiveGateIssues(): array
    {
        $issues = [];
        if (! (bool) config('seo_intel.search_channel_queue.live_submission.enabled', false)) {
            $issues[] = 'live_submission_gate_disabled';
        }
        if (! (bool) config('seo_intel.search_channel_queue.live_submission.external_api_calls_enabled', false)) {
            $issues[] = 'external_api_gate_disabled';
        }
        if (! (bool) config('seo_intel.indexnow_live_api_enabled', false)) {
            $issues[] = 'indexnow_live_api_disabled';
        }

        $endpoint = trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.endpoint'));
        $endpointHost = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (! $this->isHttpsEndpoint($endpoint)
            || ! in_array($endpointHost, config('seo_intel.search_channel_queue.live_submission.indexnow.allowed_endpoint_hosts', ['api.indexnow.org']), true)) {
            $issues[] = 'indexnow_endpoint_not_allowed';
        }
        if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key')) === '') {
            $issues[] = 'indexnow_key_missing';
        }
        if (trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location')) === '') {
            $issues[] = 'indexnow_key_location_missing';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  list<string>  $urls
     * @return array{accepted:bool,http_status:?int,endpoint_host:?string}
     */
    private function submitEnneagramIndexNowRequest(array $urls): array
    {
        $endpoint = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.endpoint');
        $key = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key');
        $keyLocation = (string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location');
        $host = strtolower((string) parse_url($urls[0] ?? '', PHP_URL_HOST));

        try {
            $response = Http::timeout(max(1, (int) config('seo_intel.search_channel_queue.live_submission.indexnow.timeout_seconds', 10)))
                ->asJson()
                ->post($endpoint, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => $keyLocation,
                    'urlList' => array_values($urls),
                ]);

            return [
                'accepted' => $response->successful(),
                'http_status' => $response->status(),
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
            ];
        } catch (Throwable) {
            return [
                'accepted' => false,
                'http_status' => null,
                'endpoint_host' => (string) parse_url($endpoint, PHP_URL_HOST),
            ];
        }
    }

    /**
     * @param  array{target_count:int,pending_count:int,submitting_count:int,submitted_count:int,failed_count:int,issues:list<string>}  $state
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function enneagramPayload(
        string $status,
        string $phase,
        string $artifactSha256,
        bool $live,
        array $state,
        int $phaseTargetCount,
        array $issues,
        bool $externalCallsAttempted = false,
        int $submittedCount = 0,
        int $failedCount = 0,
        ?int $httpStatus = null,
    ): array {
        return [
            'schema_version' => 'enneagram-indexnow-production-submit.v1',
            'status' => $status,
            'ok' => in_array($status, ['dry_run_ready', 'success', 'already_completed'], true),
            'phase' => $phase,
            'dry_run' => ! $live,
            'artifact_sha256' => preg_match(self::ENNEAGRAM_ARTIFACT_PATTERN, $artifactSha256) === 1 ? $artifactSha256 : null,
            'target_count' => $state['target_count'],
            'phase_target_count' => $phaseTargetCount,
            'state_counts' => [
                'pending' => $state['pending_count'],
                'submitting' => $state['submitting_count'],
                'submitted' => $state['submitted_count'],
                'failed' => $state['failed_count'],
            ],
            'submitted_count' => $submittedCount,
            'failed_count' => $failedCount,
            'provider_http_status' => $httpStatus,
            'issues' => array_values(array_unique($issues)),
            'external_calls_attempted' => $externalCallsAttempted,
            'search_submission_attempted' => $externalCallsAttempted,
            'writes_committed' => $live && $status !== 'blocked',
            'negative_guarantees' => [
                'queue_payload_exposed' => false,
                'provider_credentials_exposed' => false,
                'cms_or_eligibility_write' => false,
                'sitemap_or_llms_cache_mutation' => false,
                'deploy' => false,
            ],
        ];
    }
}
