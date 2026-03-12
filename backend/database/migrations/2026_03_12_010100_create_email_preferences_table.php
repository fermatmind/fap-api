<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_preferences')) {
            return;
        }

        Schema::create('email_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('subscriber_id')->unique();
            $table->boolean('marketing_updates')->default(false);
            $table->boolean('report_recovery')->default(true);
            $table->boolean('product_updates')->default(false);
            $table->timestamps();

            $table->foreign('subscriber_id')
                ->references('id')
                ->on('email_subscribers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
