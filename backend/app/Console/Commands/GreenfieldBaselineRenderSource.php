<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineSourceScript;
use Illuminate\Console\Command;

final class GreenfieldBaselineRenderSource extends Command
{
    protected $signature = 'greenfield:baseline:render-source';

    protected $description = 'Render the standalone SELECT-only Greenfield source exporter to stdout.';

    public function __construct(
        private readonly GreenfieldBaselineSourceScript $sourceScript,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->output->write($this->sourceScript->render());

        return self::SUCCESS;
    }
}
