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
    private const V2_TABLE = 'scales_registry_v2';

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

        $scales = $this->loadScalesForSlugSync();
        foreach ($scales as $scale) {
            $writer->syncSlugsForScale($scale);
        }

        $this->info('Scale slugs sync complete.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int,ScaleRegistryModel>
     */
    private function loadScalesForSlugSync()
    {
        if (!Schema::hasTable(self::V2_TABLE) || !(bool) config('fap.scales_registry.use_v2', true)) {
            return ScaleRegistryModel::query()->orderBy('code')->get();
        }

        $rows = DB::table(self::V2_TABLE)
            ->orderBy('org_id')
            ->orderBy('code')
            ->get();

        return $rows->map(function (object $row): ScaleRegistryModel {
            $payload = (array) $row;
            if (is_string($payload['slugs_json'] ?? null)) {
                $decoded = json_decode((string) $payload['slugs_json'], true);
                $payload['slugs_json'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($payload['slugs_json'] ?? null)) {
                $payload['slugs_json'] = [];
            }

            $model = new ScaleRegistryModel();
            $model->forceFill($payload);

            return $model;
        });
    }
}
