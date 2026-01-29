<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Console\Command;

class SeedScaleRegistry extends Command
{
    protected $signature = 'fap:scales:seed-default';
    protected $description = 'Seed default scales registry and slugs.';

    public function handle(): int
    {
        $this->call(ScaleRegistrySeeder::class);
        return self::SUCCESS;
    }
}
