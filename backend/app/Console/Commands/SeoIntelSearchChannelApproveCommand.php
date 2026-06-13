<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueApprovalExecutor;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelApproveCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-approve
        {--queue-ids= : Comma-separated Search Channel Queue item ids}
        {--channels= : Comma-separated allowed channels: indexnow,baidu_push}
        {--approval-phrase= : Exact bounded queue approval phrase}
        {--approval-token= : SHA-256 token for the exact bounded queue approval phrase}
        {--actor=operator : Sanitized actor id for audit events}
        {--approve : Commit approval; omitted means dry-run}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Approve bounded Search Channel queue items for the draft-safe live executor.';

    public function handle(SearchChannelQueueApprovalExecutor $executor): int
    {
        $queueIds = $this->positiveIntegerList($this->option('queue-ids'));
        $channels = $this->stringList($this->option('channels'));
        $approve = (bool) $this->option('approve');

        $payload = $executor->approve(
            queueItemIds: $queueIds,
            channels: $channels,
            approvalPhrase: $this->nullableOption('approval-phrase'),
            approvalToken: $this->nullableOption('approval-token'),
            actorId: $this->actor(),
            dryRun: ! $approve,
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
            foreach (['status', 'dry_run', 'queue_item_count', 'writes_committed'] as $key) {
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
