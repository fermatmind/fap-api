<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_quality_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('audit_type', 32);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('scope_key', 96)->nullable();
            $table->string('status', 16)->default('warning');
            $table->json('summary_json')->nullable();
            $table->json('findings_json')->nullable();
            $table->unsignedBigInteger('actor_admin_user_id')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'audit_type'], 'seo_quality_audits_org_type_idx');
            $table->index(['audit_type', 'scope_key'], 'seo_quality_audits_type_scope_idx');
            $table->index(['subject_type', 'subject_id'], 'seo_quality_audits_subject_idx');
            $table->index(['status', 'audited_at'], 'seo_quality_audits_status_audited_idx');
        });
    }

    public function down(): void
    {
        // Forward-only repository: rollback is intentionally a no-op.
    }
};
