<?php

namespace App\Console\Commands;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncScaleSlugs extends Command
{
    protected $signature = 'fap:scales:sync-slugs';
    protected $description = 'Rebuild scale_slugs from scales_registry.';

    public function handle(): int
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->warn('scale tables missing; migrate first.');
            return self::FAILURE;
        }

        $writer = app(ScaleRegistryWriter::class);

        DB::transaction(function () {
            ScaleSlug::query()->delete();
        });

        $scales = ScaleRegistryModel::query()->orderBy('code')->get();
        foreach ($scales as $scale) {
            $writer->syncSlugsForScale($scale);
        }

        $this->info('Scale slugs sync complete.');

        return self::SUCCESS;
    }
}
