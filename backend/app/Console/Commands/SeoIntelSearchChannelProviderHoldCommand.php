<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueProviderHoldRecorder;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelProviderHoldCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-provider-hold
        {--queue-ids= : Comma-separated Search Channel Queue item ids}
        {--channels=baidu_push : Comma-separated allowed channels; only baidu_push is supported}
        {--reason=transport_security_unavailable : Provider hold reason}
        {--approval-phrase= : Exact provider hold approval phrase}
        {--approval-token= : SHA-256 token for the exact provider hold approval phrase}
        {--actor=operator : Sanitized actor id for audit events}
        {--execute : Commit provider hold; omitted means dry-run}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Record an audited Baidu transport-security hold without submitting a URL or exposing credentials.';

    public function handle(SearchChannelQueueProviderHoldRecorder $recorder): int
    {
        $payload = $recorder->record(
            queueItemIds: $this->positiveIntegerList($this->option('queue-ids')),
            channels: $this->stringList($this->option('channels')),
            reason: $this->nullableOption('reason'),
            approvalPhrase: $this->nullableOption('approval-phrase'),
            approvalToken: $this->nullableOption('approval-token'),
            actorId: $this->actor(),
            dryRun: ! (bool) $this->option('execute'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach (['status', 'dry_run', 'queue_item_count', 'writes_attempted', 'writes_committed', 'external_calls_attempted', 'search_submission_attempted'] as $key) {
                $this->line($key.'='.$this->stringValue($payload[$key] ?? null));
            }
        }

        return ($payload['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): int => max(0, (int) trim($part)),
            explode(',', (string) $value),
        ), static fn (int $id): bool => $id > 0)));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', (string) $value),
        ), static fn (string $part): bool => $part !== '')));
    }

    private function nullableOption(string $key): ?string
    {
        $value = trim((string) $this->option($key));

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

        return is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;
    }
}
