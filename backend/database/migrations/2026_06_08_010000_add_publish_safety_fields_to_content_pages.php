<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_pages')) {
            return;
        }

        Schema::table('content_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_pages', 'publish_allowed')) {
                $table->boolean('publish_allowed')->default(false)->after('schema_enabled');
            }
            if (! Schema::hasColumn('content_pages', 'operator_approval_required')) {
                $table->boolean('operator_approval_required')->default(true)->after('publish_allowed');
            }
            if (! Schema::hasColumn('content_pages', 'operator_approved_at')) {
                $table->timestamp('operator_approved_at')->nullable()->after('operator_approval_required');
            }
            if (! Schema::hasColumn('content_pages', 'claim_gate_status')) {
                $table->string('claim_gate_status', 32)->default('not_reviewed')->after('operator_approved_at');
            }
            if (! Schema::hasColumn('content_pages', 'forbidden_claims')) {
                $table->json('forbidden_claims')->nullable()->after('claim_gate_status');
            }
            if (! Schema::hasColumn('content_pages', 'faq_schema_eligible')) {
                $table->boolean('faq_schema_eligible')->default(false)->after('forbidden_claims');
            }
            if (! Schema::hasColumn('content_pages', 'schema_eligibility_reviewed_at')) {
                $table->timestamp('schema_eligibility_reviewed_at')->nullable()->after('faq_schema_eligible');
            }
        });
    }

    public function down(): void
    {
        // Forward-only migration: publish safety field rollback is intentionally disabled.
    }
};
