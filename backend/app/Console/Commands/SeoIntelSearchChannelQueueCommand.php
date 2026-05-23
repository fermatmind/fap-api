<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelQueueCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-queue
        {--dry-run : Plan queue candidates without writing}
        {--no-write : Prevent writes even when the write gate is enabled}
        {--enqueue : Explicitly request queue row creation through the existing write gate}
        {--json : Output safe machine-readable JSON}
        {--channel= : Restrict planning to one supported channel}
        {--canonical-url= : Restrict planning to one persisted canonical URL}
        {--page-type= : Restrict planning to one page entity type}
        {--limit=20 : Maximum URL Truth rows to inspect}';

    protected $description = 'Plan or enqueue Search Channel Queue candidates without live search submission.';

    public function handle(SearchChannelQueuePlanner $planner, SearchChannelQueueWriteService $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $noWrite = (bool) $this->option('no-write');
        $enqueue = (bool) $this->option('enqueue');
        $limit = max(1, min((int) $this->option('limit'), 500));
        $channel = $this->nullableOption('channel');
        $canonicalUrl = $this->nullableOption('canonical-url');
        $pageType = $this->nullableOption('page-type');

        $plan = $planner->plan($channel, $pageType, $limit, $canonicalUrl);
        $plannedItems = $plan['planned_items'];
        $writeGateEnabled = (bool) config('seo_intel.search_channel_queue.write_enabled', false);
        $writeRequested = ! $dryRun && ! $noWrite;
        $writesAttempted = false;
        $writesCommitted = false;
        $enqueueAttempted = false;
        $enqueueCommitted = false;
        $writeResult = [
            'batch_ids' => [],
            'written_items' => 0,
        ];
        $status = 'success';
        $issues = [];
        $duplicateDetected = (bool) ($plan['duplicate_detected'] ?? false);

        if ($canonicalUrl !== null && $plan['source_unavailable_reason'] === null && (int) $plan['candidate_count'] === 0) {
            $issues[] = 'canonical_url_not_found';
        }

        if ($canonicalUrl !== null && $plan['source_unavailable_reason'] === null && (int) $plan['candidate_count'] > 0 && (int) $plan['eligible_count'] === 0) {
            $issues[] = 'canonical_url_not_eligible';
        }

        if ($canonicalUrl !== null && $duplicateDetected) {
            $issues[] = 'existing_active_queue_item';
        }

        $enqueueConflictBlocked = $enqueue && ($dryRun || $noWrite);

        if ($enqueueConflictBlocked) {
            $issues[] = 'enqueue_conflicts_with_dry_run_or_no_write';
        }

        $canonicalFilterBlocked = $canonicalUrl !== null
            && $plan['source_unavailable_reason'] === null
            && $issues !== [];

        if ($writeRequested && $canonicalUrl !== null && $channel === null) {
            $issues[] = 'canonical_url_requires_channel';
        }

        if ($writeRequested && $channel !== null && ($plan['selected_channels'] ?? []) === []) {
            $issues[] = 'channel_not_allowed';
        }

        if ($canonicalFilterBlocked || $enqueueConflictBlocked) {
            $status = 'blocked';
        }

        if ($writeRequested && $issues !== []) {
            $writesAttempted = true;
            $enqueueAttempted = true;
            $status = 'blocked';
        } elseif ($writeRequested && ! $writeGateEnabled) {
            $writesAttempted = true;
            $enqueueAttempted = true;
            $status = 'blocked';
            $issues[] = 'write_gate_disabled';
        } elseif ($writeRequested && $plannedItems !== []) {
            $writesAttempted = true;
            $enqueueAttempted = true;
            $writeResult = $writer->write($plannedItems);
            $writesCommitted = ((int) $writeResult['written_items']) > 0;
            $enqueueCommitted = $writesCommitted;
        }

        if ($plan['source_unavailable_reason'] !== null) {
            $issues[] = $plan['source_unavailable_reason'];
        }

        $issues = array_values(array_unique($issues));

        $payload = [
            'runtime' => 'search_channel_queue',
            'status' => $status,
            'dry_run' => $dryRun,
            'no_write' => $noWrite,
            'canonical_url_filter' => $canonicalUrl,
            'writes_attempted' => $writesAttempted,
            'writes_committed' => $writesCommitted,
            'enqueue_attempted' => $enqueueAttempted,
            'enqueue_committed' => $enqueueCommitted,
            'duplicate_detected' => $duplicateDetected,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'live_submission_attempted' => false,
            'crawler_log_read_attempted' => false,
            'cms_mutation_attempted' => false,
            'url_truth_write_attempted' => false,
            'candidate_count' => $plan['candidate_count'],
            'eligible_count' => $plan['eligible_count'],
            'blocked_count' => $plan['blocked_count'],
            'planned_queue_count' => $plan['planned_queue_count'],
            'channel_breakdown' => $plan['channel_breakdown'],
            'page_type_breakdown' => $plan['page_type_breakdown'],
            'reason_code_breakdown' => $plan['reason_code_breakdown'],
            'selected_candidate' => $plan['selected_candidate'],
            'target_tables' => config('seo_intel.search_channel_queue.target_tables', []),
            'protected_legacy_tables_not_written' => config('seo_intel.search_channel_queue.protected_legacy_tables', []),
            'write_gate_env' => 'SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED',
            'write_gate_enabled' => $writeGateEnabled,
            'batch_ids' => $writeResult['batch_ids'],
            'written_items' => $writeResult['written_items'],
            'issues' => $issues,
            'safety_flags' => [
                'no_live_submission' => true,
                'no_external_api' => true,
                'no_submit_mode' => true,
                'scheduler_enabled' => false,
                'collector_write_attempted' => false,
                'production_crawler_log_read' => false,
                'sitemap_llms_behavior_changed' => false,
                'secrets_required' => false,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach (['status', 'dry_run', 'writes_attempted', 'writes_committed', 'external_calls_attempted', 'search_submission_attempted', 'candidate_count', 'eligible_count', 'blocked_count', 'planned_queue_count'] as $key) {
                $this->line($key.'='.$this->stringValue($payload[$key]));
            }
        }

        return $status === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    private function nullableOption(string $key): ?string
    {
        $value = $this->option($key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
