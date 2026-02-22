<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_pack_activations')) {
            Schema::create('content_pack_activations', function (Blueprint $table): void {
                $table->id();
                $table->string('pack_id', 128);
                $table->string('pack_version', 64);
                $table->uuid('release_id');
                $table->timestamp('activated_at');
                $table->timestamps();

                $table->unique(['pack_id', 'pack_version'], 'content_pack_activations_pack_ver_unique');
                $table->index(['release_id'], 'content_pack_activations_release_idx');
            });
        }

        Schema::table('content_pack_activations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_pack_activations', 'pack_id')) {
                $table->string('pack_id', 128)->nullable();
            }
            if (! Schema::hasColumn('content_pack_activations', 'pack_version')) {
                $table->string('pack_version', 64)->nullable();
            }
            if (! Schema::hasColumn('content_pack_activations', 'release_id')) {
                $table->uuid('release_id')->nullable();
            }
            if (! Schema::hasColumn('content_pack_activations', 'activated_at')) {
                $table->timestamp('activated_at')->nullable();
            }
            if (! Schema::hasColumn('content_pack_activations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('content_pack_activations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
