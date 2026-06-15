<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueRetryResetter;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelRetryResetCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-retry-reset
        {--queue-ids= : Comma-separated Search Channel Queue item ids}
        {--channels=baidu_push : Comma-separated allowed channels; only baidu_push is supported}
        {--reason=provider_quota_reset : Bounded retry reset reason}
        {--approval-phrase= : Exact bounded retry reset approval phrase}
        {--approval-token= : SHA-256 token for the exact bounded retry reset approval phrase}
        {--actor=operator : Sanitized actor id for audit events}
        {--execute : Commit retry reset; omitted means dry-run}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Reset Baidu Search Channel queue items from provider-quota submit_failed to dry_run_ready.';

    public function handle(SearchChannelQueueRetryResetter $resetter): int
    {
        $queueIds = $this->positiveIntegerList($this->option('queue-ids'));
        $channels = $this->stringList($this->option('channels'));
        $execute = (bool) $this->option('execute');

        $payload = $resetter->reset(
            queueItemIds: $queueIds,
            channels: $channels,
            reason: $this->nullableOption('reason'),
            approvalPhrase: $this->nullableOption('approval-phrase'),
            approvalToken: $this->nullableOption('approval-token'),
            actorId: $this->actor(),
            dryRun: ! $execute,
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
            foreach (['status', 'dry_run', 'queue_item_count', 'writes_attempted', 'writes_committed', 'external_calls_attempted', 'search_submission_attempted'] as $key) {
                $this->line($key.'='.$this->stringValue($payload[$key] ?? null));
            }
        }

        return ($payload['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<int>
     */
    private function positiveIntegerList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): int => max(0, (int) trim($part)),
            explode(',', (string) $value),
        ), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', (string) $value),
        ), static fn (string $part): bool => $part !== '')));
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
