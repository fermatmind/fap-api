<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleForkPrivateTranslationLinks as F;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\EditorialReview;
use App\Services\Cms\CmsEditorialReviewAttestationService as Reviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReviewedTranslationPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_dry_run_and_publish_without_fabricated_seo_faq(): void
    {
        [$s, $t, $p] = $this->fixture();
        $source = F::sourceHash($s);
        $target = F::targetHash($s);
        $this->assertSame(0, $this->runPublish($p), Artisan::output());
        $this->assertSame(0, $this->runPublish($p), Artisan::output());
        $this->assertSame($target, F::targetHash($s));
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, $this->runPublish($p, true), Artisan::output());
        $this->assertTrue($t->fresh()->is_public);
        $this->assertSame($p['working_revision_id'], $t->fresh()->published_revision_id);
        $this->assertSame($source, F::sourceHash($s->fresh()));
        $this->assertFalse($t->fresh()->is_indexable);
        $audit = AuditLog::where('action', 'codex_controlled_translation_publish')->sole();
        $this->assertNull($audit->meta_json['restore_fields']['article']['published_revision_id']);
        $this->assertSame(1, $this->runPublish($p, true));
    }

    public function test_conflicts_missing_review_and_missing_preview_are_zero_write(): void
    {
        [$s, $t, $p] = $this->fixture();
        $before = F::targetHash($s);
        $this->assertSame(1, $this->runPublish($p, true, ['--preview-approved' => false]));
        $this->assertSame(1, $this->runPublish($p, true, ['--confirm' => 'wrong']));
        $this->assertSame(1, $this->runPublish([...$p, 'body_sha256' => str_repeat('a', 64)], true));
        $this->assertSame($before, F::targetHash($s));
        $t->workingRevision->forceFill(['excerpt' => 'Unreviewed edit'])->saveQuietly();
        $p['target_snapshot_hash'] = F::targetHash($s);
        $this->assertSame(1, $this->runPublish($p, true));
        $this->assertSame($p['target_snapshot_hash'], F::targetHash($s));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_indexability_release_and_package_digest_locks(): void
    {
        [$s, $t, $p] = $this->fixture();
        $before = F::targetHash($s);
        $this->assertSame(1, $this->runPublish($p, true, ['--translation-sha256' => str_repeat('a', 64)]));
        $this->assertSame(1, $this->runPublish([...$p, 'unexpected' => true], true));
        $this->assertSame(1, $this->runPublish($p, true, ['--article' => [$s->id]]));
        $this->assertSame($before, F::targetHash($s));
        $this->assertSame(0, $this->runPublish($p, true, ['--make-indexable' => true]), Artisan::output());
        $this->assertTrue($t->fresh()->is_indexable);
        $this->assertSame('index,follow', $t->seoMeta->fresh()->robots);
    }

    public function test_audit_failure_rolls_back_publication_and_indexability(): void
    {
        [$s, $t, $p] = $this->fixture();
        $before = F::targetHash($s);
        $sourceBefore = F::sourceHash($s);
        $this->mock(\App\Services\Audit\AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('log')->andThrow(new \RuntimeException('audit_unavailable'));
        });
        $this->assertSame(1, $this->runPublish($p, true, ['--make-indexable' => true]));
        $this->assertSame($before, F::targetHash($s->fresh()));
        $this->assertSame($sourceBefore, F::sourceHash($s->fresh()));
        $this->assertFalse($t->fresh()->is_public);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_stale_lineage_and_source_snapshot_drift_reject_without_writes(): void
    {
        [$s, $t, $p] = $this->fixture();
        $t->workingRevision->forceFill(['translated_from_version_hash' => str_repeat('a', 64)])->saveQuietly();
        $p['target_snapshot_hash'] = F::targetHash($s);
        $this->assertSame(1, $this->runPublish($p, true));
        $this->assertSame($p['target_snapshot_hash'], F::targetHash($s));
        $s->forceFill(['title' => 'Source changed'])->saveQuietly();
        $sourceBefore = F::sourceHash($s->fresh());
        $this->assertSame(1, $this->runPublish($p, true));
        $this->assertSame($sourceBefore, F::sourceHash($s->fresh()));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_article_projection_must_match_the_reviewed_working_body(): void
    {
        [$s, $t, $p] = $this->fixture();
        $t->forceFill(['content_md' => 'Old draft body'])->saveQuietly();
        $p['target_snapshot_hash'] = F::targetHash($s);
        $this->assertSame(1, $this->runPublish($p, true));
        $this->assertSame($p['target_snapshot_hash'], F::targetHash($s));
        $this->assertFalse($t->fresh()->is_public);
        $this->assertSame(0, AuditLog::count());
    }

    private function fixture(): array
    {
        $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('test'), 'is_active' => 1]);
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $owner->id]);
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        $s = Article::create(['org_id' => 0, 'locale' => 'zh-CN', 'slug' => 'reviewed-translation', 'source_locale' => 'zh-CN',
            'translation_group_id' => 'reviewed-translation', 'translation_status' => 'source', 'title' => '源文',
            'content_md' => "## 参考来源\n\nhttps://doi.org/example", 'status' => 'published', 'is_public' => true, 'published_at' => now()->subDay()]);
        $sr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $s->id, 'source_article_id' => $s->id,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => 'source', 'title' => $s->title, 'content_md' => $s->content_md,
            'source_version_hash' => $s->source_version_hash, 'translated_from_version_hash' => $s->source_version_hash]);
        $s->forceFill(['working_revision_id' => $sr->id, 'published_revision_id' => $sr->id])->saveQuietly();
        EditorialReview::create(['org_id' => 0, 'content_type' => 'article', 'content_id' => $s->id, 'workflow_state' => 'approved', 'reviewed_by_admin_user_id' => $owner->id]);
        $category = ArticleCategory::create(['org_id' => 0, 'name' => 'Research', 'slug' => 'research', 'is_active' => true]);
        $tag = ArticleTag::create(['org_id' => 0, 'name' => 'Personality', 'slug' => 'personality', 'is_active' => true]);
        $t = Article::create(['org_id' => 0, 'locale' => 'en', 'slug' => $s->slug, 'source_locale' => 'zh-CN', 'source_article_id' => $s->id,
            'translated_from_article_id' => $s->id, 'translation_group_id' => $s->translation_group_id, 'translation_status' => 'approved',
            'title' => 'English research', 'excerpt' => 'Reviewed excerpt', 'content_md' => "## References\n\nhttps://doi.org/example",
            'category_id' => $category->id, 'status' => 'draft', 'is_public' => false, 'is_indexable' => false,
            'cover_image_url' => 'https://assets.fermatmind.com/cover.jpg', 'cover_image_alt' => 'Research illustration']);
        $t->tags()->sync([$tag->id => ['org_id' => 0]]);
        $tr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $t->id, 'source_article_id' => $s->id,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => 'approved', 'title' => $t->title, 'excerpt' => $t->excerpt, 'content_md' => $t->content_md,
            'source_version_hash' => $s->source_version_hash, 'translated_from_version_hash' => $s->source_version_hash]);
        $t->forceFill(['working_revision_id' => $tr->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $t->id, 'locale' => 'en', 'seo_title' => $t->title,
            'seo_description' => 'Research interpretation', 'canonical_url' => 'https://fermatmind.com/en/articles/'.$t->slug,
            'og_image_url' => $t->cover_image_url, 'robots' => 'noindex,nofollow', 'is_indexable' => false]);
        foreach ([$sr, $tr] as $r) {
            app(Reviews::class)->bindOrCreateApproved(null, 'cms_article_review', 'test:revision:'.$r->id,
                [['surface_id' => 'article_translation_revision', 'record' => $r]], $owner->id);
        }
        $s = $s->fresh();
        $p = ['schema' => 'reviewed_article_translation_publication_v1', 'source_id' => $s->id, 'target_id' => $t->id,
            'source_published_revision_id' => $sr->id, 'working_revision_id' => $tr->id,
            'source_snapshot_hash' => F::sourceHash($s), 'target_snapshot_hash' => F::targetHash($s), 'body_sha256' => hash('sha256', $tr->content_md)];

        return [$s, $t, $p];
    }

    private function runPublish(array $p, bool $execute = false, array $overrides = []): int
    {
        $file = tempnam(sys_get_temp_dir(), 'translation-publish-');
        file_put_contents($file, json_encode($p, JSON_THROW_ON_ERROR));
        try {
            $args = ['--article' => [$p['target_id']], '--translation-locks' => $file, '--translation-sha256' => hash_file('sha256', $file),
                '--preview-approved' => true, '--confirm' => 'I explicitly approve Codex to publish article id '.$p['target_id'].' after preflight passes.', '--json' => true];
            if (! $execute) {
                $args['--dry-run'] = true;
            }

            return Artisan::call('articles:publish-controlled', array_replace($args, $overrides));
        } finally {
            unlink($file);
        }
    }
}
