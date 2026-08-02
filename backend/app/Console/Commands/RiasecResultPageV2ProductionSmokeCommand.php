<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Riasec\RiasecResultPageV2ProductionSmokeVerifier;
use Illuminate\Console\Command;

final class RiasecResultPageV2ProductionSmokeCommand extends Command
{
    protected $signature = 'riasec:result-page-v2-production-smoke {--json : Emit machine-readable JSON}';

    protected $description = 'Run the read-only RIASEC Result Page V2 production contract smoke for 60/140 forms.';

    public function handle(RiasecResultPageV2ProductionSmokeVerifier $verifier): int
    {
        $summary = $verifier->verify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($summary['decision'] ?? null) === 'pass') {
            $this->info('RIASEC Result Page V2 read-only production smoke passed.');
        } else {
            $this->error('RIASEC Result Page V2 read-only production smoke failed.');
            foreach ((array) ($summary['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return ($summary['decision'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
