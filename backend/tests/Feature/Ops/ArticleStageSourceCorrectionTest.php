<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\ArticleForkPrivateTranslationLinks as Locks;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ArticleStageSourceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public static function correctionPairs(): array
    {
        return [[11, 26], [140, 207], [141, 208], [142, 209], [143, 210], [146, 211], [147, 235], [149, 237], [153, 213], [154, 214], [155, 215], [167, 226], [176, 246], [178, 244]];
    }

    #[DataProvider('correctionPairs')]
    public function test_dry_run_stage_and_restore_preserve_public_versions_and_english(int $sourceId, int $targetId): void
    {
        [$s, $old] = $this->pair($sourceId, $targetId);
        [$file, $sha] = $this->package($s);
        try {
            $initial = $s->getAttributes();
            $published = $old->getAttributes();
            $english = Locks::targetHash($s);
            $public = $this->getJson('/api/v0.5/articles/correction-source?locale=zh-CN')->assertOk()->json();
            $count = ArticleTranslationRevision::withoutGlobalScopes()->count();
            $this->assertSame(0, $this->callCommand($file, $sha));
            $this->assertSame(0, $this->callCommand($file, $sha));
            $this->assertSame($count, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
            $this->assertSame($initial, $s->fresh()->getAttributes());
            $this->assertSame(0, $this->callCommand($file, $sha, true));
            $out = json_decode(Artisan::output(), true);
            $s = $s->fresh();
            $draft = $s->workingRevision;
            $this->assertSame($count + 1, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame($published, $old->fresh()->getAttributes());
            $this->assertSame($english, Locks::targetHash($s));
            $this->assertSame($public, $this->getJson('/api/v0.5/articles/correction-source?locale=zh-CN')->assertOk()->json());
            $this->assertSame('human_review', $draft->revision_status);
            $this->assertSame('Corrected source body.', $draft->content_md);
            $this->assertNull($draft->reviewed_by);
            $this->assertNull($draft->approved_at);
            foreach (['authority_asset_key', 'authority_source_package', 'authority_source_hash', 'authority_package_sha256'] as $field) {
                $this->assertNull($draft->$field);
            }
            $this->assertNotSame($initial['source_version_hash'], $s->source_version_hash);
            $this->assertSame($old->id, $s->published_revision_id);
            $auditId = $out['after']['audit_id'];
            $this->assertFalse(AuditLog::withoutGlobalScopes()->findOrFail($auditId)->meta_json['human_review_completed']);
            $this->assertSame(1, $this->callCommand($file, $sha, true));
            $this->assertSame(0, $this->callCommand($file, $sha, false, $auditId));
            $this->assertSame(0, $this->callCommand($file, $sha, true, $auditId));
            $this->assertSame($initial, $s->fresh()->getAttributes());
            $this->assertSame('archived', $draft->fresh()->revision_status);
            $this->assertSame($published, $old->fresh()->getAttributes());
            $this->assertSame($english, Locks::targetHash($s->fresh()));
            $this->assertSame(2, AuditLog::withoutGlobalScopes()->count());
        } finally {
            unlink($file);
        }
    }

    public function test_changed_source_or_target_locks_reject_all_writes(): void
    {
        [$s] = $this->pair();
        [$file, $sha] = $this->package($s);
        try {
            $target = Article::withoutGlobalScopes()->findOrFail(207);
            $target->forceFill(['title' => 'Changed target'])->saveQuietly();
            $before = Locks::sourceHash($s->fresh());
            $this->assertSame(1, $this->callCommand($file, $sha, true));
            $this->assertSame($before, Locks::sourceHash($s->fresh()));
            $this->assertSame(2, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        } finally {
            unlink($file);
        }
    }

    public function test_review_or_candidate_edit_prevents_restore(): void
    {
        [$s] = $this->pair();
        [$file, $sha] = $this->package($s);
        try {
            $this->assertSame(0, $this->callCommand($file, $sha, true));
            $decoded = json_decode(Artisan::output(), true);
            $this->assertIsArray($decoded);
            $audit = $decoded['after']['audit_id'];
            $draft = $s->fresh()->workingRevision;
            $draft->forceFill(['reviewed_by' => 1])->saveQuietly();
            $before = Locks::sourceHash($s->fresh());
            $this->assertSame(1, $this->callCommand($file, $sha, true, $audit));
            $this->assertSame($before, Locks::sourceHash($s->fresh()));
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->count());
        } finally {
            unlink($file);
        }
    }

    public function test_audit_failure_rolls_back_fork_and_source_hash(): void
    {
        [$s] = $this->pair();
        [$file, $sha] = $this->package($s);
        try {
            $before = Locks::sourceHash($s);
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
            $this->assertSame(1, $this->callCommand($file, $sha, true));
            $this->assertSame($before, Locks::sourceHash($s->fresh()));
            $this->assertSame(2, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        } finally {
            unlink($file);
        }
    }

    private function callCommand(string $file, string $sha, bool $execute = false, int $restore = 0): int
    {
        $sourceId = json_decode(file_get_contents($file), true)['source_id'];

        return Artisan::call('articles:stage-source-correction', ['--file' => $file, '--sha256' => $sha,
            $execute ? '--execute' : '--dry-run' => true,
            ...($restore ? ['--restore-audit-id' => $restore] : []),
            ...($execute ? ['--confirm' => sprintf('%s Article source correction %d with package %s%s.',
                $restore ? 'Restore' : 'Stage', $sourceId, $sha, $restore ? ' and audit '.$restore : '')] : []),
        ]);
    }

    private function pair(int $sourceId = 140, int $targetId = 207): array
    {
        $s = Article::forceCreate(['id' => $sourceId, 'org_id' => 0, 'slug' => 'correction-source', 'locale' => 'zh-CN',
            'source_locale' => 'zh-CN', 'translation_group_id' => 'correction-group', 'translation_status' => 'source',
            'title' => 'Original source', 'excerpt' => 'Original excerpt', 'content_md' => 'Original source body.',
            'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'published_at' => now()->subDay()]);
        $old = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $s->id, 'source_article_id' => $s->id,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'source', 'source_version_hash' => $s->source_version_hash,
            'translated_from_version_hash' => $s->source_version_hash, 'title' => $s->title, 'excerpt' => $s->excerpt,
            'content_md' => $s->content_md, 'authority_asset_key' => 'historical-key',
            'authority_source_package' => 'historical-package', 'authority_source_hash' => str_repeat('a', 64),
            'authority_package_sha256' => str_repeat('b', 64), 'published_at' => now()->subDay()]);
        $s->forceFill(['working_revision_id' => $old->id, 'published_revision_id' => $old->id])->saveQuietly();
        $t = Article::forceCreate(['id' => $targetId, 'org_id' => 0, 'slug' => $s->slug, 'locale' => 'en', 'source_locale' => 'zh-CN',
            'translation_group_id' => $s->translation_group_id, 'translation_status' => 'machine_draft',
            'source_article_id' => $s->id, 'translated_from_article_id' => $s->id, 'title' => 'English title',
            'content_md' => 'English body.', 'status' => 'draft', 'is_public' => false]);
        $draft = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $t->id, 'source_article_id' => $s->id,
            'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'machine_draft', 'source_version_hash' => $s->source_version_hash,
            'translated_from_version_hash' => $s->source_version_hash, 'title' => $t->title, 'content_md' => $t->content_md]);
        $t->forceFill(['working_revision_id' => $draft->id])->saveQuietly();

        return [$s->fresh(), $old->fresh()];
    }

    private function package(Article $s): array
    {
        $p = ['schema' => 'fermat_article_source_correction_v1', 'source_id' => (int) $s->id, 'target_id' => (int) Article::withoutGlobalScopes()->where('locale', 'en')->value('id'),
            'published_revision_id' => (int) $s->published_revision_id, 'source_snapshot_hash' => Locks::sourceHash($s),
            'target_snapshot_hash' => Locks::targetHash($s), 'original_source_version_hash' => $s->source_version_hash,
            'source_updated_at' => $s->getRawOriginal('updated_at'),
            'correction' => ['title' => $s->title, 'excerpt' => $s->excerpt, 'content_md' => 'Corrected source body.',
                'seo_title' => 'Corrected source', 'seo_description' => 'Corrected description.']];
        $file = tempnam(sys_get_temp_dir(), 'source-correction-');
        file_put_contents($file, json_encode($p, JSON_THROW_ON_ERROR));

        return [$file, hash_file('sha256', $file)];
    }
}
