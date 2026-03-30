<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intent_registry')) {
            Schema::create('intent_registry', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('governable_type', 160);
                $table->unsignedBigInteger('governable_id');
                $table->string('page_type', 32);
                $table->string('primary_query', 255);
                $table->string('canonical_governable_type', 160)->nullable();
                $table->unsignedBigInteger('canonical_governable_id')->nullable();
                $table->string('resolution_strategy', 32)->default('canonical');
                $table->text('exception_reason')->nullable();
                $table->decimal('latest_similarity_score', 5, 3)->nullable();
                $table->timestamps();

                $table->unique(['governable_type', 'governable_id'], 'intent_registry_governable_unique');
                $table->index(['org_id', 'page_type'], 'intent_registry_org_page_type_idx');
                $table->index('primary_query', 'intent_registry_primary_query_idx');
                $table->index('resolution_strategy', 'intent_registry_resolution_strategy_idx');
            });
        }

        if (! Schema::hasTable('content_governance') || ! Schema::hasTable('intent_registry')) {
            return;
        }

        $now = now();
        $rows = DB::table('content_governance')
            ->select([
                'org_id',
                'governable_type',
                'governable_id',
                'page_type',
                'primary_query',
                'created_at',
                'updated_at',
            ])
            ->whereNotNull('primary_query')
            ->where('primary_query', '<>', '')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('intent_registry')
                ->where('governable_type', (string) $row->governable_type)
                ->where('governable_id', (int) $row->governable_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('intent_registry')->insert([
                'org_id' => max(0, (int) $row->org_id),
                'governable_type' => (string) $row->governable_type,
                'governable_id' => (int) $row->governable_id,
                'page_type' => trim((string) ($row->page_type ?: 'guide')),
                'primary_query' => trim((string) $row->primary_query),
                'canonical_governable_type' => (string) $row->governable_type,
                'canonical_governable_id' => (int) $row->governable_id,
                'resolution_strategy' => 'canonical',
                'exception_reason' => null,
                'latest_similarity_score' => null,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to preserve intent ownership history.
    }
};
