<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('seo_issue_queue', 'detector_id')) {
            $schema->table('seo_issue_queue', function (Blueprint $table): void {
                $table->string('detector_id', 80)->nullable()->after('issue_type');
                $table->string('detector_version', 32)->nullable()->after('detector_id');
                $table->string('cluster_uid', 64)->nullable()->after('cluster');
                $table->char('query_hash', 64)->nullable()->after('canonical_url_hash');
                $table->string('authority_revision', 160)->nullable()->after('cluster_uid');
                $table->string('url_truth_revision', 160)->nullable()->after('authority_revision');
                $table->string('policy_version', 160)->nullable()->after('url_truth_revision');
                $table->unsignedInteger('affected_url_count')->default(1)->after('policy_version');
                $table->char('artifact_hash', 64)->nullable()->after('evidence_hash');
                $table->timestamp('last_evidence_at')->nullable()->after('artifact_hash');
                $table->unsignedInteger('reopen_count')->default(0)->after('last_evidence_at');

                $table->index('detector_id', 'seo_issue_queue_detector_idx');
                $table->index('cluster_uid', 'seo_issue_queue_detector_cluster_idx');
                $table->index('query_hash', 'seo_issue_queue_query_hash_idx');
                $table->index('authority_revision', 'seo_issue_queue_authority_revision_idx');
            });
        }

        if ($schema->hasTable('seo_detector_opportunities')) {
            return;
        }

        $schema->create('seo_detector_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_uid', 128)->unique();
            $table->string('detector_id', 80);
            $table->string('detector_version', 32);
            $table->string('cluster_uid', 64);
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_family', 80);
            $table->string('authority_revision', 160);
            $table->string('url_truth_revision', 160);
            $table->string('policy_version', 160);
            $table->string('status', 32)->default('open');
            $table->string('lifecycle_state', 32)->default('open');
            $table->unsignedInteger('affected_url_count')->default(1);
            $table->char('evidence_hash', 64);
            $table->char('artifact_hash', 64);
            $table->json('metadata_json')->nullable();
            $table->dateTime('detected_at');
            $table->dateTime('last_evidence_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();

            $table->index('detector_id', 'seo_detector_opportunity_detector_idx');
            $table->index('cluster_uid', 'seo_detector_opportunity_cluster_idx');
            $table->index('canonical_url_hash', 'seo_detector_opportunity_canonical_idx');
            $table->index('query_hash', 'seo_detector_opportunity_query_idx');
            $table->index('status', 'seo_detector_opportunity_status_idx');
            $table->index('authority_revision', 'seo_detector_opportunity_authority_idx');
        });
    }

    public function down(): void
    {
        // Forward-only expand migration. Existing queue readers remain compatible
        // because all legacy columns and semantics are preserved.
    }
};
