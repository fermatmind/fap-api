<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Console\Command;

final class SeoCouncilAcceptanceReadbackCommand extends Command
{
    protected $signature = 'seo:council-acceptance-readback
        {--receipt-hashes= : Comma-separated hashes from the three controlled Mission receipts}';

    protected $description = 'Verify the three controlled receipts through the same sanitized read model used by Operations';

    public function handle(
        Platform12RuntimeControl $runtime,
        Platform12SystemHealthReadService $health,
    ): int {
        $expected = array_values(array_filter(array_map(
            static fn (string $hash): string => strtolower(trim($hash)),
            explode(',', (string) $this->option('receipt-hashes')),
        )));
        $validHashes = count($expected) === 3
            && count(array_unique($expected)) === 3
            && count(array_filter($expected, static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/D', $hash) === 1)) === 3;
        $status = $runtime->status();
        $snapshot = $health->snapshot();
        $items = data_get($snapshot, 'daily_missions.items');
        $observed = is_array($items) ? array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_array($item) && is_string($item['receipt_hash'] ?? null)
                ? $item['receipt_hash'] : null,
            $items,
        ))) : [];
        sort($expected);
        sort($observed);
        $projectionVerified = $validHashes && $observed === $expected;
        $controlled = ($status['controlled_acceptance_enabled'] ?? false) === true
            && ($status['runtime_phase'] ?? null) === 'CONTROLLED_ACCEPTANCE_ONLY';
        $notification = ($status['notification_configuration_verified'] ?? false) === true;
        $result = [
            'status' => $projectionVerified && $controlled && $notification ? 'pass' : 'hold',
            'receipt_to_ui_verified' => $projectionVerified,
            'notification_configuration_verified' => $notification,
            'controlled_acceptance_verified' => $controlled,
            'receipt_count' => count($observed),
            'business_write_enabled' => false,
        ];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
