<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\ArticleForkPrivateTranslationLinks;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class ArticleForkPrivateTranslationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_reads_fork_and_restore_preserve_old_revisions_and_row_projection(): void
    {
        [$source, $target, $old] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $sourceBefore = ArticleForkPrivateTranslationLinks::sourceHash($source);
            $targetBefore = ArticleForkPrivateTranslationLinks::targetHash($source);
            $oldBefore = $old->getAttributes();
            $rowBefore = $target->getAttributes();
            $count = ArticleTranslationRevision::count();
            $this->assertSame(0, $this->runCommand($file, $sha));
            $this->assertSame(0, $this->runCommand($file, $sha));
            $this->assertSame($targetBefore, ArticleForkPrivateTranslationLinks::targetHash($source));
            $this->assertSame($count, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(0, $this->runCommand($file, $sha, $this->execute($target, $sha)));
            $result = json_decode(Artisan::output(), true);
            $new = ArticleTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $this->assertSame($count + 1, ArticleTranslationRevision::count());
            $this->assertSame($oldBefore, $old->fresh()->getAttributes());
            $this->assertSame(3, $new->revision_number);
            $this->assertSame((int) $old->id, $new->supersedes_revision_id);
            $this->assertSame(str_replace('/zh/', '/en/', $old->content_md), $new->content_md);
            $this->assertSame($old->translated_from_version_hash, $new->translated_from_version_hash);
            foreach (['title', 'excerpt', 'seo_title', 'seo_description', 'source_version_hash', 'source_article_id', 'authority_source_hash', 'authority_package_sha256'] as $key) {
                $this->assertSame($old->getAttribute($key), $new->getAttribute($key), $key);
            }
            $this->assertNull($new->reviewed_by);
            $this->assertNull($new->reviewed_at);
            $this->assertNull($new->approved_at);
            $this->assertNull($new->published_at);
            $this->assertSame('machine_draft', $new->revision_status);
            $after = $target->fresh()->getAttributes();
            foreach (['working_revision_id', 'updated_at'] as $key) {
                unset($rowBefore[$key], $after[$key]);
            }
            $this->assertSame($rowBefore, $after);
            $this->assertSame($sourceBefore, ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()));
            $auditId = $result['after']['audit_id'];
            $this->assertFalse(AuditLog::findOrFail($auditId)->meta_json['human_review_completed']);
            $hashAfter = ArticleForkPrivateTranslationLinks::targetHash($source);
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId]));
            $this->assertSame($hashAfter, ArticleForkPrivateTranslationLinks::targetHash($source));
            $this->assertSame(0, $this->runCommand($file, $sha, $this->execute($target, $sha, $auditId)));
            $this->assertSame((int) $old->id, (int) $target->fresh()->working_revision_id);
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($oldBefore, $old->fresh()->getAttributes());
            $this->assertSame($sourceBefore, ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()));
            $this->assertSame(2, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_copy_rewrites_unknown_links_and_stale_package_are_rejected_without_writes(): void
    {
        [$source, $target] = $this->pair();
        foreach (['Changed prose.', str_replace('/zh/', '/en/', $target->workingRevision->content_md).' Added prose.'] as $body) {
            [$file, $sha] = $this->package($source, $target, $body);
            try {
                $before = ArticleForkPrivateTranslationLinks::targetHash($source);
                $this->assertSame(1, $this->runCommand($file, $sha, $this->execute($target, $sha)));
                $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($source));
            } finally {
                unlink($file);
            }
        }
        [$file, $sha] = $this->package($source, $target);
        try {
            ArticleSeoMeta::where('article_id', $source->id)->update(['seo_title' => 'Changed source SEO']);
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_restore_rejects_reviewed_new_draft_or_historical_revision_drift(): void
    {
        [$source, $target, $old] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $this->assertSame(0, $this->runCommand($file, $sha, $this->execute($target, $sha)));
            $result = json_decode(Artisan::output(), true);
            $old->forceFill(['excerpt' => 'An old edit'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha, $this->execute($target, $sha, $result['after']['audit_id'])));
            $new = ArticleTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $new->forceFill(['reviewed_at' => now(), 'revision_status' => 'human_review'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--restore-audit-id' => $result['after']['audit_id']]));
            $this->assertSame((int) $new->id, (int) $target->fresh()->working_revision_id);
            $this->assertSame(1, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_audit_failure_rolls_back_and_published_or_reviewed_targets_are_refused(): void
    {
        [$source, $target, $old] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failed'));
            $before = ArticleForkPrivateTranslationLinks::targetHash($source);
            $this->assertSame(1, $this->runCommand($file, $sha, $this->execute($target, $sha)));
            $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($source));
            $target->forceFill(['is_public' => true, 'status' => 'published', 'published_revision_id' => $old->id])->saveQuietly();
            [$otherFile, $otherSha] = $this->package($source, $target);
            try {
                $this->assertSame(1, $this->runCommand($otherFile, $otherSha));
            } finally {
                unlink($otherFile);
            }
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_escaped_destinations_are_adapted_and_unrelated_routes_stay_unchanged(): void
    {
        [$source, $target, $old] = $this->pair();
        $old->forceFill(['content_md' => "## Copy\n\n[Guide](https://fermatmind\\.com/zh/articles/big\\-five\\-tool\\-guide)\n[Other](https://fermatmind.com/zh/help/about)\n[External](https://example.com/zh/articles/big-five-tool-guide)"])->saveQuietly();
        $candidate = str_replace('fermatmind\\.com/zh/', 'fermatmind\\.com/en/', $old->content_md);
        [$file, $sha] = $this->package($source, $target, $candidate);
        try {
            $this->assertSame(0, $this->runCommand($file, $sha, $this->execute($target, $sha)));
            $new = $target->fresh()->workingRevision;
            $this->assertSame($candidate, $new->content_md);
            $this->assertStringContainsString('/zh/help/about', $new->content_md);
            $this->assertStringContainsString('https://example.com/zh/', $new->content_md);
        } finally {
            unlink($file);
        }
    }

    public function test_digest_confirmation_and_identity_mismatch_never_create_a_revision(): void
    {
        [$source, $target] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $before = ArticleForkPrivateTranslationLinks::targetHash($source);
            $count = ArticleTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, str_repeat('a', 64)));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => 'wrong']));
            $p = json_decode(file_get_contents($file), true);
            $p['target_id'] = (int) $source->id;
            file_put_contents($file, json_encode($p));
            $this->assertSame(1, $this->runCommand($file, hash_file('sha256', $file)));
            $this->assertSame($before, ArticleForkPrivateTranslationLinks::targetHash($source));
            $this->assertSame($count, ArticleTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    private function pair(): array
    {
        $source = Article::create(['org_id' => 0, 'slug' => 'private-links', 'locale' => 'zh-CN',
            'translation_group_id' => 'private-links', 'source_locale' => 'zh-CN', 'translation_status' => 'source',
            'title' => 'Source', 'excerpt' => 'Source summary', 'content_md' => 'Source body', 'status' => 'published', 'is_public' => true]);
        $revision = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id,
            'translation_group_id' => $source->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $source->source_version_hash,
            'title' => $source->title, 'content_md' => $source->content_md, 'published_at' => now()]);
        $source->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::create(['org_id' => 0, 'article_id' => $source->id, 'locale' => 'zh-CN', 'seo_title' => 'Source SEO']);
        $target = Article::create(['org_id' => 0, 'slug' => $source->slug, 'locale' => 'en', 'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'zh-CN', 'source_article_id' => $source->id, 'translated_from_article_id' => $source->id,
            'translation_status' => 'machine_draft', 'translated_from_version_hash' => $source->source_version_hash,
            'title' => 'English title', 'content_md' => 'Private row copy', 'status' => 'draft', 'is_public' => false,
            'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false]);
        $old = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $target->id, 'source_article_id' => $source->id,
            'translation_group_id' => $source->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => 'machine_draft', 'source_version_hash' => $source->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash, 'title' => 'English title', 'excerpt' => 'English summary',
            'content_md' => "## English\n\n[Guide](https://fermatmind.com/zh/articles/big-five-tool-guide)\n[Hub](/zh/personality/big-five)",
            'seo_title' => 'English SEO', 'seo_description' => 'English SEO description',
            'authority_metadata_json' => ['draft_origin' => 'operator_supplied_ai_draft', 'editorial_review_state' => 'pending']]);
        $historical = $old->replicate();
        $historical->forceFill(['revision_number' => 2, 'revision_status' => 'stale'])->saveQuietly();
        $target->forceFill(['working_revision_id' => $old->id])->saveQuietly();

        return [$source->fresh(), $target->fresh(), $old->fresh()];
    }

    private function package(Article $source, Article $target, ?string $body = null): array
    {
        $p = ['schema' => 'fermat_article_private_links_v1', 'source_id' => (int) $source->id, 'target_id' => (int) $target->id,
            'working_revision_id' => (int) $target->working_revision_id,
            'source_snapshot_hash' => ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()),
            'target_snapshot_hash' => ArticleForkPrivateTranslationLinks::targetHash($source->fresh()),
            'content_md' => $body ?? str_replace('/zh/', '/en/', $target->fresh()->workingRevision->content_md)];
        $file = tempnam(sys_get_temp_dir(), 'private-links-');
        file_put_contents($file, json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [$file, hash_file('sha256', $file)];
    }

    private function execute(Article $target, string $sha, int $audit = 0): array
    {
        $args = ['--execute' => true, '--confirm' => sprintf('%s private Article links for target %d with package %s%s.',
            $audit ? 'Restore' : 'Fork', $target->id, $sha, $audit ? ' and audit '.$audit : '')];
        if ($audit) {
            $args['--restore-audit-id'] = $audit;
        }

        return $args;
    }

    private function runCommand(string $file, string $sha, array $args = []): int
    {
        return Artisan::call('articles:fork-private-translation-links', ['--file' => $file, '--sha256' => $sha, '--json' => true,
            ...isset($args['--execute']) ? [] : ['--dry-run' => true], ...$args]);
    }
}
