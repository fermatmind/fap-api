<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\ArticleForkPrivateTranslationLinks as Locks;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ArticleForkExistingTranslationDraftTest extends TestCase
{
    use RefreshDatabase;

    public static function existingAndStalePairs(): array
    {
        return [[4, 191, false], [4, 191, true], [12, 27, true], [13, 29, true], [14, 28, true], [15, 24, true], [16, 25, true]];
    }

    #[DataProvider('existingAndStalePairs')]
    public function test_read_fork_restore_preserve_public_projection_and_history(int $sourceId, int $targetId, bool $privateSourceDraft): void
    {
        [$s,$t,$old] = $this->pair($sourceId, $targetId);
        if ($privateSourceDraft) {
            $draft = $s->publishedRevision->replicate();
            $draft->forceFill(['revision_number' => 2, 'revision_status' => 'draft', 'content_md' => 'Unpublished source edit.'])->save();
            $s->forceFill(['working_revision_id' => $draft->id])->saveQuietly();
        }
        if ($sourceId >= 12 && $sourceId <= 16) {
            $s->publishedRevision->forceFill(['source_version_hash' => str_repeat('e', 64)])->saveQuietly();
        }
        $initialCount = ArticleTranslationRevision::count();
        [$file,$sha] = $this->package($s, $t);
        try {
            $source = Locks::sourceHash($s);
            $target = Locks::targetHash($s);
            $row = $t->getAttributes();
            $history = ArticleSourceTargetSnapshot::sourceRevisions($t);
            $seo = $t->seoMeta->getAttributes();
            $this->assertSame(0, $this->callCommand($file, $sha));
            $this->assertSame(0, $this->callCommand($file, $sha));
            $this->assertSame($target, Locks::targetHash($s));
            $this->assertSame($initialCount, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha, 0, $targetId)));
            $result = json_decode(Artisan::output(), true);
            $new = ArticleTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $this->assertSame($initialCount + 1, ArticleTranslationRevision::count());
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($t, [(int) $new->id]));
            $this->assertSame($source, Locks::sourceHash($s->fresh()));
            $this->assertSame($seo, $t->fresh()->seoMeta->getAttributes());
            $after = $t->fresh()->getAttributes();
            foreach (['working_revision_id', 'updated_at'] as $key) {
                unset($row[$key],$after[$key]);
            }$this->assertSame($row, $after);
            $this->assertSame((int) $old->id, $new->supersedes_revision_id);
            $this->assertSame('machine_draft', $new->revision_status);
            $this->assertSame($s->source_version_hash, $new->translated_from_version_hash);
            $this->assertNotSame($old->translated_from_version_hash, $new->translated_from_version_hash);
            $this->assertSame('Fresh complete candidate.', $new->content_md);
            $this->assertSame($source, $new->authority_metadata_json['source_snapshot_hash']);
            $this->assertSame($s->publishedRevision->source_version_hash, $new->authority_metadata_json['source_published_version_hash']);
            $this->assertSame($sourceId < 12, $new->authority_metadata_json['source_published_hash_matches_current']);
            foreach (['reviewed_by', 'reviewed_at', 'approved_at', 'published_at', 'authority_source_hash', 'authority_package_sha256'] as $key) {
                $this->assertNull($new->$key, $key);
            }
            $audit = $result['after']['audit_id'];
            $this->assertFalse(AuditLog::findOrFail($audit)->meta_json['human_review_completed']);
            $this->assertSame(1, $this->callCommand($file, $sha));
            $fork = Locks::targetHash($s);
            $this->assertSame(0, $this->callCommand($file, $sha, ['--restore-audit-id' => $audit]));
            $this->assertSame($fork, Locks::targetHash($s));
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha, $audit, $targetId)));
            $this->assertSame((int) $old->id, (int) $t->fresh()->working_revision_id);
            $this->assertSame((int) $old->id, (int) $t->fresh()->published_revision_id);
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($t, [(int) $new->id]));
            $this->assertSame(2, AuditLog::count());
            $this->assertSame($source, Locks::sourceHash($s->fresh()));
        } finally {
            unlink($file);
        }
    }

    public function test_unscoped_legacy_source_hash_is_rejected_without_writes(): void
    {
        [$s,$t] = $this->pair();
        $s->publishedRevision->forceFill(['source_version_hash' => str_repeat('e', 64)])->saveQuietly();
        [$file,$sha] = $this->package($s, $t);
        try {
            $before = Locks::targetHash($s);
            $this->assertSame(1, $this->callCommand($file, $sha));
            $this->assertSame($before, Locks::targetHash($s));
            $this->assertSame(2, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_bad_package_confirmation_copy_and_source_fields_are_no_write(): void
    {
        [$s,$t] = $this->pair();
        [$file,$sha] = $this->package($s, $t);
        try {
            $before = Locks::targetHash($s);
            $this->assertSame(1, $this->callCommand($file, str_repeat('a', 64)));
            $this->assertSame(1, $this->callCommand($file, $sha, ['--execute' => true, '--confirm' => 'wrong']));
            $p = json_decode(file_get_contents($file), true);
            foreach ([['source_id' => 3], ['source_fields_sha256' => [...$p['source_fields_sha256'], 'content_md' => str_repeat('a', 64)]], ['translation' => [...$p['translation'], 'seo_title' => str_repeat('x', 61)]], ['translation' => [...$p['translation'], 'content_md' => 'English __FM_TOKEN_0001__']], ['translation' => [...$p['translation'], 'content_md' => '中文']], ['extra' => true]] as $change) {
                file_put_contents($file, json_encode([...$p, ...$change]));
                $this->assertSame(1, $this->callCommand($file, hash_file('sha256', $file)));
                $this->assertSame($before, Locks::targetHash($s));
            }
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_audit_failure_rolls_back_and_concurrent_seo_change_is_rejected(): void
    {
        [$s,$t] = $this->pair();
        [$file,$sha] = $this->package($s, $t);
        try {
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failed'));
            $before = Locks::targetHash($s);
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha)));
            $this->assertSame($before, Locks::targetHash($s));
            ArticleSeoMeta::where('article_id', $t->id)->update(['seo_title' => 'Concurrent SEO']);
            $before = Locks::targetHash($s);
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha)));
            $this->assertSame($before, Locks::targetHash($s));
            $this->assertSame(2, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_restore_refuses_historical_drift_and_reviewed_draft(): void
    {
        [$s,$t,$old] = $this->pair();
        [$file,$sha] = $this->package($s, $t);
        try {
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha)));
            $r = json_decode(Artisan::output(), true);
            $new = $t->fresh()->workingRevision;
            $old->forceFill(['excerpt' => 'Old edit'])->saveQuietly();
            $before = Locks::targetHash($s);
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, $r['after']['audit_id'])));
            $this->assertSame($before, Locks::targetHash($s));
            $new->forceFill(['reviewed_at' => now(), 'revision_status' => 'human_review'])->saveQuietly();
            $this->assertSame(1, $this->callCommand($file, $sha, ['--restore-audit-id' => $r['after']['audit_id']]));
            $this->assertSame((int) $new->id, (int) $t->fresh()->working_revision_id);
            $this->assertSame(1, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_source_content_or_published_version_drift_is_rejected(): void
    {
        [$s,$t] = $this->pair();
        [$file,$sha] = $this->package($s, $t);
        try {
            $s->forceFill(['content_md' => 'Changed source body'])->saveQuietly();
            $this->assertSame(1, $this->callCommand($file, $sha));
            [$next,$digest] = $this->package($s->fresh(), $t);
            try {
                $this->assertSame(1, $this->callCommand($next, $digest));
            } finally {
                unlink($next);
            }$this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_duplicate_english_identity_refuses_fork(): void
    {
        [$s, $t, $old] = $this->pair();
        [$file, $sha] = $this->package($s, $t);
        try {
            Article::forceCreate(['id' => 192, 'org_id' => 0, 'slug' => 'another-slug', 'locale' => 'en',
                'translation_group_id' => $s->translation_group_id, 'source_locale' => 'zh-CN',
                'source_article_id' => $s->id, 'translated_from_article_id' => $s->id,
                'translation_status' => 'machine_draft', 'title' => 'Other candidate', 'content_md' => 'Other private body', 'status' => 'draft']);
            $count = ArticleTranslationRevision::count();
            $this->assertSame(1, $this->callCommand($file, $sha));
            $this->assertSame($count, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame((int) $old->id, (int) $t->fresh()->working_revision_id);
        } finally {
            unlink($file);
        }
    }

    private function pair(int $sourceId = 4, int $targetId = 191): array
    {
        $s = Article::forceCreate(['id' => $sourceId, 'org_id' => 0, 'slug' => 'existing-test-'.$sourceId, 'locale' => 'zh-CN', 'translation_group_id' => 'existing-test-'.$sourceId, 'source_locale' => 'zh-CN', 'translation_status' => 'source', 'title' => 'Source title', 'excerpt' => 'Source summary', 'content_md' => 'Current source body', 'status' => 'published', 'is_public' => true]);
        $sr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $s->id, 'source_article_id' => $s->id, 'translation_group_id' => $s->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'source', 'source_version_hash' => $s->source_version_hash, 'title' => $s->title, 'excerpt' => $s->excerpt, 'content_md' => $s->content_md]);
        $s->forceFill(['working_revision_id' => $sr->id, 'published_revision_id' => $sr->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $s->id, 'locale' => 'zh-CN', 'seo_title' => 'Source SEO', 'seo_description' => 'Source SEO description']);
        $t = Article::forceCreate(['id' => $targetId, 'org_id' => 0, 'slug' => $s->slug, 'locale' => 'en', 'translation_group_id' => $s->translation_group_id, 'source_locale' => 'zh-CN', 'translation_status' => 'published', 'source_article_id' => $s->id, 'translated_from_article_id' => $s->id, 'translated_from_version_hash' => str_repeat('a', 64), 'title' => 'Old title', 'excerpt' => 'Old summary', 'content_md' => 'Old public body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'published_at' => now()]);
        $old = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $t->id, 'source_article_id' => $s->id, 'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published', 'title' => $t->title, 'excerpt' => $t->excerpt, 'content_md' => $t->content_md, 'translated_from_version_hash' => str_repeat('a', 64), 'reviewed_by' => 1, 'reviewed_at' => now(), 'approved_at' => now(), 'published_at' => now(), 'authority_source_hash' => str_repeat('b', 64), 'authority_package_sha256' => str_repeat('c', 64)]);
        $t->forceFill(['working_revision_id' => $old->id, 'published_revision_id' => $old->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $t->id, 'locale' => 'en', 'seo_title' => 'Public SEO', 'seo_description' => 'Public description', 'is_indexable' => true]);

        return [$s->fresh(), $t->fresh(), $old->fresh()];
    }

    private function package(Article $s, Article $t): array
    {
        $fields = [];
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $k) {
            $fields[$k] = hash('sha256', (string) (in_array($k, ['seo_title', 'seo_description'], true) ? $s->seoMeta?->$k : $s->$k));
        }
        $p = ['schema' => 'fermat_existing_article_translation_draft_v1', 'source_id' => (int) $s->id, 'target_id' => (int) $t->id, 'working_revision_id' => (int) $t->working_revision_id, 'source_published_revision_id' => (int) $s->published_revision_id, 'source_snapshot_hash' => Locks::sourceHash($s), 'target_snapshot_hash' => Locks::targetHash($s), 'source_fields_sha256' => $fields, 'translation' => ['title' => 'Fresh English title', 'excerpt' => 'Fresh summary', 'content_md' => 'Fresh complete candidate.', 'seo_title' => 'Fresh SEO', 'seo_description' => 'Fresh SEO description']];
        $file = tempnam(sys_get_temp_dir(), 'existing-draft-');
        file_put_contents($file, json_encode($p));

        return [$file, hash_file('sha256', $file)];
    }

    private function execute(string $sha, int $audit = 0, int $targetId = 191): array
    {
        return ['--execute' => true, '--confirm' => sprintf('%s existing Article translation draft for target %d with package %s%s.', $audit ? 'Restore' : 'Fork', $targetId, $sha, $audit ? ' and audit '.$audit : ''), ...$audit ? ['--restore-audit-id' => $audit] : []];
    }

    private function callCommand(string $file, string $sha, array $args = []): int
    {
        return Artisan::call('articles:fork-existing-translation-draft', ['--file' => $file, '--sha256' => $sha, '--json' => true, ...isset($args['--execute']) ? [] : ['--dry-run' => true], ...$args]);
    }
}
