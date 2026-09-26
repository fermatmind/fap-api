<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class NormalizeTranslationSourceStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_exact_for_article_and_content_page(): void
    {
        foreach (['article', 'content_page'] as $contentType) {
            [$source, $revision] = $this->seedLegacySource($contentType);
            $options = $this->commandOptions($contentType, $source, $revision, ['--dry-run' => true]);
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            [$exit, $result] = $this->runCommand($options);
            $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
                static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
            )->all();
            DB::connection()->disableQueryLog();

            $this->assertSame(0, $exit);
            $this->assertTrue($result['ok']);
            $this->assertSame([], array_values($writes));
            $this->assertSame($source->translation_status, $source->fresh()->translation_status);
            $this->assertSame((int) $revision->id, (int) $source->fresh()->published_revision_id);
        }
    }

    public function test_execute_changes_only_source_status_and_audits_exact_article(): void
    {
        [$source, $revision] = $this->seedLegacySource('article');
        $before = $source->fresh()->getAttributes();
        [$exit, $result] = $this->runCommand($this->commandOptions('article', $source, $revision, [
            '--execute' => true,
            '--confirm' => "Normalize article source {$source->id} in group {$source->translation_group_id}.",
        ]));

        $this->assertSame(0, $exit);
        $this->assertTrue($result['ok']);
        $this->assertSame('source_status_normalized', $result['action']);
        $this->assertSame('published', $result['before']['translation_status']);
        $this->assertSame('source', $result['after']['translation_status']);
        $this->assertGreaterThan(0, $result['after']['audit_id']);
        $this->assertSame('source', $source->fresh()->translation_status);
        $this->assertSame($before['source_version_hash'], $source->fresh()->source_version_hash);
        $this->assertSame($before['working_revision_id'], $source->fresh()->working_revision_id);
        $this->assertSame($before['published_revision_id'], $source->fresh()->published_revision_id);
        $this->assertSame($before['content_md'], $source->fresh()->content_md);
        $this->assertSame('published', $revision->fresh()->revision_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'translation_source_status_normalized',
            'target_type' => 'article',
            'target_id' => (string) $source->id,
        ]);
    }

    public function test_execute_preserves_content_page_payload_and_revision(): void
    {
        [$source, $revision] = $this->seedLegacySource('content_page');
        $before = $source->fresh()->getAttributes();
        [$exit, $result] = $this->runCommand($this->commandOptions('content_page', $source, $revision, [
            '--execute' => true,
            '--confirm' => "Normalize content_page source {$source->id} in group {$source->translation_group_id}.",
        ]));

        $this->assertSame(0, $exit);
        $this->assertTrue($result['ok']);
        $this->assertSame('published', $result['before']['translation_status']);
        $this->assertSame('source', $result['after']['translation_status']);
        $this->assertSame($before['content_md'], $source->fresh()->content_md);
        $this->assertSame($before['source_version_hash'], $source->fresh()->source_version_hash);
        $this->assertSame($before['published_revision_id'], $source->fresh()->published_revision_id);
        $this->assertSame('published', $revision->fresh()->revision_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'translation_source_status_normalized',
            'target_type' => 'content_page',
            'target_id' => (string) $source->id,
        ]);
    }

    public function test_restore_uses_exact_audit_and_current_row_lock(): void
    {
        [$source, $revision] = $this->seedLegacySource('article');
        [$executeExit] = $this->runCommand($this->commandOptions('article', $source, $revision, [
            '--execute' => true,
            '--confirm' => "Normalize article source {$source->id} in group {$source->translation_group_id}.",
        ]));
        $this->assertSame(0, $executeExit);
        $source = $source->fresh();
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'translation_source_status_normalized')->firstOrFail();
        $options = $this->commandOptions('article', $source, $revision, [
            '--expected-status' => 'source',
            '--restore-audit-id' => (int) $audit->id,
            '--dry-run' => true,
        ]);
        [$dryExit, $dryResult] = $this->runCommand($options);
        $this->assertSame(0, $dryExit);
        $this->assertTrue($dryResult['ok']);

        unset($options['--dry-run']);
        $options['--execute'] = true;
        $options['--confirm'] = "Restore article source {$source->id} from audit {$audit->id}.";
        [$restoreExit, $restoreResult] = $this->runCommand($options);
        $this->assertSame(0, $restoreExit);
        $this->assertSame('source_status_restored', $restoreResult['action']);
        $this->assertGreaterThan((int) $audit->id, $restoreResult['after']['audit_id']);
        $this->assertSame('published', $source->fresh()->translation_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'translation_source_status_restored',
            'target_type' => 'article',
            'target_id' => (string) $source->id,
        ]);
    }

    public function test_conflicting_hash_or_second_source_refuses_all_writes(): void
    {
        [$source, $revision] = $this->seedLegacySource('article');
        [$hashExit, $hashResult] = $this->runCommand($this->commandOptions('article', $source, $revision, [
            '--expected-source-hash' => str_repeat('0', 64),
            '--dry-run' => true,
        ]));
        $this->assertSame(1, $hashExit);
        $this->assertContains('source_hash_drift', $hashResult['errors']);

        Article::query()->create([
            'org_id' => 0,
            'slug' => 'other-root',
            'locale' => 'en',
            'source_locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id,
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Other root',
            'content_md' => 'Other root body',
            'status' => 'published',
            'is_public' => true,
        ]);
        [$duplicateExit, $duplicateResult] = $this->runCommand($this->commandOptions('article', $source, $revision, [
            '--execute' => true,
            '--confirm' => "Normalize article source {$source->id} in group {$source->translation_group_id}.",
        ]));
        $this->assertSame(1, $duplicateExit);
        $this->assertContains('source_identity_ambiguous', $duplicateResult['errors']);
        $this->assertSame('published', $source->fresh()->translation_status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'translation_source_status_normalized']);
    }

    public function test_updated_at_lock_refuses_stale_execution(): void
    {
        [$source, $revision] = $this->seedLegacySource('content_page');
        $options = $this->commandOptions('content_page', $source, $revision, [
            '--execute' => true,
            '--confirm' => "Normalize content_page source {$source->id} in group {$source->translation_group_id}.",
        ]);
        DB::table('content_pages')->where('id', $source->id)->update(['updated_at' => now()->addMinute()]);

        [$exit, $result] = $this->runCommand($options);

        $this->assertSame(1, $exit);
        $this->assertContains('source_updated_at_drift', $result['errors']);
        $this->assertSame('published', $source->fresh()->translation_status);
    }

    /** @return array{Article|ContentPage,ArticleTranslationRevision|CmsTranslationRevision} */
    private function seedLegacySource(string $contentType): array
    {
        if ($contentType === 'article') {
            $source = Article::query()->create([
                'org_id' => 0,
                'slug' => 'legacy-article-source',
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'translation_group_id' => 'article-legacy-source',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => '中文文章',
                'excerpt' => '摘要',
                'content_md' => '正文',
                'status' => 'published',
                'is_public' => true,
            ]);
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => 0,
                'article_id' => (int) $source->id,
                'source_article_id' => (int) $source->id,
                'translation_group_id' => (string) $source->translation_group_id,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 1,
                'revision_status' => 'published',
                'source_version_hash' => (string) $source->source_version_hash,
                'title' => $source->title,
                'content_md' => $source->content_md,
            ]);
        } else {
            $source = ContentPage::query()->create([
                'org_id' => 0,
                'slug' => 'legacy-page-source',
                'path' => '/legacy-page-source',
                'kind' => ContentPage::KIND_COMPANY,
                'page_type' => 'company',
                'template' => 'company',
                'animation_profile' => 'none',
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'translation_group_id' => 'content-page-legacy-source',
                'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
                'title' => '中文页面',
                'content_md' => '正文',
                'seo_title' => '页面 SEO',
                'seo_description' => '描述',
                'canonical_path' => '/legacy-page-source',
                'status' => 'published',
                'review_state' => 'approved',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => now(),
            ]);
            $revision = CmsTranslationRevision::query()->create([
                'org_id' => 0,
                'content_type' => 'content_page',
                'content_id' => (int) $source->id,
                'source_content_id' => (int) $source->id,
                'translation_group_id' => (string) $source->translation_group_id,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 1,
                'revision_status' => 'published',
                'source_version_hash' => (string) $source->source_version_hash,
                'payload_json' => ['title' => $source->title, 'body_md' => $source->content_md],
            ]);
        }

        $source->forceFill([
            'translation_status' => 'published',
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->saveQuietly();

        return [$source->fresh(), $revision];
    }

    /** @param Article|ContentPage $source @param ArticleTranslationRevision|CmsTranslationRevision $revision @param array<string,mixed> $overrides @return array<string,mixed> */
    private function commandOptions(string $contentType, Article|ContentPage $source, ArticleTranslationRevision|CmsTranslationRevision $revision, array $overrides): array
    {
        return array_replace([
            '--content-type' => $contentType,
            '--source-id' => (int) $source->id,
            '--translation-group-id' => (string) $source->translation_group_id,
            '--source-locale' => (string) $source->locale,
            '--expected-status' => 'published',
            '--expected-source-hash' => (string) $source->source_version_hash,
            '--expected-working-revision-id' => (int) $revision->id,
            '--expected-published-revision-id' => (int) $revision->id,
            '--expected-updated-at' => $source->updated_at?->toDateTimeString(),
            '--json' => true,
        ], $overrides);
    }

    /** @param array<string,mixed> $options @return array{int,array<string,mixed>} */
    private function runCommand(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('translation:normalize-source-status', $options, $output);

        return [$exit, json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR)];
    }
}
