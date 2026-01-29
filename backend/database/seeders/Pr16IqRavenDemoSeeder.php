<?php

namespace Database\Seeders;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class Pr16IqRavenDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->command?->warn('Pr16IqRavenDemoSeeder skipped: missing tables.');
            return;
        }

        $writer = app(ScaleRegistryWriter::class);
        $scale = $writer->upsertScale([
            'code' => 'IQ_RAVEN',
            'org_id' => 0,
            'primary_slug' => 'iq-test',
            'slugs_json' => [
                'iq-test',
            ],
            'driver_type' => 'iq_test',
            'default_pack_id' => 'default',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'assets' => true,
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('Pr16IqRavenDemoSeeder: IQ_RAVEN scale upserted.');
    }
}
