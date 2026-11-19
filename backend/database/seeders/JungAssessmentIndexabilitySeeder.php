<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class JungAssessmentIndexabilitySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                foreach ([
                    'JUNG_ARCHETYPES' => 'jung-archetypes',
                    'JUNG_COGNITIVE_FUNCTIONS' => 'jung-cognitive-functions',
                ] as $code => $slug) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)
                        ->where('primary_slug', $slug)->where('default_pack_id', $code.'.v1')
                        ->where('is_active', true)->where('is_public', true);
                    if ($row->lockForUpdate()->first() === null) {
                        throw new RuntimeException('Expected published Jung assessment identity is unavailable.');
                    }
                    $row->update(['is_indexable' => true]);
                }
            }
        });
    }
}
