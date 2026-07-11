<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

final class SearchChannelProviderCapabilityEvaluator
{
    /**
     * @param  list<int>  $queueItemIds
     * @param  list<string>  $channels
     * @return array<string,mixed>
     */
    public function evaluate(array $queueItemIds, array $channels): array
    {
        $configuredChannels = array_values(config('seo_intel.search_channel_queue.live_submission.allowed_channels', []));
        $channels = array_values(array_unique(array_intersect($channels === [] ? $configuredChannels : $channels, $configuredChannels)));
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $items = $queueItemIds === []
            ? collect()
            : $connection->table('seo_search_channel_queue_items')->whereIn('id', $queueItemIds)->orderBy('id')->get();
        $foundIds = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $results = [];

        foreach (array_values(array_diff($queueItemIds, $foundIds)) as $missingId) {
            $results[] = $this->blockedItem($missingId, null, ['queue_item_not_found']);
        }

        foreach ($items as $item) {
            $itemIssues = $this->itemIssues($item, $channels);
            $capability = $this->providerCapability((string) $item->channel);
            $issues = array_values(array_unique([...$itemIssues, ...$capability['issues']]));
            $results[] = [
                'queue_item_id' => (int) $item->id,
                'channel' => (string) $item->channel,
                'canonical_url' => (string) $item->canonical_url,
                'status' => $issues === [] ? $capability['status'] : 'blocked',
                'next_action' => $issues === [] ? $capability['next_action'] : null,
                'provider_host' => $capability['provider_host'],
                'transport' => $capability['transport'],
                'credentials_configured' => $capability['credentials_configured'],
                'issues' => $issues,
            ];
        }

        $issues = array_values(array_unique([
            ...($queueItemIds === [] ? ['queue_ids_required'] : []),
            ...($channels === [] ? ['valid_channels_required'] : []),
            ...array_merge(...array_map(static fn (array $item): array => $item['issues'], $results)),
        ]));

        return [
            'runtime' => 'search_channel_provider_capability_preflight',
            'status' => $issues === [] ? 'success' : 'blocked',
            'queue_item_ids' => $queueItemIds,
            'channels' => $channels,
            'issues' => $issues,
            'items' => $results,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'token_output' => false,
            'tls_verification_disabled' => false,
        ];
    }

    /** @param list<string> $channels @return list<string> */
    private function itemIssues(object $item, array $channels): array
    {
        $url = trim((string) $item->canonical_url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $issues = array_values(array_filter([
            ! in_array((string) $item->channel, $channels, true) ? 'channel_not_requested' : null,
            (string) $item->eligibility_state !== 'eligible' ? 'item_not_eligible' : null,
            (string) $item->approval_state !== 'approved' ? 'approval_state_not_approved' : null,
            (string) $item->execution_state !== 'dry_run_ready' ? 'execution_state_not_dry_run_ready' : null,
            (string) $item->indexability_state !== 'indexable' ? 'non_indexable_rejected' : null,
            (string) $item->claim_boundary_state !== 'claim_safe' ? 'claim_unsafe_rejected' : null,
            (bool) $item->private_flow ? 'private_flow_rejected' : null,
            filter_var($url, FILTER_VALIDATE_URL) === false ? 'invalid_canonical_url' : null,
            strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' ? 'non_https_url_rejected' : null,
            ! in_array($host, config('seo_intel.search_channel_queue.live_submission.allowed_hosts', []), true) ? 'host_not_allowed' : null,
            ! in_array((string) $item->source_authority, config('seo_intel.search_channel_queue.approved_source_authorities', []), true) ? 'source_authority_not_approved' : null,
        ]));

        return array_values(array_unique($issues));
    }

    /** @return array{status:string,next_action:string,provider_host:?string,transport:array<string,mixed>,credentials_configured:bool,issues:list<string>} */
    private function providerCapability(string $channel): array
    {
        $configKey = $channel === 'baidu_push' ? 'baidu' : ($channel === 'indexnow' ? 'indexnow' : '');
        if ($configKey === '') {
            return $this->capability('blocked', '', null, false, false, false, ['unsupported_live_submission_channel']);
        }

        $endpoint = trim((string) config('seo_intel.search_channel_queue.live_submission.'.$configKey.'.endpoint'));
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $allowedHosts = array_values(config('seo_intel.search_channel_queue.live_submission.'.$configKey.'.allowed_endpoint_hosts', []));
        $validUrl = filter_var($endpoint, FILTER_VALIDATE_URL) !== false && $host !== '';
        $https = $validUrl && $scheme === 'https';
        $allowedHost = $host !== '' && in_array($host, $allowedHosts, true);
        $credentialsConfigured = $channel === 'indexnow'
            ? trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key')) !== ''
                && trim((string) config('seo_intel.search_channel_queue.live_submission.indexnow.key_location')) !== ''
            : trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.site')) !== ''
                && trim((string) config('seo_intel.search_channel_queue.live_submission.baidu.token')) !== '';

        if (! $credentialsConfigured) {
            return $this->capability('blocked', $endpoint, $host, $https, $allowedHost, false, ['provider_credentials_missing']);
        }

        if ($channel === 'baidu_push' && (! $https || ! $allowedHost)) {
            return $this->capability('provider_security_hold_ready', $endpoint, $host, $https, $allowedHost, true, []);
        }

        $issues = array_values(array_filter([
            ! $validUrl ? 'provider_endpoint_missing_or_invalid' : null,
            ! $https ? 'provider_endpoint_not_https' : null,
            ! $allowedHost ? 'provider_endpoint_host_not_allowed' : null,
        ]));

        return $this->capability($issues === [] ? 'submit_ready' : 'blocked', $endpoint, $host, $https, $allowedHost, true, $issues);
    }

    /** @param list<string> $issues @return array{status:string,next_action:string,provider_host:?string,transport:array<string,mixed>,credentials_configured:bool,issues:list<string>} */
    private function capability(string $status, string $endpoint, ?string $host, bool $https, bool $allowedHost, bool $credentialsConfigured, array $issues): array
    {
        return [
            'status' => $status,
            'next_action' => $status === 'provider_security_hold_ready' ? 'record_audited_provider_security_hold' : ($status === 'submit_ready' ? 'bounded_live_submit' : ''),
            'provider_host' => $host !== '' ? $host : null,
            'transport' => [
                'endpoint_configured' => $endpoint !== '',
                'https' => $https,
                'host_allowed' => $allowedHost,
                'tls_verification_enabled' => true,
            ],
            'credentials_configured' => $credentialsConfigured,
            'issues' => $issues,
        ];
    }

    /** @param list<string> $issues @return array<string,mixed> */
    private function blockedItem(int $queueItemId, ?object $item, array $issues): array
    {
        return [
            'queue_item_id' => $queueItemId,
            'channel' => $item?->channel,
            'canonical_url' => $item?->canonical_url,
            'status' => 'blocked',
            'next_action' => null,
            'provider_host' => null,
            'transport' => null,
            'credentials_configured' => false,
            'issues' => $issues,
        ];
    }
}
