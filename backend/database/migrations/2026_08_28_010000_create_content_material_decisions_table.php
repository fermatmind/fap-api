<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_material_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('family', 32);
            $table->string('locale', 16);
            $table->string('authority_subject_key', 64);
            $table->string('public_identity', 191);
            $table->string('previous_public_identity', 191)->nullable();
            $table->string('authority_revision_kind', 64);
            $table->string('authority_revision', 128);
            $table->char('material_fingerprint', 64)->nullable();
            $table->char('previous_material_fingerprint', 64)->nullable();
            $table->string('publication_state', 32);
            $table->string('operation', 32);
            $table->string('decision_code', 64);
            $table->boolean('material_changed')->default(false);
            $table->timestamp('material_changed_at')->nullable();
            $table->string('evidence_ref', 191);
            $table->char('decision_key', 64)->unique();
            $table->timestamps();

            $table->index(
                ['org_id', 'family', 'authority_subject_key', 'locale', 'id'],
                'content_material_decisions_current_idx',
            );
            $table->index(
                ['family', 'publication_state', 'material_changed_at'],
                'content_material_decisions_state_idx',
            );
        });
    }

    public function down(): void
    {
        // Forward-only expand migration. Older releases ignore this append-only table.
    }
};
