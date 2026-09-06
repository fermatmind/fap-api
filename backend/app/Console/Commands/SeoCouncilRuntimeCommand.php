<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Console\Command;

final class SeoCouncilRuntimeCommand extends Command
{
    protected $signature = 'seo:council-runtime
        {operation=status : status, pause, resume, acceptance-begin, acceptance-complete, or acceptance-abort}
        {--operation-ref= : Exact deploy run/attempt/SHA reference for controlled acceptance}
        {--adopt-historical-pause : Staging-only authorization to replace one legacy unknown pause during acceptance}
        {--expected-generation= : Generation read after acceptance-begin}';

    protected $description = 'Inspect or pause/resume Council-only read-only computation; never enables business writes';

    public function handle(Platform12RuntimeControl $runtime): int
    {
        $operation = $this->argument('operation');
        if (! in_array($operation, ['status', 'pause', 'resume', 'acceptance-begin', 'acceptance-complete', 'acceptance-abort'], true)) {
            $this->line('{"state":"OPERATION_DENIED","business_write_enabled":false}');

            return self::FAILURE;
        }
        $result = match ($operation) {
            'status' => $runtime->status(),
            'pause', 'resume' => $runtime->change($operation === 'pause'),
            'acceptance-begin' => $runtime->beginControlledAcceptance(
                (string) $this->option('operation-ref'),
                (bool) $this->option('adopt-historical-pause'),
            ),
            'acceptance-complete', 'acceptance-abort' => $runtime->finishControlledAcceptance(
                (string) $this->option('operation-ref'),
                (string) $this->option('expected-generation'),
                $operation === 'acceptance-complete',
            ),
        };
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $success = match ($operation) {
            'status' => true,
            'pause' => ($result['pause_source'] ?? null) === Platform12RuntimeControl::PAUSE_MANUAL,
            'resume' => ($result['state'] ?? null) === 'ACTIVE_READ_ONLY',
            'acceptance-begin' => ($result['controlled_acceptance_enabled'] ?? false) === true,
            'acceptance-complete' => in_array($result['state'] ?? null, ['ACTIVE_READ_ONLY', 'MANUAL_PAUSE_HOLD'], true),
            'acceptance-abort' => ($result['pause_reason'] ?? null) === 'CONTROLLED_ACCEPTANCE_FAILED',
        };

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
