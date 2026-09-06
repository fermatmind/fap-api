<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Runtime\ProductionCalibrationProbeService;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use Illuminate\Console\Command;

final class SeoPlatformRuntimeProbeScheduled extends Command
{
    protected $signature = 'seo:runtime-probe-scheduled
        {--trigger=manual : Receipt provenance; the scheduler supplies scheduled}
        {--scope=full : Full natural calibration or private-negative-set for controlled acceptance}
        {--json : Emit the sanitized receipt as JSON}';

    protected $description = 'Record a bounded, sanitized SEO runtime probe scheduler receipt';

    public function handle(
        ScheduledRuntimeProbeReceiptService $service,
        ProductionCalibrationProbeService $calibration,
    ): int {
        $trigger = (string) $this->option('trigger');
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['full', 'private-negative-set'], true)
            || ($scope === 'private-negative-set' && $trigger !== 'manual')) {
            $this->error('Runtime probe scope is invalid for the selected trigger.');

            return self::INVALID;
        }
        $receipt = $service->record(
            $trigger,
            calibration: $scope === 'private-negative-set'
                ? $calibration->observePrivateNegativeSet()
                : $calibration->observe(),
        );
        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status='.(string) ($receipt['status'] ?? 'MEASUREMENT_HOLD'));
            $this->line('receipt_hash='.(string) ($receipt['receipt_hash'] ?? ''));
        }

        return self::SUCCESS;
    }
}
