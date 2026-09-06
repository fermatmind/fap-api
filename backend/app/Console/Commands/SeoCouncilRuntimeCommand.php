<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Console\Command;

final class SeoCouncilRuntimeCommand extends Command
{
    protected $signature = 'seo:council-runtime {operation=status : status, pause or resume}';

    protected $description = 'Inspect or pause/resume Council-only read-only computation; never enables business writes';

    public function handle(Platform12RuntimeControl $runtime): int
    {
        $operation = $this->argument('operation');
        if (! in_array($operation, ['status', 'pause', 'resume'], true)) {
            $this->line('{"state":"OPERATION_DENIED","business_write_enabled":false}');

            return self::FAILURE;
        }
        $result = $operation === 'status' ? $runtime->status() : $runtime->change($operation === 'pause');
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $operation === 'status' || $result['state'] === ($operation === 'pause' ? 'PAUSED' : 'ACTIVE_READ_ONLY')
            ? self::SUCCESS : self::FAILURE;
    }
}
