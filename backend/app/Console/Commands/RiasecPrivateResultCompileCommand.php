<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\RiasecPrivateResultCompileService;
use Illuminate\Console\Command;

final class RiasecPrivateResultCompileCommand extends Command
{
    protected $signature = 'riasec:private-result-compile';

    protected $description = 'Deterministically compile the canonical RIASEC Chinese private-result authority';

    public function handle(RiasecPrivateResultCompileService $compiler): int
    {
        $result = $compiler->materialize();
        $this->line(json_encode([
            'ok' => true,
            'authority_id' => RiasecPrivateResultCompileService::AUTHORITY_ID,
            'source_hash' => $result['source_hash'],
            'compiled_hash' => $result['compiled_hash'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
