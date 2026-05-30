<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_giving_records', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('record_code')->unique();
            $table->date('donation_date');
            $table->string('recipient_name');
            $table->string('recipient_official_url')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('donation_status')->default('planned');
            $table->string('proof_status')->default('none');
            $table->string('proof_public_url')->nullable();
            $table->string('proof_private_path')->nullable();
            $table->text('proof_redaction_notes')->nullable();
            $table->string('receipt_reference_redacted')->nullable();
            $table->string('receipt_reference_private')->nullable();
            $table->string('social_x_url')->nullable();
            $table->string('social_linkedin_url')->nullable();
            $table->string('social_weibo_url')->nullable();
            $table->string('social_xiaohongshu_url')->nullable();
            $table->json('social_other_links')->nullable();
            $table->text('public_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_indexable')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally no-op to satisfy production-safe migration policy.
    }
};
