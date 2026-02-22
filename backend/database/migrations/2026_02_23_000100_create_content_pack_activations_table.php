<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_pack_activations')) {
            return;
        }

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

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
