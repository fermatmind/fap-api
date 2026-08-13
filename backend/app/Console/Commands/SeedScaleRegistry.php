<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Console\Command;

class SeedScaleRegistry extends Command
{
    protected $signature = 'fap:scales:seed-default
        {--preserve-existing-big-five-content : Preserve existing Big Five CMS editorial content while seeding operational defaults}';

    protected $description = 'Seed default scales registry and slugs.';

    public function handle(): int
    {
        app(ScaleRegistrySeeder::class)
            ->setContainer($this->laravel)
            ->setCommand($this)
            ->__invoke([
                'preserveExistingBigFiveContent' => (bool) $this->option('preserve-existing-big-five-content'),
            ]);

        return self::SUCCESS;
    }
}
