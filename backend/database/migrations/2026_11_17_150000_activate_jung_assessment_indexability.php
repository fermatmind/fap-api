<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        // Older releases can continue reading the registered identities.
    }
};
