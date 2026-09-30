<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Support\ContentPageSourceTargetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ImportPolicy2PreparedTranslationDraftTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('pairs')]
    public function test_dry_run_import_and_restore_preserve_copy_and_review_state(int $sid, int $tid, string $slug, string $group): void
    {
        [$source, $target] = $this->pair($sid, $tid, $slug, $group);
        [$file, $sha] = $this->package($source, $target);
        try {
            $sourceBefore = ContentPageSourceTargetSnapshot::hash($source);
            $targetBefore = $target->getAttributes();
            $targetSnapshot = ContentPageSourceTargetSnapshot::hash($target);
            $revision = CmsTranslationRevision::findOrFail($source->published_revision_id);
            $adapter = app(\App\Services\Cms\ContentPageTranslationAdapter::class);
            $payload = $adapter->snapshotPayload($source);
            $payload['body_html'] = $source->getRawOriginal('content_html');
            $diagnostic = [
                'source_ready' => $source->passesPublicReadinessGate(),
                'source_hash_matches' => $source->source_version_hash === $source->freshSourceVersionHash(),
                'revision_status' => $revision->revision_status,
                'revision_pub' => $revision->published_at !== null,
                'revision_hash_matches' => $revision->source_version_hash === $source->source_version_hash,
                'payload_matches' => \App\Support\CanonicalTranslationPayloadHash::hash($payload) === \App\Support\CanonicalTranslationPayloadHash::hash($revision->payload_json),
                'blockers' => $adapter->requiredPayloadBlockers($revision->payload_json),
            ];
            $this->assertTrue($diagnostic['source_ready'] && $diagnostic['source_hash_matches'] && $diagnostic['revision_pub'] && $diagnostic['revision_hash_matches'] && $diagnostic['payload_matches'] && $diagnostic['blockers'] === [], json_encode($diagnostic));
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            $firstExit = $this->runCommand($file, $sha);
            $this->assertSame(0, $firstExit, Artisan::output());
            $this->assertSame(0, $this->runCommand($file, $sha));
            $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $q) === 1)->all();
            DB::connection()->disableQueryLog();
            $this->assertSame([], array_values($writes));
            $this->assertSame($targetSnapshot, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(1, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(1, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => 'wrong']));
            $cacheKey = 'content_page:v1:0:'.$slug.':en';
            Cache::put($cacheKey, ['legacy' => true], 300);
            $this->assertSame(0, $this->runCommand($file, $sha, ['--execute' => true,
                '--confirm' => sprintf('Import Policy2 private draft for target %d with package %s.', $tid, $sha)]));
            $this->assertFalse(Cache::has($cacheKey));
            $after = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR)['after'];
            $target->refresh();
            $published = CmsTranslationRevision::findOrFail($after['published_revision_id']);
            $draft = CmsTranslationRevision::findOrFail($after['working_revision_id']);
            $this->assertSame(3, CmsTranslationRevision::count());
            $this->assertSame('published', $published->revision_status);
            $this->assertNull($published->source_content_id);
            $this->assertNull($published->approved_at);
            $this->assertSame($targetBefore['content_md'], $published->payload_json['body_md']);
            $this->assertSame('draft', $draft->revision_status);
            $this->assertSame($published->id, $draft->supersedes_revision_id);
            $this->assertSame($source->source_version_hash, $draft->translated_from_version_hash);
            $this->assertSame($sid, $draft->source_content_id);
            $this->assertNull($draft->reviewed_at);
            $this->assertNull($draft->approved_at);
            $this->assertNull($draft->published_at);
            $this->assertSame(['English policy draft'], $draft->payload_json['headings_json']);
            $this->assertFalse($target->is_public);
            $this->assertSame('draft', $target->status);
            $this->assertSame('draft', $target->review_state);
            $this->assertSame('draft', $target->translation_status);
            $this->assertSame($sid, $target->source_content_id);
            $this->assertSame('zh-CN', $target->source_locale);
            $this->assertFalse(app(\App\Services\Cms\ContentPageTranslationAdapter::class)->isPublished($target));
            $this->assertNull(ContentPage::query()->withoutGlobalScopes()->whereKey($tid)->publiclyReadable()->first());
            $this->assertTrue(app(\App\Services\Cms\SiblingTranslationWorkflowService::class)->preflight('content_page', $target)['ok']);

            $this->assertSame($targetBefore['content_md'], $target->getRawOriginal('content_md'));
            $this->assertSame($sourceBefore, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertFalse(AuditLog::findOrFail($after['audit_id'])->meta_json['human_review_completed']);
            $this->assertSame(1, $this->runCommand($file, $sha));
            $restore = ['--restore-audit-id' => $after['audit_id']];
            $this->assertSame(0, $this->runCommand($file, $sha, $restore));
            Cache::put($cacheKey, ['draft' => true], 300);
            $this->assertSame(0, $this->runCommand($file, $sha, $restore + ['--execute' => true,
                '--confirm' => sprintf('Restore Policy2 private draft for target %d with package %s and audit %d.', $tid, $sha, $after['audit_id'])]));
            $this->assertFalse(Cache::has($cacheKey));
            $target->refresh();
            foreach ($targetBefore as $key => $value) {
                if ($key !== 'updated_at') {
                    $this->assertSame($value, $target->getRawOriginal($key), $key);
                }
            }
            $this->assertSame('archived', $draft->fresh()->revision_status);
            $this->assertSame('archived', $published->fresh()->revision_status);
            $this->assertSame($sourceBefore, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertNotNull(ContentPage::query()->withoutGlobalScopes()->whereKey($tid)->publiclyReadable()->first());
            $this->assertSame(1, $this->runCommand($file, $sha));
        } finally {
            unlink($file);
        }
    }

    public function test_drift_and_reviewed_draft_refuse_without_writes(): void
    {
        [$source, $target] = $this->pair(...self::pairs()[0]);
        [$file, $sha] = $this->package($source, $target);
        try {
            $target->forceFill(['content_md' => 'Changed placeholder'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(1, CmsTranslationRevision::count());
            $target->forceFill(['content_md' => 'Draft candidate'])->saveQuietly();
            $source->forceFill(['summary' => 'Changed source'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(0, AuditLog::count());
            $source->forceFill(['summary' => 'Summary'])->saveQuietly();
            $source->refresh();
            $target->refresh();
            unlink($file);
            [$file, $sha] = $this->package($source, $target);
            $this->assertSame(0, $this->runCommand($file, $sha, ['--execute' => true,
                '--confirm' => sprintf('Import Policy2 private draft for target %d with package %s.', $target->id, $sha)]));
            $after = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR)['after'];
            $working = CmsTranslationRevision::findOrFail($after['working_revision_id']);
            $working->forceFill(['reviewed_at' => now(), 'revision_status' => 'human_review'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--restore-audit-id' => $after['audit_id']]));
            $this->assertSame('human_review', $working->fresh()->revision_status);
            $this->assertSame(1, AuditLog::where('action', 'policy2_prepared_translation_draft_imported')->count());
            $this->assertSame(0, AuditLog::where('action', 'policy2_prepared_translation_draft_restored')->count());
        } finally {
            unlink($file);
        }
    }

    public static function pairs(): array
    {
        return [[55, 53, 'methodology', 'big5-v2-e34761eea2865c6ce6a0cc7d09897877215e4fd6'],
            [56, 54, 'source-review-policy', 'big5-v2-795d661fc1241f4311c1f6ce4f5ba082f74a5dac']];
    }

    private function pair(int $sid, int $tid, string $slug, string $group): array
    {
        $source = ContentPage::create(['org_id' => 0, 'slug' => 'test-source', 'path' => '/test-source',
            'locale' => 'zh-CN', 'translation_group_id' => $group, 'source_locale' => 'zh-CN',
            'translation_status' => 'source', 'kind' => 'policy', 'page_type' => 'policy',
            'template' => 'policy', 'animation_profile' => 'none', 'title' => 'Source',
            'summary' => 'Summary', 'content_md' => '## Source', 'seo_title' => 'Source SEO',
            'seo_description' => 'Source description', 'canonical_path' => '/zh/test-source',
            'status' => 'published', 'review_state' => 'approved', 'is_public' => true,
            'is_indexable' => false, 'publish_allowed' => true, 'claim_gate_status' => 'passed',
            'schema_enabled' => false, 'operator_approval_required' => false,
            'faq_schema_eligible' => false, 'headings_json' => [], 'faq_items' => [], 'forbidden_claims' => [],
            'published_at' => now()]);
        DB::table('content_pages')->where('id', $source->id)->update(['id' => $sid, 'slug' => $slug]);
        $source = ContentPage::findOrFail($sid);
        $source->forceFill(['source_version_hash' => $source->freshSourceVersionHash()])->saveQuietly();
        $sourceRevision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $source);
        $sourcePayload = app(\App\Services\Cms\ContentPageTranslationAdapter::class)->snapshotPayload($source);
        $sourcePayload['body_html'] = $source->getRawOriginal('content_html');
        $sourceRevision->forceFill(['payload_json' => $sourcePayload])->saveQuietly();
        $target = ContentPage::create(['org_id' => 0, 'slug' => 'test-target', 'path' => '/test-target',
            'locale' => 'en', 'translation_group_id' => $group, 'source_locale' => 'en',
            'translation_status' => 'source', 'kind' => 'company', 'page_type' => 'company',
            'template' => 'company', 'animation_profile' => 'none', 'title' => 'Draft candidate',
            'content_md' => 'Draft candidate', 'canonical_path' => '/en/personality/big-five/'.$slug,
            'status' => 'published', 'review_state' => 'approved', 'is_public' => true,
            'is_indexable' => false, 'operator_approval_required' => true,
            'claim_gate_status' => 'not_reviewed', 'published_at' => now()]);
        DB::table('content_pages')->where('id', $target->id)->update(['id' => $tid, 'slug' => $slug]);
        $target = ContentPage::findOrFail($tid);
        $target->forceFill(['source_version_hash' => $target->freshSourceVersionHash()])->saveQuietly();

        return [$source->fresh(), $target->fresh()];
    }

    private function package(ContentPage $source, ContentPage $target): array
    {
        $p = ['schema' => 'fermat_policy2_private_draft_v1',
            'source' => ['id' => $source->id, 'revision_id' => $source->published_revision_id,
                'snapshot_hash' => ContentPageSourceTargetSnapshot::hash($source), 'body_sha256' => hash('sha256', (string) $source->content_md)],
            'target' => ['id' => $target->id, 'snapshot_hash' => ContentPageSourceTargetSnapshot::hash($target),
                'body_sha256' => hash('sha256', (string) $target->content_md)],
            'copy' => ['title' => 'English policy draft', 'summary' => 'English summary.',
                'content_md' => "## English policy draft\n\nAn English policy draft.",
                'seo_title' => 'English policy draft', 'seo_description' => 'English summary.']];
        $f = tempnam(sys_get_temp_dir(), 'policy2-');
        $b = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($f, $b);

        return [$f, hash('sha256', $b)];
    }

    private function runCommand(string $file, string $hash, array $extra = []): int
    {
        return Artisan::call('translation:import-policy2-prepared-draft', array_replace(
            ['--file' => $file, '--sha256' => $hash, '--dry-run' => true, '--json' => true],
            $extra, isset($extra['--execute']) ? ['--dry-run' => false] : []));
    }
}
