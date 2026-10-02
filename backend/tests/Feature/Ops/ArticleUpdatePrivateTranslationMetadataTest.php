<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\ArticleForkPrivateTranslationLinks;
use App\Console\Commands\ArticleUpdatePrivateTranslationMetadata as Metadata;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class ArticleUpdatePrivateTranslationMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_reads_and_apply_preserve_content_lineage_history_and_private_state(): void
    {
        [$s, $t, $p] = $this->fixture();
        $before = ArticleForkPrivateTranslationLinks::targetHash($s);
        $source = ArticleForkPrivateTranslationLinks::sourceHash($s);
        $history = ArticleSourceTargetSnapshot::sourceRevisions($t);
        $body = $t->content_md;
        $exit = $this->runCommand($p);
        $this->assertSame(0, $exit, Artisan::output());
        $exit = $this->runCommand($p);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($s));
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, $this->runCommand($p, true), Artisan::output());
        $t->refresh();
        $this->assertSame($body, $t->content_md);
        $this->assertSame($source, ArticleForkPrivateTranslationLinks::sourceHash($s->fresh()));
        $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($t));
        $this->assertFalse($t->is_public);
        $this->assertFalse($t->is_indexable);
        $this->assertNull($t->published_revision_id);
        $this->assertSame($p['category_id'], (int) $t->category_id);
        $this->assertSame($p['cover_image_alt'], $t->cover_image_alt);
        $this->assertSame('noindex,nofollow', $t->seoMeta->robots);
        $this->assertSame('https://fermatmind.com/en/articles/private-metadata', $t->seoMeta->canonical_url);
        $this->assertCount(5, $t->cover_image_variants);
        $this->assertSame(1, AuditLog::count());
        $audit = AuditLog::firstOrFail();
        $this->assertFalse($audit->meta_json['human_review_completed']);
        $this->assertNull($audit->meta_json['before_metadata']['article']['category_id']);
        $this->assertSame([], $audit->meta_json['before_metadata']['tag_map']);
        $this->assertSame(1, $this->runCommand($p, true)); // A stale package never reapplies.
        $after = ArticleForkPrivateTranslationLinks::targetHash($s);
        $this->assertSame(0, $this->runCommand($p, false, (int) $audit->id));
        $this->assertSame($after, ArticleForkPrivateTranslationLinks::targetHash($s));
        $this->assertSame(0, $this->runCommand($p, true, (int) $audit->id), Artisan::output());
        $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($s));
        $this->assertSame([], $t->fresh()->tags()->pluck('article_tags.id')->all());
        $this->assertSame(2, AuditLog::count());
        $this->assertSame(1, $this->runCommand($p, true, (int) $audit->id));
    }

    public function test_source_target_tag_media_and_taxonomy_drift_are_rejected(): void
    {
        [$s, $t, $p] = $this->fixture();
        $mutations = [
            fn () => $s->forceFill(['title' => 'Changed source'])->saveQuietly(),
            fn () => $t->forceFill(['excerpt' => 'Changed target'])->saveQuietly(),
            fn () => $t->tags()->sync([$p['tag_ids'][0] => ['org_id' => 0]]),
            fn () => MediaVariant::where('media_asset_id', $p['media_asset_id'])->where('variant_key', 'hero')->update(['width' => 1599]),
            fn () => ArticleCategory::whereKey($p['category_id'])->update(['name' => 'Different English category']),
        ];
        foreach ($mutations as $mutate) {
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                $mutate();
                $before = ArticleForkPrivateTranslationLinks::targetHash($s->fresh());
                $this->assertSame(1, $this->runCommand($p, true));
                $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($s->fresh()));
                $this->assertSame(0, AuditLog::count());
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }
    }

    public function test_queued_unreviewed_revision_metadata_update_preserves_revision_and_review_state(): void
    {
        [$s, $t, $p] = $this->fixture();
        $t->workingRevision->forceFill(['revision_status' => 'human_review'])->saveQuietly();
        $t->forceFill(['translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW])->saveQuietly();
        $p['target_snapshot_hash'] = ArticleForkPrivateTranslationLinks::targetHash($s->fresh());
        $history = ArticleSourceTargetSnapshot::sourceRevisions($t->fresh());
        $source = ArticleForkPrivateTranslationLinks::sourceHash($s);

        $this->assertSame(0, $this->runCommand($p), Artisan::output());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, $this->runCommand($p, true), Artisan::output());
        $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($t->fresh()));
        $this->assertSame($source, ArticleForkPrivateTranslationLinks::sourceHash($s->fresh()));
        $this->assertSame('human_review', $t->fresh()->workingRevision->revision_status);
        $this->assertSame(Article::TRANSLATION_STATUS_HUMAN_REVIEW, $t->fresh()->translation_status);
        $this->assertNull($t->fresh()->workingRevision->reviewed_at);
        $this->assertNull($t->fresh()->published_revision_id);
        $this->assertFalse(AuditLog::firstOrFail()->meta_json['human_review_completed']);
    }

    public function test_public_reviewed_cross_tenant_and_unverified_media_are_rejected_with_current_locks(): void
    {
        [$s, $t, $p] = $this->fixture();
        $mutations = [
            fn () => $t->forceFill(['is_public' => true])->saveQuietly(),
            fn () => $t->forceFill(['translation_status' => Article::TRANSLATION_STATUS_APPROVED])->saveQuietly(),
            fn () => $t->workingRevision->forceFill(['reviewed_at' => now()])->saveQuietly(),
            fn () => $t->workingRevision->forceFill(['revision_status' => 'approved'])->saveQuietly(),
            fn () => $t->workingRevision->forceFill(['revision_status' => 'published'])->saveQuietly(),
            fn () => $t->workingRevision->forceFill(['reviewed_by' => 1])->saveQuietly(),
            fn () => $t->workingRevision->forceFill(['approved_at' => now()])->saveQuietly(),
            fn () => MediaAsset::withoutGlobalScopes()->whereKey($p['media_asset_id'])->update(['org_id' => 9]),
            fn () => MediaVariant::where('media_asset_id', $p['media_asset_id'])->update(['cdn_status' => 'not_verified']),
            fn () => ArticleTag::whereKey($p['tag_ids'][0])->update(['name' => '中文标签']),
        ];
        foreach ($mutations as $mutate) {
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                $mutate();
                $q = $p;
                $q['target_snapshot_hash'] = ArticleForkPrivateTranslationLinks::targetHash($s->fresh());
                $this->assertSame(1, $this->runCommand($q, true));
                $this->assertSame(0, AuditLog::count());
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }
    }

    public function test_native_package_queued_revision_requires_exact_import_identity_and_body(): void
    {
        [$s, $t, $p] = $this->fixture();
        $t->workingRevision->forceFill(['revision_status' => 'human_review', 'authority_metadata_json' => null])->saveQuietly();
        $t->forceFill(['translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW])->saveQuietly();
        $p['target_snapshot_hash'] = ArticleForkPrivateTranslationLinks::targetHash($s->fresh());
        $this->assertSame(1, $this->runCommand($p, true));
        $import = ArticleEditorialPackageImport::create(['org_id' => 0, 'article_id' => $t->id,
            'locale' => 'en', 'slug' => $t->slug, 'title' => $t->title, 'content_track' => 'editorial',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED, 'body_hash' => hash('sha256', $t->workingRevision->content_md)]);
        foreach (['org_id' => 9, 'article_id' => $s->id, 'locale' => 'zh-CN', 'slug' => 'wrong-slug',
            'status' => ArticleEditorialPackageImport::STATUS_DRY_RUN_PASSED, 'body_hash' => str_repeat('b', 64)] as $field => $bad) {
            $original = $import->{$field};
            $import->forceFill([$field => $bad])->saveQuietly();
            $this->assertSame(1, $this->runCommand($p, true), $field);
            $this->assertSame(0, AuditLog::count());
            $import->forceFill([$field => $original])->saveQuietly();
        }
        $history = ArticleSourceTargetSnapshot::sourceRevisions($t->fresh());
        $this->assertSame(0, $this->runCommand($p), Artisan::output());
        $this->assertSame(0, $this->runCommand($p, true), Artisan::output());
        $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($t->fresh()));
        $this->assertNull($t->fresh()->published_revision_id);
        $this->assertNull($t->fresh()->workingRevision->reviewed_at);
        $this->assertFalse(AuditLog::firstOrFail()->meta_json['human_review_completed']);
    }

    public function test_audit_failure_rolls_back_all_metadata_and_tags(): void
    {
        [$s, $t, $p] = $this->fixture();
        $before = ArticleForkPrivateTranslationLinks::targetHash($s);
        $tags = ArticleSourceTargetSnapshot::tagsHash($t);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_unavailable'));
        $this->assertSame(1, $this->runCommand($p, true));
        $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($s));
        $this->assertSame($tags, ArticleSourceTargetSnapshot::tagsHash($t));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unknown_body_field_and_restoration_after_review_are_rejected(): void
    {
        [$s, $t, $p] = $this->fixture();
        $bad = [...$p, 'content_md' => 'Unauthorized content replacement'];
        $this->assertSame(1, $this->runCommand($bad, true));
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, $this->runCommand($p, true));
        $audit = AuditLog::firstOrFail();
        $t->workingRevision->forceFill(['reviewed_at' => now()])->saveQuietly();
        $before = ArticleForkPrivateTranslationLinks::targetHash($s);
        $this->assertSame(1, $this->runCommand($p, true, (int) $audit->id));
        $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($s));
        $this->assertSame(1, AuditLog::count());
    }

    private function fixture(): array
    {
        $s = Article::create(['org_id' => 0, 'slug' => 'private-metadata', 'locale' => 'zh-CN',
            'translation_group_id' => 'private-metadata', 'source_locale' => 'zh-CN', 'translation_status' => 'source',
            'title' => 'Source', 'content_md' => 'Source body', 'status' => 'published', 'is_public' => true]);
        $t = Article::create(['org_id' => 0, 'slug' => $s->slug, 'locale' => 'en', 'translation_group_id' => $s->translation_group_id,
            'source_locale' => 'zh-CN', 'source_article_id' => $s->id, 'translated_from_article_id' => $s->id,
            'translation_status' => 'machine_draft', 'title' => 'Target', 'content_md' => 'Unchanged English body',
            'status' => 'draft', 'is_public' => false, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false]);
        $r = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $t->id, 'source_article_id' => $s->id,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'machine_draft', 'title' => 'Target', 'content_md' => $t->content_md,
            'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64),
            'authority_metadata_json' => ['draft_origin' => 'operator_supplied_ai_draft', 'editorial_review_state' => 'pending']]);
        $t->forceFill(['working_revision_id' => $r->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $t->id, 'locale' => 'en', 'seo_title' => 'Existing title', 'is_indexable' => false]);
        $category = ArticleCategory::create(['org_id' => 0, 'name' => 'Personality Psychology', 'slug' => 'personality-psychology', 'is_active' => true]);
        $tag = ArticleTag::create(['org_id' => 0, 'name' => 'Big Five', 'slug' => 'big-five', 'is_active' => true]);
        $asset = MediaAsset::create(['org_id' => 0, 'asset_key' => 'metadata-cover', 'disk' => 'public', 'path' => 'cover.png', 'is_public' => true]);
        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $key) {
            MediaVariant::create(['media_asset_id' => $asset->id, 'variant_key' => $key,
                'url' => 'https://assets.fermatmind.com/storage/media-library/'.$key.'.jpg', 'width' => 1600, 'height' => 900,
                'sync_status' => 'synced', 'cdn_status' => 'verified', 'verified_at' => now()]);
        }
        $s->refresh();
        $t->refresh();
        $p = ['schema' => 'fermat_private_translation_metadata_v1', 'source_id' => (int) $s->id, 'target_id' => (int) $t->id,
            'working_revision_id' => (int) $r->id, 'source_snapshot_hash' => ArticleForkPrivateTranslationLinks::sourceHash($s),
            'target_snapshot_hash' => ArticleForkPrivateTranslationLinks::targetHash($s), 'target_tags_sha256' => ArticleSourceTargetSnapshot::tagsHash($t),
            'category_id' => (int) $category->id, 'tag_ids' => [(int) $tag->id], 'media_asset_id' => (int) $asset->id,
            'cover_image_alt' => 'Research evidence connected to individual observation and reflection.'];
        $p['dependencies_sha256'] = Metadata::digest(Metadata::dependencies($p));

        return [$s, $t, $p];
    }

    private function runCommand(array $p, bool $execute = false, int $restore = 0): int
    {
        $file = tempnam(sys_get_temp_dir(), 'private-metadata-');
        file_put_contents($file, json_encode($p, JSON_THROW_ON_ERROR));
        $sha = hash_file('sha256', $file);
        try {
            return Artisan::call('articles:update-private-translation-metadata', ['--file' => $file, '--sha256' => $sha,
                $execute ? '--execute' : '--dry-run' => true, ...($restore ? ['--restore-audit-id' => $restore] : []), '--confirm' => ($restore ? 'Restore' : 'Update').' private Article '.$p['target_id'].' metadata with package '.$sha.($restore ? ' and audit '.$restore : '').'.']);
        } finally {
            unlink($file);
        }
    }
}
