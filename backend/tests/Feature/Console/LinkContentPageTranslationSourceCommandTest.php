<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class LinkContentPageTranslationSourceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_has_no_writes_and_execute_links_only_row_identity(): void
    {
        [$source, $target, $revision] = $this->seedPair();
        $before = $target->getAttributes();
        $revisionBefore = (array) DB::table('cms_translation_revisions')->where('id', $revision->id)->first();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        [$dryExit, $dry] = $this->runCommand($this->commandOptions($source, $target, $revision, ['--dry-run' => true]));
        $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
            static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
        )->all();
        DB::connection()->disableQueryLog();
        $this->assertSame(0, $dryExit);
        $this->assertTrue($dry['ok']);
        $this->assertSame([], array_values($writes));
        $this->assertNull($target->fresh()->source_content_id);

        [$exit, $result] = $this->runCommand($this->commandOptions($source, $target, $revision, [
            '--execute' => true,
            '--confirm' => "Link content page target {$target->id} to source {$source->id} in group {$source->translation_group_id}.",
        ]));
        $this->assertSame(0, $exit);
        $this->assertTrue($result['ok']);
        $this->assertSame((int) $source->id, $result['after']['source_content_id']);
        $this->assertFalse($result['after']['translation_freshness_attested']);
        $this->assertGreaterThan(0, $result['after']['audit_id']);
        $after = $target->fresh()->getAttributes();
        unset($before['source_content_id'], $before['updated_at'], $after['source_content_id'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($revisionBefore, (array) DB::table('cms_translation_revisions')->where('id', $revision->id)->first());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_page_source_linked',
            'target_type' => 'content_page',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_exact_audit_locked_restore_returns_to_unlinked_state(): void
    {
        [$source, $target, $revision] = $this->seedPair();
        [$linkExit] = $this->runCommand($this->commandOptions($source, $target, $revision, [
            '--execute' => true,
            '--confirm' => "Link content page target {$target->id} to source {$source->id} in group {$source->translation_group_id}.",
        ]));
        $this->assertSame(0, $linkExit);
        $target = $target->fresh();
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'content_page_source_linked')->firstOrFail();
        [$restoreExit, $restore] = $this->runCommand($this->commandOptions($source, $target, $revision, [
            '--restore-audit-id' => (int) $audit->id,
            '--execute' => true,
            '--confirm' => "Restore content page target {$target->id} from audit {$audit->id}.",
        ]));
        $this->assertSame(0, $restoreExit, json_encode($restore));
        $this->assertSame('source_link_restored', $restore['action']);
        $this->assertNull($target->fresh()->source_content_id);
        $this->assertSame((int) $revision->id, (int) $target->fresh()->published_revision_id);
    }

    public function test_conflicting_revision_provenance_or_group_pair_refuses_link(): void
    {
        [$source, $target, $revision] = $this->seedPair();
        $revision->forceFill(['translated_from_version_hash' => str_repeat('a', 64)])->saveQuietly();
        [$provenanceExit, $provenance] = $this->runCommand($this->commandOptions($source, $target, $revision, ['--dry-run' => true]));
        $this->assertSame(1, $provenanceExit);
        $this->assertContains('published_revision_identity_or_provenance_drift', $provenance['errors']);

        $revision->forceFill(['translated_from_version_hash' => null])->saveQuietly();
        $third = $this->page('en', 'other-slug', 'A third row', (string) $source->translation_group_id, 'zh-CN', (int) $source->id);
        $this->assertNotNull($third->id);
        [$ambiguousExit, $ambiguous] = $this->runCommand($this->commandOptions($source, $target, $revision, ['--dry-run' => true]));
        $this->assertSame(1, $ambiguousExit);
        $this->assertContains('group_pair_ambiguous', $ambiguous['errors']);
        $this->assertNull($target->fresh()->source_content_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'content_page_source_linked']);
    }

    public function test_unique_legacy_published_root_can_be_linked_without_normalizing_its_status(): void
    {
        [$source, $target, $revision] = $this->seedPair();
        $source->forceFill(['translation_status' => 'published'])->saveQuietly();
        $source = $source->fresh();

        [$exit, $result] = $this->runCommand($this->commandOptions($source, $target, $revision, [
            '--dry-run' => true,
        ]));

        $this->assertSame(0, $exit);
        $this->assertTrue($result['ok']);
        $this->assertSame('published', $source->fresh()->translation_status);
        $this->assertNull($target->fresh()->source_content_id);
    }

    /** @return array{ContentPage,ContentPage,CmsTranslationRevision} */
    private function seedPair(): array
    {
        $source = $this->page('zh-CN', 'same-slug', '中文源文', 'content-page-same-slug', 'zh-CN', null);
        $target = $this->page('en', 'same-slug', 'English translation', 'content-page-same-slug', 'zh-CN', (int) $source->id);
        DB::table('content_pages')->where('id', $target->id)->update([
            'source_content_id' => null,
            'translated_from_version_hash' => null,
        ]);
        $target = $target->fresh();
        $revision = CmsTranslationRevision::query()->create([
            'org_id' => 0,
            'content_type' => 'content_page',
            'content_id' => (int) $target->id,
            'source_content_id' => null,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => 'published',
            'source_version_hash' => (string) $target->source_version_hash,
            'translated_from_version_hash' => null,
            'payload_json' => ['title' => $target->title, 'body_md' => $target->content_md],
            'published_at' => now(),
        ]);
        $target->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->saveQuietly();

        return [$source->fresh(), $target->fresh(), $revision];
    }

    private function page(string $locale, string $slug, string $title, string $group, string $sourceLocale, ?int $sourceId): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => '/'.$slug,
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => $locale,
            'source_locale' => $sourceLocale,
            'source_content_id' => $sourceId,
            'translation_group_id' => $group,
            'translation_status' => $sourceId === null ? 'source' : 'published',
            'title' => $title,
            'content_md' => 'Complete body',
            'seo_title' => $title,
            'seo_description' => 'Description',
            'canonical_path' => '/'.$slug,
            'status' => 'published',
            'review_state' => 'approved',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function commandOptions(ContentPage $source, ContentPage $target, CmsTranslationRevision $revision, array $overrides): array
    {
        return array_replace([
            '--source-id' => (int) $source->id,
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $source->translation_group_id,
            '--slug' => (string) $source->slug,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--revision-id' => (int) $revision->id,
            '--source-updated-at' => $source->updated_at?->toDateTimeString(),
            '--target-updated-at' => $target->updated_at?->toDateTimeString(),
            '--json' => true,
        ], $overrides);
    }

    /** @param array<string,mixed> $options @return array{int,array<string,mixed>} */
    private function runCommand(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('translation:link-content-page-source', $options, $output);

        return [$exit, json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR)];
    }
}
