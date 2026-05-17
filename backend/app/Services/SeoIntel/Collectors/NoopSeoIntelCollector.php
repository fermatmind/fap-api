<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class NoopSeoIntelCollector implements SeoIntelCollector
{
    public function name(): string
    {
        return 'noop';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: (bool) ($options['dry_run'] ?? true),
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: 0,
            issues: [],
            metadata: [
                'skeleton_only' => true,
                'production_data_reads' => false,
                'node2_local_laravel_data_source' => false,
            ],
        );
    }
}
