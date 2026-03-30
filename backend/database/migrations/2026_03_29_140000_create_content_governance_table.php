<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_governance')) {
            Schema::create('content_governance', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('governable_type', 160);
                $table->unsignedBigInteger('governable_id');
                $table->string('page_type', 32);
                $table->string('primary_query', 255)->nullable();
                $table->string('canonical_target', 255)->nullable();
                $table->string('hub_ref', 255)->nullable();
                $table->string('test_binding', 255)->nullable();
                $table->string('method_binding', 255)->nullable();
                $table->unsignedBigInteger('author_admin_user_id')->nullable();
                $table->unsignedBigInteger('reviewer_admin_user_id')->nullable();
                $table->string('publish_gate_state', 32)->default('draft');
                $table->timestamps();

                $table->unique(['governable_type', 'governable_id'], 'content_governance_governable_unique');
                $table->index(['org_id', 'page_type'], 'content_governance_org_page_type_idx');
                $table->index('primary_query', 'content_governance_primary_query_idx');
                $table->index('publish_gate_state', 'content_governance_publish_gate_state_idx');
            });

            return;
        }

        Schema::table('content_governance', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_governance', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0)->after('id');
            }
            if (! Schema::hasColumn('content_governance', 'governable_type')) {
                $table->string('governable_type', 160)->after('org_id');
            }
            if (! Schema::hasColumn('content_governance', 'governable_id')) {
                $table->unsignedBigInteger('governable_id')->after('governable_type');
            }
            if (! Schema::hasColumn('content_governance', 'page_type')) {
                $table->string('page_type', 32)->default('guide')->after('governable_id');
            }
            if (! Schema::hasColumn('content_governance', 'primary_query')) {
                $table->string('primary_query', 255)->nullable()->after('page_type');
            }
            if (! Schema::hasColumn('content_governance', 'canonical_target')) {
                $table->string('canonical_target', 255)->nullable()->after('primary_query');
            }
            if (! Schema::hasColumn('content_governance', 'hub_ref')) {
                $table->string('hub_ref', 255)->nullable()->after('canonical_target');
            }
            if (! Schema::hasColumn('content_governance', 'test_binding')) {
                $table->string('test_binding', 255)->nullable()->after('hub_ref');
            }
            if (! Schema::hasColumn('content_governance', 'method_binding')) {
                $table->string('method_binding', 255)->nullable()->after('test_binding');
            }
            if (! Schema::hasColumn('content_governance', 'author_admin_user_id')) {
                $table->unsignedBigInteger('author_admin_user_id')->nullable()->after('method_binding');
            }
            if (! Schema::hasColumn('content_governance', 'reviewer_admin_user_id')) {
                $table->unsignedBigInteger('reviewer_admin_user_id')->nullable()->after('author_admin_user_id');
            }
            if (! Schema::hasColumn('content_governance', 'publish_gate_state')) {
                $table->string('publish_gate_state', 32)->default('draft')->after('reviewer_admin_user_id');
            }
            if (! Schema::hasColumn('content_governance', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('publish_gate_state');
            }
            if (! Schema::hasColumn('content_governance', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to preserve governance state history.
    }
};
