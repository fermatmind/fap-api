<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelProviderCapabilityEvaluator;
use Illuminate\Console\Command;

final class SeoIntelSearchChannelProviderPreflightCommand extends Command
{
    protected $signature = 'seo-intel:search-channel-provider-preflight
        {--queue-ids= : Comma-separated Search Channel Queue item ids}
        {--channels= : Comma-separated channels: indexnow,baidu_push}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Evaluate Search Channel provider transport and queue readiness without external calls or writes.';

    public function handle(SearchChannelProviderCapabilityEvaluator $evaluator): int
    {
        $payload = $evaluator->evaluate(
            $this->positiveIntegerList($this->option('queue-ids')),
            $this->stringList($this->option('channels')),
        );

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return ($payload['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): int => max(0, (int) trim($part)),
            explode(',', (string) $value),
        ))));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', (string) $value),
        ))));
    }
}
