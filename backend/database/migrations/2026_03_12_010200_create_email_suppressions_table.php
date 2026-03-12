<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_suppressions')) {
            return;
        }

        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email_hash', 64)->index();
            $table->string('reason', 64);
            $table->string('source', 128)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
