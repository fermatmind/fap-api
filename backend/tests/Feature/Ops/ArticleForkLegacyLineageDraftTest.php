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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ArticleForkLegacyLineageDraftTest extends TestCase
{
    use RefreshDatabase;

    public static function pairs(): array
    {
        return [[37, 39], [40, 41], [68, 69], [64, 65], [72, 73], [84, 85], [74, 75], [70, 71]];
    }

    #[DataProvider('pairs')]
    public function test_read_repair_restore_preserve_historical_copy_and_provenance(int $sid, int $tid): void
    {
        [$s, $t] = $this->pair($sid, $tid);
        [$file, $sha] = $this->package($s, $t);
        try {
            $sourceBefore = $s->getAttributes();
            $targetBefore = $t->getAttributes();
            $sourceHistory = ArticleSourceTargetSnapshot::sourceRevisions($s);
            $targetHistory = ArticleSourceTargetSnapshot::sourceRevisions($t);
            $sourceSeo = $s->seoMeta->getAttributes();
            $targetSeo = $t->seoMeta->getAttributes();
            $sourceHash = Locks::sourceHash($s);
            $targetHash = Locks::sourceHash($t);
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            $this->assertSame(0, $this->callCommand($file, $sha));
            $first = Artisan::output();
            $this->assertSame(0, $this->callCommand($file, $sha));
            $this->assertSame($first, Artisan::output());
            $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $q) === 1)->all();
            DB::connection()->disableQueryLog();
            $this->assertSame([], array_values($writes));
            $this->assertSame($sourceHash, Locks::sourceHash($s->fresh()));
            $this->assertSame($targetHash, Locks::sourceHash($t->fresh()));
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(1, $this->callCommand($file, $sha, ['--execute' => true, '--dry-run' => false, '--confirm' => 'wrong']));
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha, $tid)));
            $after = json_decode(Artisan::output(), true)['after'];
            $new = ArticleTranslationRevision::findOrFail($after['new_revision_id']);
            $this->assertSame(3, ArticleTranslationRevision::count());
            $this->assertSame($sourceHistory, ArticleSourceTargetSnapshot::sourceRevisions($s));
            $this->assertSame($targetHistory, ArticleSourceTargetSnapshot::sourceRevisions($t, [(int) $new->id]));
            $this->assertSame($sourceSeo, $s->fresh()->seoMeta->getAttributes());
            $this->assertSame($targetSeo, $t->fresh()->seoMeta->getAttributes());
            $this->assertTrue($s->fresh()->isSourceArticle());
            $this->assertFalse($t->fresh()->isSourceArticle());
            $this->assertSame($sid, (int) $t->fresh()->sourceArticle()->id);
            $this->assertSame($targetBefore['translated_from_version_hash'], $t->fresh()->translated_from_version_hash);
            $this->assertSame($targetBefore['published_revision_id'], $t->fresh()->published_revision_id);
            $this->assertSame($sourceBefore['published_revision_id'], $s->fresh()->published_revision_id);
            $this->assertSame($sourceBefore['working_revision_id'], $s->fresh()->working_revision_id);
            $this->assertSame('machine_draft', $new->revision_status);
            $this->assertSame('Fresh complete English candidate.', $new->content_md);
            $this->assertSame($s->source_version_hash, $new->translated_from_version_hash);
            $this->assertSame($s->publishedRevision->source_version_hash, $new->authority_metadata_json['source_published_version_hash']);
            $this->assertFalse($new->authority_metadata_json['source_editorial_approval_granted']);
            foreach (['reviewed_by', 'reviewed_at', 'approved_at', 'published_at', 'authority_source_hash', 'authority_package_sha256'] as $field) {
                $this->assertNull($new->$field);
            }
            $audit = AuditLog::findOrFail($after['audit_id']);
            $this->assertFalse($audit->meta_json['human_review_completed']);
            $this->assertFalse($audit->meta_json['published_copy_or_provenance_changed']);
            foreach ([$s, $t] as $i => $row) {
                $allowed = $i ? ['source_locale', 'translation_status', 'source_article_id', 'translated_from_article_id', 'working_revision_id', 'updated_at'] : ['source_locale', 'translation_status', 'updated_at'];
                $before = $i ? $targetBefore : $sourceBefore;
                $current = $row->fresh()->getAttributes();
                foreach ($allowed as $field) {
                    unset($before[$field], $current[$field]);
                }
                $this->assertSame($before, $current);
            }
            $this->assertSame(1, $this->callCommand($file, $sha));
            $forkSource = Locks::sourceHash($s->fresh());
            $forkTarget = Locks::sourceHash($t->fresh());
            $this->assertSame(0, $this->callCommand($file, $sha, ['--restore-audit-id' => $audit->id]));
            $this->assertSame($forkSource, Locks::sourceHash($s->fresh()));
            $this->assertSame($forkTarget, Locks::sourceHash($t->fresh()));
            $originalAudit = $audit->meta_json;
            $tampered = $originalAudit;
            $tampered['source_identity_before']['content_md'] = 'Invalid recovery override';
            $audit->forceFill(['meta_json' => $tampered])->saveQuietly();
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, $tid, (int) $audit->id)));
            $this->assertSame($forkSource, Locks::sourceHash($s->fresh()));
            $this->assertSame($forkTarget, Locks::sourceHash($t->fresh()));
            $audit->forceFill(['meta_json' => $originalAudit])->saveQuietly();
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha, $tid, (int) $audit->id)));
            foreach ([$s, $t] as $i => $row) {
                $before = $i ? $targetBefore : $sourceBefore;
                $current = $row->fresh()->getAttributes();
                unset($before['updated_at'], $current['updated_at']);
                $this->assertSame($before, $current);
            }
            $this->assertSame($sourceHistory, ArticleSourceTargetSnapshot::sourceRevisions($s));
            $this->assertSame($targetHistory, ArticleSourceTargetSnapshot::sourceRevisions($t, [(int) $new->id]));
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, $tid, (int) $audit->id)));
        } finally {
            DB::connection()->disableQueryLog();
            unlink($file);
        }
    }

    public function test_independent_english_source61_is_outside_repair_scope(): void
    {
        [$s, $t] = $this->pair(60, 61);
        [$file, $sha] = $this->package($s, $t);
        try {
            $before = [Locks::sourceHash($s), Locks::sourceHash($t)];
            $this->assertSame(1, $this->callCommand($file, $sha));
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, 61)));
            $this->assertSame($before, [Locks::sourceHash($s->fresh()), Locks::sourceHash($t->fresh())]);
            $this->assertSame(2, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_audit_failure_rolls_back_identity_and_private_revision(): void
    {
        [$s, $t] = $this->pair(37, 39);
        [$file, $sha] = $this->package($s, $t);
        try {
            $before = [Locks::sourceHash($s), Locks::sourceHash($t)];
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, 39)));
            $this->assertSame($before, [Locks::sourceHash($s->fresh()), Locks::sourceHash($t->fresh())]);
            $this->assertSame(2, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_snapshot_drift_collision_and_reviewed_restore_refuse(): void
    {
        [$s, $t] = $this->pair(37, 39);
        [$file, $sha] = $this->package($s, $t);
        try {
            DB::table('article_seo_meta')->where('article_id', $s->id)->update(['seo_title' => 'Changed source SEO']);
            $this->assertSame(1, $this->callCommand($file, $sha));
            DB::table('article_seo_meta')->where('article_id', $s->id)->update(['seo_title' => 'Source SEO']);
            $collision = Article::forceCreate(['org_id' => 0, 'slug' => 'collision', 'locale' => 'en',
                'translation_group_id' => $s->translation_group_id, 'title' => 'Collision', 'content_md' => 'Other content', 'status' => 'draft']);
            $this->assertSame(1, $this->callCommand($file, $sha));
            $collision->forceDelete();
            $this->assertSame(0, $this->callCommand($file, $sha, $this->execute($sha, 39)));
            $after = json_decode(Artisan::output(), true)['after'];
            ArticleTranslationRevision::whereKey($after['new_revision_id'])->update(['reviewed_at' => now()]);
            $before = [Locks::sourceHash($s->fresh()), Locks::sourceHash($t->fresh())];
            $this->assertSame(1, $this->callCommand($file, $sha, $this->execute($sha, 39, $after['audit_id'])));
            $this->assertSame($before, [Locks::sourceHash($s->fresh()), Locks::sourceHash($t->fresh())]);
        } finally {
            unlink($file);
        }
    }

    private function pair(int $sid, int $tid): array
    {
        $s = Article::forceCreate(['id' => $sid, 'org_id' => 0, 'slug' => 'source-'.$sid, 'locale' => 'zh-CN',
            'translation_group_id' => 'legacy-'.$sid, 'source_locale' => 'zh-CN', 'translation_status' => 'approved',
            'title' => 'Source title', 'excerpt' => 'Source summary', 'content_md' => 'Current source body', 'status' => 'published', 'is_public' => true]);
        $sr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $sid, 'source_article_id' => $sid,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => in_array($sid, [40, 74], true) ? str_repeat('e', 64) : $s->source_version_hash,
            'title' => $s->title, 'excerpt' => $s->excerpt, 'content_md' => $s->content_md]);
        $s->forceFill(['working_revision_id' => $sr->id, 'published_revision_id' => $sr->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $sid, 'locale' => 'zh-CN', 'seo_title' => 'Source SEO', 'seo_description' => 'Source SEO description']);
        $t = Article::forceCreate(['id' => $tid, 'org_id' => 0, 'slug' => in_array($sid, [37, 40], true) ? 'target-'.$tid : $s->slug,
            'locale' => 'en', 'translation_group_id' => $s->translation_group_id, 'source_locale' => 'en', 'translation_status' => 'source',
            'translated_from_version_hash' => str_repeat('a', 64), 'title' => 'Old target', 'excerpt' => 'Old summary',
            'content_md' => 'Old public row body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $tr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $tid, 'source_article_id' => $tid,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1,
            'revision_status' => 'published', 'title' => $t->title, 'excerpt' => $t->excerpt,
            'content_md' => $sid === 60 ? 'Distinct old revision body' : $t->content_md,
            'translated_from_version_hash' => str_repeat('a', 64), 'source_version_hash' => str_repeat('b', 64),
            'approved_at' => now(), 'published_at' => now()]);
        $t->forceFill(['working_revision_id' => $tr->id, 'published_revision_id' => $tr->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $tid, 'locale' => 'en', 'seo_title' => 'Old SEO', 'seo_description' => 'Old SEO description']);

        return [$s->fresh(), $t->fresh()];
    }

    private function package(Article $s, Article $t): array
    {
        $fields = [];
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $field) {
            $value = in_array($field, ['seo_title', 'seo_description'], true) ? $s->seoMeta?->$field : $s->$field;
            $fields[$field] = hash('sha256', (string) $value);
        }
        $p = ['schema' => 'fermat_legacy_article_lineage_private_draft_v1', 'source_id' => (int) $s->id, 'target_id' => (int) $t->id,
            'source_snapshot_hash' => Locks::sourceHash($s), 'target_snapshot_hash' => Locks::sourceHash($t), 'source_fields_sha256' => $fields,
            'source_published_revision_id' => (int) $s->published_revision_id, 'source_working_revision_id' => (int) $s->working_revision_id,
            'target_published_revision_id' => (int) $t->published_revision_id, 'target_working_revision_id' => (int) $t->working_revision_id,
            'translation' => ['title' => 'New English', 'excerpt' => 'New summary.', 'content_md' => 'Fresh complete English candidate.', 'seo_title' => 'New SEO', 'seo_description' => 'New SEO description.']];
        $file = tempnam(sys_get_temp_dir(), 'legacy-lineage-');
        $raw = json_encode($p, JSON_THROW_ON_ERROR);
        file_put_contents($file, $raw);

        return [$file, hash('sha256', $raw)];
    }

    private function callCommand(string $file, string $sha, array $extra = []): int
    {
        return Artisan::call('articles:fork-legacy-lineage-draft', array_replace(['--file' => $file, '--sha256' => $sha, '--dry-run' => true, '--json' => true], $extra));
    }

    private function execute(string $sha, int $tid, int $audit = 0): array
    {
        return ['--execute' => true, '--dry-run' => false, '--restore-audit-id' => $audit ?: null,
            '--confirm' => sprintf('%s legacy Article lineage draft for target %d with package %s%s.', $audit ? 'Restore' : 'Fork', $tid, $sha, $audit ? ' and audit '.$audit : '')];
    }
}
