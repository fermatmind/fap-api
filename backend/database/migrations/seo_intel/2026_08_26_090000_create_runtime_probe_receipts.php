<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_runtime_probe_receipts')) {
            return;
        }

        $schema->create('seo_runtime_probe_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('slot_key', 80)->unique();
            $table->string('trigger_mode', 32)->index();
            $table->string('status', 32)->index();
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('completed_at')->index();
            $table->string('receipt_hash', 64);
            $table->json('crawler_source_receipt_json');
            $table->json('receipt_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Expand-only: natural scheduler evidence must remain available.
    }
};
