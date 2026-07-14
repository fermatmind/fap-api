<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addArticleAuthorityRevisionFields();
        $this->addContentPageAuthorityRevisionFields();
        $this->createPersonalityAssetRevisions();
        $this->addPersonalityRevisionPointers();
        $this->addTopicAuthorityRevisionFields();
        $this->addTopicRevisionPointers();
    }

    public function down(): void
    {
        // Forward-only authority migration. Draft lineage and public-record pointers must be
        // removed only through a separately reviewed forward migration.
    }

    private function addArticleAuthorityRevisionFields(): void
    {
        if (! Schema::hasTable('article_translation_revisions')) {
            return;
        }

        Schema::table('article_translation_revisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('article_translation_revisions', 'authority_asset_key')) {
                $table->string('authority_asset_key', 191)->nullable()->after('supersedes_revision_id');
            }
            if (! Schema::hasColumn('article_translation_revisions', 'authority_source_package')) {
                $table->string('authority_source_package', 191)->nullable()->after('authority_asset_key');
            }
            if (! Schema::hasColumn('article_translation_revisions', 'authority_source_hash')) {
                $table->char('authority_source_hash', 64)->nullable()->after('authority_source_package');
            }
            if (! Schema::hasColumn('article_translation_revisions', 'authority_package_sha256')) {
                $table->char('authority_package_sha256', 64)->nullable()->after('authority_source_hash');
            }
            if (! Schema::hasColumn('article_translation_revisions', 'authority_metadata_json')) {
                $table->json('authority_metadata_json')->nullable()->after('authority_package_sha256');
            }
        });

        if (! Schema::hasIndex('article_translation_revisions', 'article_translation_revisions_authority_asset_unique')) {
            Schema::table('article_translation_revisions', function (Blueprint $table): void {
                $table->unique(
                    ['authority_package_sha256', 'authority_asset_key'],
                    'article_translation_revisions_authority_asset_unique'
                );
            });
        }
    }

    private function addContentPageAuthorityRevisionFields(): void
    {
        if (! Schema::hasTable('cms_translation_revisions')) {
            return;
        }

        Schema::table('cms_translation_revisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cms_translation_revisions', 'authority_asset_key')) {
                $table->string('authority_asset_key', 191)->nullable()->after('supersedes_revision_id');
            }
            if (! Schema::hasColumn('cms_translation_revisions', 'authority_source_package')) {
                $table->string('authority_source_package', 191)->nullable()->after('authority_asset_key');
            }
            if (! Schema::hasColumn('cms_translation_revisions', 'authority_source_hash')) {
                $table->char('authority_source_hash', 64)->nullable()->after('authority_source_package');
            }
            if (! Schema::hasColumn('cms_translation_revisions', 'authority_package_sha256')) {
                $table->char('authority_package_sha256', 64)->nullable()->after('authority_source_hash');
            }
        });

        if (! Schema::hasIndex('cms_translation_revisions', 'cms_translation_revisions_authority_asset_unique')) {
            Schema::table('cms_translation_revisions', function (Blueprint $table): void {
                $table->unique(
                    ['authority_package_sha256', 'authority_asset_key'],
                    'cms_translation_revisions_authority_asset_unique'
                );
            });
        }
    }

    private function createPersonalityAssetRevisions(): void
    {
        if (Schema::hasTable('personality_public_content_asset_revisions')) {
            return;
        }

        Schema::create('personality_public_content_asset_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('revision_no');
            $table->string('authority_asset_key', 191);
            $table->string('source_package', 191);
            $table->char('source_hash', 64);
            $table->char('authority_package_sha256', 64);
            $table->string('workflow_state', 32)->default('draft');
            $table->json('snapshot_json');
            $table->char('public_runtime_fingerprint_before', 64);
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'revision_no'], 'personality_asset_revisions_asset_no_unique');
            $table->unique(
                ['authority_package_sha256', 'authority_asset_key'],
                'personality_asset_revisions_authority_asset_unique'
            );
            $table->index(['asset_id', 'workflow_state'], 'personality_asset_revisions_state_idx');
            $table->foreign('asset_id', 'personality_asset_revisions_asset_fk')
                ->references('id')
                ->on('personality_public_content_assets')
                ->cascadeOnDelete();
        });
    }

    private function addPersonalityRevisionPointers(): void
    {
        if (! Schema::hasTable('personality_public_content_assets')) {
            return;
        }

        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('personality_public_content_assets', 'working_revision_id')) {
                $table->unsignedBigInteger('working_revision_id')->nullable()->after('source_hash');
            }
            if (! Schema::hasColumn('personality_public_content_assets', 'published_revision_id')) {
                $table->unsignedBigInteger('published_revision_id')->nullable()->after('working_revision_id');
            }
        });

        if (! Schema::hasIndex('personality_public_content_assets', 'personality_public_assets_working_revision_idx')) {
            Schema::table('personality_public_content_assets', function (Blueprint $table): void {
                $table->index('working_revision_id', 'personality_public_assets_working_revision_idx');
            });
        }
        if (! Schema::hasIndex('personality_public_content_assets', 'personality_public_assets_published_revision_idx')) {
            Schema::table('personality_public_content_assets', function (Blueprint $table): void {
                $table->index('published_revision_id', 'personality_public_assets_published_revision_idx');
            });
        }
    }

    private function addTopicAuthorityRevisionFields(): void
    {
        if (! Schema::hasTable('topic_profile_revisions')) {
            return;
        }

        Schema::table('topic_profile_revisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('topic_profile_revisions', 'authority_asset_key')) {
                $table->string('authority_asset_key', 191)->nullable()->after('revision_no');
            }
            if (! Schema::hasColumn('topic_profile_revisions', 'source_package')) {
                $table->string('source_package', 191)->nullable()->after('authority_asset_key');
            }
            if (! Schema::hasColumn('topic_profile_revisions', 'source_hash')) {
                $table->char('source_hash', 64)->nullable()->after('source_package');
            }
            if (! Schema::hasColumn('topic_profile_revisions', 'authority_package_sha256')) {
                $table->char('authority_package_sha256', 64)->nullable()->after('source_hash');
            }
            if (! Schema::hasColumn('topic_profile_revisions', 'workflow_state')) {
                $table->string('workflow_state', 32)->nullable()->after('authority_package_sha256');
            }
            if (! Schema::hasColumn('topic_profile_revisions', 'public_runtime_fingerprint_before')) {
                $table->char('public_runtime_fingerprint_before', 64)->nullable()->after('snapshot_json');
            }
        });

        if (! Schema::hasIndex('topic_profile_revisions', 'topic_profile_revisions_authority_asset_unique')) {
            Schema::table('topic_profile_revisions', function (Blueprint $table): void {
                $table->unique(
                    ['authority_package_sha256', 'authority_asset_key'],
                    'topic_profile_revisions_authority_asset_unique'
                );
            });
        }
    }

    private function addTopicRevisionPointers(): void
    {
        if (! Schema::hasTable('topic_profiles')) {
            return;
        }

        Schema::table('topic_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('topic_profiles', 'working_revision_id')) {
                $table->unsignedBigInteger('working_revision_id')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('topic_profiles', 'published_revision_id')) {
                $table->unsignedBigInteger('published_revision_id')->nullable()->after('working_revision_id');
            }
        });

        if (! Schema::hasIndex('topic_profiles', 'topic_profiles_working_revision_idx')) {
            Schema::table('topic_profiles', function (Blueprint $table): void {
                $table->index('working_revision_id', 'topic_profiles_working_revision_idx');
            });
        }
        if (! Schema::hasIndex('topic_profiles', 'topic_profiles_published_revision_idx')) {
            Schema::table('topic_profiles', function (Blueprint $table): void {
                $table->index('published_revision_id', 'topic_profiles_published_revision_idx');
            });
        }
    }
};
