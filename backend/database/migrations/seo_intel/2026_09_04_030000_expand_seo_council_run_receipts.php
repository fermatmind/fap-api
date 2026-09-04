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
        if ($schema->hasTable('seo_council_run_receipts')) {
            return;
        }

        $schema->create('seo_council_run_receipts', function (Blueprint $table): void {
            $table->id();
            $table->char('receipt_id', 64)->unique();
            $table->char('receipt_hash', 64)->unique();
            $table->char('run_id', 64);
            $table->unsignedInteger('receipt_version');
            $table->char('request_hash', 64);
            $table->char('catalog_hash', 64);
            $table->char('policy_hash', 64);
            $table->char('binding_hash', 64);
            $table->char('evidence_hash', 64);
            $table->char('capability_hash', 64);
            $table->char('supersedes_receipt_hash', 64)->nullable();
            $table->json('receipt_json');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['run_id', 'receipt_version'], 'seo_council_receipt_run_version_uq');
            $table->index(['request_hash', 'created_at'], 'seo_council_receipt_request_time_idx');
        });
    }

    public function down(): void
    {
        // Expand-only: immutable Council receipts remain available for replay and resume validation.
    }
};
