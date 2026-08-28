<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keeps legacy command logic available to its existing unit contracts while
 * removing every seo-agent:* wrapper from normal Artisan discovery.
 */
abstract class RetiredSeoAgentCommand extends Command
{
    public const AGENT_INVOCABLE = false;

    public function isEnabled(): bool
    {
        return app()->runningUnitTests();
    }
}
