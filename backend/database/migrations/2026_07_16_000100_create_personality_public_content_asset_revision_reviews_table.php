<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personality_public_content_asset_revision_reviews')) {
            $this->convergeExistingTable();

            return;
        }

        Schema::create('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('authority_asset_key', 191);
            $table->string('source_package', 191);
            $table->char('asset_sha256', 64);
            $table->char('authority_package_sha256', 64);
            $table->char('review_register_sha256', 64);
            $table->string('reviewer_name', 255);
            $table->timestamp('reviewed_at');
            $table->string('decision', 32);
            $table->string('review_source', 64);
            $table->char('evidence_sha256', 64);
            $table->unsignedBigInteger('bound_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique('revision_id', 'personality_asset_revision_reviews_revision_unique');
            $table->unique(
                ['authority_package_sha256', 'authority_asset_key'],
                'personality_asset_revision_reviews_package_asset_unique'
            );
            $table->index(['asset_id', 'decision'], 'personality_asset_revision_reviews_asset_decision_idx');
            $table->foreign('revision_id', 'personality_asset_revision_reviews_revision_fk')
                ->references('id')
                ->on('personality_public_content_asset_revisions')
                ->cascadeOnDelete();
            $table->foreign('asset_id', 'personality_asset_revision_reviews_asset_fk')
                ->references('id')
                ->on('personality_public_content_assets')
                ->cascadeOnDelete();
        });
    }

    private function convergeExistingTable(): void
    {
        Schema::table('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'revision_id')) {
                $table->unsignedBigInteger('revision_id');
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'asset_id')) {
                $table->unsignedBigInteger('asset_id');
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'authority_asset_key')) {
                $table->string('authority_asset_key', 191);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'source_package')) {
                $table->string('source_package', 191);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'asset_sha256')) {
                $table->char('asset_sha256', 64);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'authority_package_sha256')) {
                $table->char('authority_package_sha256', 64);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'review_register_sha256')) {
                $table->char('review_register_sha256', 64);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'reviewer_name')) {
                $table->string('reviewer_name', 255);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'reviewed_at')) {
                $table->timestamp('reviewed_at');
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'decision')) {
                $table->string('decision', 32);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'review_source')) {
                $table->string('review_source', 64);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'evidence_sha256')) {
                $table->char('evidence_sha256', 64);
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'bound_by_admin_user_id')) {
                $table->unsignedBigInteger('bound_by_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('personality_public_content_asset_revision_reviews', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (! Schema::hasIndex('personality_public_content_asset_revision_reviews', 'personality_asset_revision_reviews_revision_unique')) {
            Schema::table('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
                $table->unique('revision_id', 'personality_asset_revision_reviews_revision_unique');
            });
        }
        if (! Schema::hasIndex('personality_public_content_asset_revision_reviews', 'personality_asset_revision_reviews_package_asset_unique')) {
            Schema::table('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
                $table->unique(
                    ['authority_package_sha256', 'authority_asset_key'],
                    'personality_asset_revision_reviews_package_asset_unique'
                );
            });
        }
        if (! Schema::hasIndex('personality_public_content_asset_revision_reviews', 'personality_asset_revision_reviews_asset_decision_idx')) {
            Schema::table('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
                $table->index(['asset_id', 'decision'], 'personality_asset_revision_reviews_asset_decision_idx');
            });
        }
    }

    public function down(): void
    {
        // Forward-only private review evidence. Removal requires a separately reviewed migration.
    }
};
