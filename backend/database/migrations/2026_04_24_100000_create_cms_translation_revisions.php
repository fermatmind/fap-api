<?php

declare(strict_types=1);

use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_translation_revisions')) {
            Schema::create('cms_translation_revisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('content_type', 64);
                $table->unsignedBigInteger('content_id');
                $table->unsignedBigInteger('source_content_id')->nullable();
                $table->string('translation_group_id', 128);
                $table->string('locale', 16);
                $table->string('source_locale', 16);
                $table->unsignedInteger('revision_number');
                $table->string('revision_status', 32);
                $table->string('source_version_hash', 128)->nullable();
                $table->string('translated_from_version_hash', 128)->nullable();
                $table->json('payload_json');
                $table->unsignedBigInteger('supersedes_revision_id')->nullable();
                $table->unsignedBigInteger('created_by_admin_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index(['content_type', 'content_id']);
                $table->index(['translation_group_id', 'locale']);
                $table->index(['content_type', 'revision_status']);
                $table->unique(['content_type', 'content_id', 'revision_number'], 'cms_translation_revisions_content_revision_unique');
            });
        }

        $this->addRevisionPointers('support_articles');
        $this->addRevisionPointers('interpretation_guides');
        $this->addRevisionPointers('content_pages');

        $this->backfillTable(
            'support_article',
            'support_articles',
            [
                'title',
                'summary',
                'body_md',
                'body_html',
                'support_category',
                'support_intent',
                'primary_cta_label',
                'primary_cta_url',
                'related_support_article_ids',
                'related_content_page_ids',
                'seo_title',
                'seo_description',
                'canonical_path',
            ]
        );
        $this->backfillTable(
            'interpretation_guide',
            'interpretation_guides',
            [
                'title',
                'summary',
                'body_md',
                'body_html',
                'test_family',
                'result_context',
                'audience',
                'related_guide_ids',
                'related_methodology_page_ids',
                'seo_title',
                'seo_description',
                'canonical_path',
            ]
        );
        $this->backfillTable(
            'content_page',
            'content_pages',
            [
                'path',
                'kind',
                'page_type',
                'title',
                'kicker',
                'summary',
                'template',
                'animation_profile',
                'owner',
                'legal_review_required',
                'science_review_required',
                'source_doc',
                'headings_json',
                'content_md',
                'content_html',
                'seo_title',
                'meta_description',
                'seo_description',
                'canonical_path',
                'is_public',
                'is_indexable',
            ]
        );
    }

    public function down(): void
    {
        foreach (['support_articles', 'interpretation_guides', 'content_pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'working_revision_id')) {
                    $table->dropColumn('working_revision_id');
                }
                if (Schema::hasColumn($tableName, 'published_revision_id')) {
                    $table->dropColumn('published_revision_id');
                }
            });
        }

        Schema::dropIfExists('cms_translation_revisions');
    }

    private function addRevisionPointers(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasColumn($tableName, 'working_revision_id')) {
                $table->unsignedBigInteger('working_revision_id')->nullable()->after('translated_from_version_hash');
            }
            if (! Schema::hasColumn($tableName, 'published_revision_id')) {
                $table->unsignedBigInteger('published_revision_id')->nullable()->after('working_revision_id');
            }
        });
    }

    /**
     * @param  list<string>  $payloadFields
     */
    private function backfillTable(string $contentType, string $tableName, array $payloadFields): void
    {
        DB::table($tableName)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($contentType, $tableName, $payloadFields): void {
                foreach ($rows as $row) {
                    $existingWorking = (int) ($row->working_revision_id ?? 0);
                    $existingPublished = (int) ($row->published_revision_id ?? 0);
                    if ($existingWorking > 0 || $existingPublished > 0) {
                        continue;
                    }

                    $status = (string) ($row->translation_status ?? '');
                    $reviewState = (string) ($row->review_state ?? '');
                    $recordStatus = (string) ($row->status ?? '');
                    $isPublic = (bool) ($row->is_public ?? false);

                    $revisionStatus = match (true) {
                        $status === 'source' => 'source',
                        $status === 'machine_draft' => 'machine_draft',
                        $status === 'human_review' => 'human_review',
                        $status === 'approved' => 'approved',
                        $status === 'stale' => 'stale',
                        $status === 'archived' => 'archived',
                        $status === 'published' => 'published',
                        $recordStatus === 'published' && ($reviewState === 'approved' || $isPublic) => 'published',
                        default => 'draft',
                    };

                    $payload = [];
                    foreach ($payloadFields as $field) {
                        $payload[$field] = $row->{$field} ?? null;
                    }

                    $now = now();
                    $revisionId = DB::table('cms_translation_revisions')->insertGetId([
                        'org_id' => (int) ($row->org_id ?? 0),
                        'content_type' => $contentType,
                        'content_id' => (int) $row->id,
                        'source_content_id' => $row->source_content_id ? (int) $row->source_content_id : null,
                        'translation_group_id' => (string) ($row->translation_group_id ?? ''),
                        'locale' => (string) ($row->locale ?? ''),
                        'source_locale' => (string) ($row->source_locale ?? $row->locale ?? ''),
                        'revision_number' => 1,
                        'revision_status' => $revisionStatus,
                        'source_version_hash' => $row->source_version_hash ? (string) $row->source_version_hash : null,
                        'translated_from_version_hash' => $row->translated_from_version_hash ? (string) $row->translated_from_version_hash : null,
                        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'supersedes_revision_id' => null,
                        'created_by_admin_id' => null,
                        'reviewed_at' => null,
                        'approved_at' => $revisionStatus === 'published' || $revisionStatus === 'approved' ? ($row->published_at ?? $row->updated_at ?? $now) : null,
                        'archived_at' => $revisionStatus === 'archived' ? ($row->updated_at ?? $now) : null,
                        'published_at' => $revisionStatus === 'published' ? ($row->published_at ?? $row->updated_at ?? $now) : null,
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $row->updated_at ?? $now,
                    ]);

                    DB::table($tableName)
                        ->where('id', (int) $row->id)
                        ->update([
                            'working_revision_id' => $revisionId,
                            'published_revision_id' => $revisionStatus === 'published' ? $revisionId : null,
                        ]);
                }
            });
    }
};
