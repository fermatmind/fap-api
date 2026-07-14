<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\PublicContentDeliveryProbeService;
use Illuminate\Console\Command;
use Throwable;

final class ProbePublicContentDelivery extends Command
{
    protected $signature = 'public-content:probe-delivery
        {--all : Probe every fixed allowlisted target instead of the next rotation target}
        {--json : Emit one machine-readable JSON document}';

    protected $description = 'Probe fixed anonymous public content delivery targets without retaining response bodies.';

    public function handle(PublicContentDeliveryProbeService $probes): int
    {
        try {
            $items = (bool) $this->option('all') ? $probes->probeAll() : [$probes->probeNext()];
            $ok = collect($items)->every(static fn (array $item): bool => ($item['ok'] ?? false) === true);
            $report = [
                'ok' => $ok,
                'scope' => 'fixed_anonymous_public_allowlist',
                'mode' => (bool) $this->option('all') ? 'all' : 'rotated',
                'items' => $items,
            ];
        } catch (Throwable) {
            $report = [
                'ok' => false,
                'scope' => 'fixed_anonymous_public_allowlist',
                'mode' => (bool) $this->option('all') ? 'all' : 'rotated',
                'error_code' => 'probe_configuration_or_storage_unavailable',
                'items' => [],
            ];
            $ok = false;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($report['items'] as $item) {
                $this->line(sprintf(
                    'target=%s priority=%s status=%d cache=%s bytes=%d duration_ms=%.2f result=%s',
                    $item['target_id'],
                    $item['priority'],
                    $item['status_code'],
                    $item['cache_state'],
                    $item['bytes'],
                    $item['duration_ms'],
                    $item['ok'] ? 'pass' : 'fail',
                ));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
