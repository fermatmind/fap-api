<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Recovery\CareerGuideLocaleRecovery;
use Illuminate\Console\Command;
use Throwable;

final class CareerRecoverGuideLocaleCorruption extends Command
{
    protected $signature = 'career:recover-guide-locale-corruption
        {--execute : Apply the bound recovery after preflight}
        {--json : Emit a machine-readable result}';

    protected $description = 'Recover the exact 20-guide locale corruption cohort from committed bilingual baselines.';

    public function handle(CareerGuideLocaleRecovery $recovery): int
    {
        try {
            $result = $recovery->run((bool) $this->option('execute'));
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ((bool) $this->option('json')) {
                $this->line($encoded);
            } else {
                $this->line('operation_version='.$result['operation_version']);
                $this->line('status='.$result['status']);
                $this->line('created_count='.$result['writes']['created_count']);
                $this->line('updated_count='.$result['writes']['updated_count']);
                $this->line('readback_count='.(int) data_get($result, 'readback.row_count', 0));
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
