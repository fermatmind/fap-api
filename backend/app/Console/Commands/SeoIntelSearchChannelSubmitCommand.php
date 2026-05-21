<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueLiveSubmissionExecutor;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelSubmitCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-submit
        {--queue-item-id= : Exact Search Channel Queue item id}
        {--approval-phrase= : Exact human approval phrase}
        {--actor=operator : Sanitized actor id for audit events}
        {--dry-run : Validate without writes or external calls}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Execute a guarded Search Channel live submission for one approved queue item.';

    public function handle(SearchChannelQueueLiveSubmissionExecutor $executor): int
    {
        $queueItemId = (int) $this->option('queue-item-id');

        if ($queueItemId < 1) {
            $payload = [
                'runtime' => 'search_channel_live_submission',
                'status' => 'blocked',
                'issues' => ['queue_item_id_required'],
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
                'writes_committed' => false,
            ];

            return $this->finish($payload);
        }

        $payload = $executor->submit(
            $queueItemId,
            $this->nullableOption('approval-phrase'),
            $this->actor(),
            (bool) $this->option('dry-run'),
        );

        return $this->finish($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach (['status', 'queue_item_id', 'channel', 'dry_run', 'external_calls_attempted', 'search_submission_attempted', 'writes_committed', 'submission_status'] as $key) {
                $this->line($key.'='.$this->stringValue($payload[$key] ?? null));
            }
        }

        return ($payload['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
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

    private function actor(): string
    {
        $actor = preg_replace('/[^A-Za-z0-9:_@.-]/', '_', (string) ($this->option('actor') ?: 'operator'));

        return substr((string) $actor, 0, 128);
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
