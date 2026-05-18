<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_internal_traffic_rules')) {
            return;
        }

        Schema::create('seo_internal_traffic_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 128)->unique();
            $table->string('rule_type', 64);
            $table->string('match_kind', 64);
            $table->char('pattern_hash', 64)->nullable();
            $table->string('pattern_display_masked', 255)->nullable();
            $table->string('environment', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('reason', 255)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
