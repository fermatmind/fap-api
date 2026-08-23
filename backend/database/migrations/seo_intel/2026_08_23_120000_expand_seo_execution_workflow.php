<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        // Status stays a string for rollback compatibility. Runtime maps legacy
        // new/assigned/fixed/verified values onto the five-state workflow; no
        // destructive status rewrite or enum narrowing occurs during expand.
        Schema::connection($this->getConnection())->table('seo_issue_queue', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_admin_user_id')->nullable()->after('lifecycle_state');
            $table->timestamp('sla_due_at')->nullable()->after('owner_admin_user_id');
            $table->text('operator_note')->nullable()->after('sla_due_at');
            $table->text('ignore_reason')->nullable()->after('ignored_at');
            $table->timestamp('ignore_until')->nullable()->after('ignore_reason');
            $table->timestamp('verified_at')->nullable()->after('ignore_until');
            $table->unsignedBigInteger('verified_by_admin_user_id')->nullable()->after('verified_at');
            $table->text('verification_note')->nullable()->after('verified_by_admin_user_id');
            $table->unsignedBigInteger('lock_version')->default(0)->after('verification_note');

            $table->index('owner_admin_user_id', 'seo_issue_queue_owner_admin_idx');
            $table->index('sla_due_at', 'seo_issue_queue_sla_due_idx');
            $table->index('ignore_until', 'seo_issue_queue_ignore_until_idx');
        });
    }

    public function down(): void
    {
        // Forward-only expand migration. Contract cleanup requires a later compatibility window.
    }
};
