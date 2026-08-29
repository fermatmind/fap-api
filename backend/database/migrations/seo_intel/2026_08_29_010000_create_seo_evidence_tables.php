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
        if (! $schema->hasTable('seo_evidence_bundles')) {
            $schema->create('seo_evidence_bundles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('bundle_id', 128);
                $table->unsignedInteger('bundle_version');
                $table->char('bundle_hash', 64);
                $table->string('mission_id', 128);
                $table->string('page_family', 64);
                $table->string('locale', 16);
                $table->string('source_type', 64);
                $table->timestamp('expires_at');
                $table->json('bundle_json');
                $table->timestamp('created_at');
                $table->unique(['bundle_id', 'bundle_version'], 'seo_evidence_bundle_version_unique');
                $table->index(['mission_id', 'page_family', 'locale'], 'seo_evidence_mission_family_locale_idx');
                $table->index(['source_type', 'expires_at'], 'seo_evidence_source_expiry_idx');
            });
        }
        if (! $schema->hasTable('seo_evidence_deletion_receipts')) {
            $schema->create('seo_evidence_deletion_receipts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('schema_version', 64);
                $table->string('bundle_id', 128);
                $table->unsignedInteger('bundle_version');
                $table->char('bundle_hash', 64);
                $table->string('policy_version', 32);
                $table->char('policy_hash', 64);
                $table->timestamp('expired_at');
                $table->timestamp('deleted_at');
                $table->string('reason', 64);
                $table->char('receipt_hash', 64);
                $table->timestamp('created_at');
                $table->unique(['bundle_id', 'bundle_version', 'bundle_hash'], 'seo_evidence_deletion_unique');
                $table->unique('receipt_hash', 'seo_evidence_deletion_receipt_hash_unique');
            });
        }
    }

    public function down(): void
    {
        // Forward-only expand migration. Deletion is retention-policy controlled.
    }
};
