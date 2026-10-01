<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ContentPageTranslationAdapter;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Support\ContentPageSourceTargetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ImportHelp6PreparedTranslationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_import_restore_retains_public_copy_and_all_previous_revisions(): void
    {
        [$source, $target, $published, $oldWorking] = $this->pair();
        [$file, $sha, $confirm] = $this->package($source, $target);
        try {
            $sourceSnapshot = ContentPageSourceTargetSnapshot::hash($source);
            $targetSnapshot = ContentPageSourceTargetSnapshot::hash($target);
            $publishedBefore = $published->getAttributes();
            $workingBefore = $oldWorking->getAttributes();
            $rowBefore = $target->getAttributes();
            $count = CmsTranslationRevision::count();
            $this->assertSame(0, $this->runCommand($file, $sha));
            $this->assertSame(0, $this->runCommand($file, $sha));
            $this->assertSame($targetSnapshot, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(0, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => $confirm]));
            $result = json_decode(Artisan::output(), true);
            $new = CmsTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $target->refresh();
            $this->assertSame($count + 1, CmsTranslationRevision::count());
            $this->assertSame((int) $new->id, (int) $target->working_revision_id);
            $this->assertSame((int) $published->id, (int) $target->published_revision_id);
            $this->assertSame((int) $oldWorking->id, (int) $new->supersedes_revision_id);
            $this->assertSame(8, (int) $new->revision_number);
            $this->assertSame('draft', $new->revision_status);
            $this->assertSame($source->source_version_hash, $new->translated_from_version_hash);
            $this->assertSame($rowBefore['translated_from_version_hash'], $target->getRawOriginal('translated_from_version_hash'));
            $this->assertNull($new->reviewed_at);
            $this->assertNull($new->approved_at);
            $this->assertNull($new->published_at);
            $this->assertSame("## About\n\nEnglish copy.\n\n### Contact\n\nRead the guide.", $new->payload_json['body_md']);
            $this->assertSame('', $new->payload_json['body_html']);
            $this->assertSame(['About', 'Contact'], $new->payload_json['headings_json']);
            foreach ($oldWorking->payload_json as $key => $value) {
                if (! in_array($key, ['title', 'summary', 'body_md', 'body_html', 'seo_title', 'seo_description', 'headings_json'], true)) {
                    $this->assertSame($value, $new->payload_json[$key], $key);
                }
            }
            $this->assertSame($sourceSnapshot, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($workingBefore, $oldWorking->fresh()->getAttributes());
            $rowAfter = $target->getAttributes();
            foreach (['working_revision_id', 'translation_status', 'updated_at'] as $key) {
                unset($rowBefore[$key], $rowAfter[$key]);
            }
            $this->assertSame($rowBefore, $rowAfter);
            $auditId = $result['after']['audit_id'];
            $this->assertFalse(AuditLog::findOrFail($auditId)->meta_json['human_review_completed']);
            $afterSnapshot = ContentPageSourceTargetSnapshot::hash($target);
            $this->assertSame(0, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId]));
            $this->assertSame($afterSnapshot, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(1, AuditLog::count());
            $this->assertSame(0, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId, '--execute' => true,
                '--confirm' => sprintf('Restore Help6 target %d with package %s and audit %d.', $target->id, $sha, $auditId)]));
            $this->assertSame((int) $oldWorking->id, (int) $target->fresh()->working_revision_id);
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($count + 1, CmsTranslationRevision::count());
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($workingBefore, $oldWorking->fresh()->getAttributes());
            $this->assertSame($sourceSnapshot, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertSame(2, AuditLog::count());
            $this->assertSame(1, $this->runCommand($file, $sha));
        } finally {
            unlink($file);
        }
    }

    public function test_source_and_historical_target_revision_drift_refuse_import(): void
    {
        [$source, $target, $published] = $this->pair();
        [$file, $sha, $confirm] = $this->package($source, $target);
        try {
            $published->forceFill(['approved_at' => now()->subDay()])->saveQuietly();
            $count = CmsTranslationRevision::count();
            $before = ContentPageSourceTargetSnapshot::hash($target->fresh());
            $this->assertSame(1, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => $confirm]));
            $this->assertContains('target_revision_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $source->forceFill(['summary' => 'Changed source summary'])->save();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertContains('source_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
        } finally {
            unlink($file);
        }
    }

    public function test_restore_refuses_edits_to_new_draft_or_any_old_revision(): void
    {
        [$source, $target, $published] = $this->pair();
        [$file, $sha, $confirm] = $this->package($source, $target);
        try {
            $this->assertSame(0, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => $confirm]));
            $result = json_decode(Artisan::output(), true);
            $auditId = $result['after']['audit_id'];
            $published->forceFill(['reviewed_at' => now()->subDay()])->saveQuietly();
            $before = ContentPageSourceTargetSnapshot::hash($target->fresh());
            $this->assertSame(1, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId, '--execute' => true,
                '--confirm' => sprintf('Restore Help6 target %d with package %s and audit %d.', $target->id, $sha, $auditId)]));
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(1, AuditLog::count());
            $new = CmsTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $new->forceFill(['revision_status' => 'human_review', 'reviewed_at' => now()])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId]));
            $this->assertSame('human_review', $new->fresh()->revision_status);
        } finally {
            unlink($file);
        }
    }

    public function test_bad_digest_confirmation_and_out_of_cohort_identity_fail_without_writes(): void
    {
        [$source, $target] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $this->assertSame(1, $this->runCommand($file, str_repeat('0', 64)));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => 'wrong']));
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(0, AuditLog::count());
            $source->forceFill(['slug' => 'help-data-deletion'])->saveQuietly();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertContains('identity_or_publication_invalid', json_decode(Artisan::output(), true)['errors']);
        } finally {
            unlink($file);
        }
    }

    public function test_audit_failure_rolls_back_new_revision_and_pointer(): void
    {
        [$source, $target] = $this->pair();
        [$file, $sha, $confirm] = $this->package($source, $target);
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $count = CmsTranslationRevision::count();
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--execute' => true, '--confirm' => $confirm]));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_source_snapshot_with_matching_hash_but_different_published_payload_is_rejected(): void
    {
        [$source, $target] = $this->pair();
        $sourceRevision = CmsTranslationRevision::findOrFail($source->published_revision_id);
        $payload = $sourceRevision->payload_json;
        $payload['body_md'] = 'Unrelated source revision body';
        $sourceRevision->forceFill(['payload_json' => $payload])->saveQuietly();
        [$file, $sha] = $this->package($source, $target);
        try {
            $count = CmsTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertContains('source_revision_invalid', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_extra_publication_fields_and_non_english_copy_are_rejected(): void
    {
        [$source, $target] = $this->pair();
        [$file, $sha] = $this->package($source, $target);
        try {
            $package = json_decode(file_get_contents($file), true);
            foreach ([['is_public' => true], ['content_md' => '尚未翻译的正文'], ['seo_description' => str_repeat('x', 2001)]] as $change) {
                $candidate = $package;
                $candidate['copy'] = array_replace($candidate['copy'], $change);
                file_put_contents($file, json_encode($candidate, JSON_THROW_ON_ERROR));
                $this->assertSame(1, $this->runCommand($file, hash_file('sha256', $file)));
                $this->assertSame(0, AuditLog::count());
            }
            $this->assertSame($package['target']['working_revision_id'], (int) $target->fresh()->working_revision_id);
        } finally {
            unlink($file);
        }
    }

    public static function coreSlugs(): array
    {
        return [['about'], ['privacy'], ['terms']];
    }

    #[DataProvider('coreSlugs')]
    public function test_core_pages_require_separate_schema_and_preserve_public_and_legal_fields(string $slug): void
    {
        [$source, $target, $published, $working] = $this->pair($slug);
        $payload = $working->payload_json;
        $payload['legal_review_required'] = true;
        $working->forceFill(['payload_json' => $payload])->saveQuietly();
        [$file, $sha, $confirm] = $this->package($source, $target, 'core3');
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $sourceBefore = ContentPageSourceTargetSnapshot::hash($source);
            $publicBefore = $published->getAttributes();
            $oldBefore = $working->getAttributes();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--cohort' => 'arbitrary']));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'core3']));
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'core3', '--execute' => true, '--confirm' => $confirm]));
            $result = json_decode(Artisan::output(), true);
            $new = CmsTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $auditId = $result['after']['audit_id'];
            $this->assertSame('core3_prepared_translation_draft_imported', AuditLog::findOrFail($auditId)->action);
            $this->assertFalse(AuditLog::findOrFail($auditId)->meta_json['human_review_completed']);
            $this->assertTrue($new->payload_json['legal_review_required']);
            $this->assertNull($new->reviewed_at);
            $this->assertNull($new->approved_at);
            $this->assertNull($new->published_at);
            $this->assertSame($publicBefore, $published->fresh()->getAttributes());
            $this->assertSame($oldBefore, $working->fresh()->getAttributes());
            $this->assertSame($sourceBefore, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--restore-audit-id' => $auditId]));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'core3', '--restore-audit-id' => $auditId, '--execute' => true,
                '--confirm' => sprintf('Restore Core3 target %d with package %s and audit %d.', $target->id, $sha, $auditId)]));
            $this->assertSame((int) $working->id, (int) $target->fresh()->working_revision_id);
            $this->assertSame('archived', $new->fresh()->revision_status);
        } finally {
            unlink($file);
        }
    }

    public static function serviceSlugs(): array
    {
        $cases = [];
        foreach ([
            'help-unlock-failure', 'help-payment-refund', 'help-result-recovery',
            'help-privacy-data', 'help-use-boundaries', 'help-data-deletion',
        ] as $slug) {
            $cases[$slug.' linked'] = [$slug, false];
            $cases[$slug.' legacy unlinked'] = [$slug, true];
        }

        return $cases;
    }

    #[DataProvider('serviceSlugs')]
    public function test_service_shared_published_revision_forks_privately_and_restores(string $slug, bool $legacyUnlinked): void
    {
        [$source, $target, $published] = $this->pair($slug);
        if ($legacyUnlinked) {
            $published->forceFill(['source_content_id' => null, 'translated_from_version_hash' => null])->saveQuietly();
        }
        $target->forceFill(['working_revision_id' => $published->id, 'translation_status' => 'published'])->saveQuietly();
        [$file, $sha, $confirm] = $this->package($source, $target, 'help-service');
        try {
            $rowBefore = $target->getAttributes();
            $sourceBefore = ContentPageSourceTargetSnapshot::hash($source);
            $publishedBefore = $published->getAttributes();
            $count = CmsTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, $sha));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'help-service']));
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'help-service']));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'help-service', '--execute' => true, '--confirm' => $confirm]));
            $result = json_decode(Artisan::output(), true);
            $new = CmsTranslationRevision::findOrFail($result['after']['new_revision_id']);
            $auditId = $result['after']['audit_id'];
            $this->assertSame($count + 1, CmsTranslationRevision::count());
            $this->assertSame((int) $published->id, (int) $new->supersedes_revision_id);
            $this->assertSame((int) $source->id, (int) $new->source_content_id);
            $this->assertSame($source->source_version_hash, $new->translated_from_version_hash);
            $this->assertSame('draft', $new->revision_status);
            $this->assertNull($new->reviewed_at);
            $this->assertNull($new->approved_at);
            $this->assertNull($new->published_at);
            $this->assertFalse(AuditLog::findOrFail($auditId)->meta_json['human_review_completed']);
            $rowAfter = $target->fresh()->getAttributes();
            foreach (['working_revision_id', 'translation_status', 'updated_at'] as $key) {
                unset($rowBefore[$key], $rowAfter[$key]);
            }
            $this->assertSame($rowBefore, $rowAfter);
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($sourceBefore, ContentPageSourceTargetSnapshot::hash($source->fresh()));
            $this->assertSame(1, $this->runCommand($file, $sha, ['--cohort' => 'help-service', '--execute' => true, '--confirm' => $confirm]));
            $this->assertSame($count + 1, CmsTranslationRevision::count());
            $this->assertSame(0, $this->runCommand($file, $sha, ['--cohort' => 'help-service', '--restore-audit-id' => $auditId,
                '--execute' => true, '--confirm' => sprintf('Restore HelpService target %d with package %s and audit %d.', $target->id, $sha, $auditId)]));
            $this->assertSame((int) $published->id, (int) $target->fresh()->working_revision_id);
            $this->assertSame('published', $target->fresh()->translation_status);
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($sourceBefore, ContentPageSourceTargetSnapshot::hash($source->fresh()));
        } finally {
            unlink($file);
        }
    }

    public function test_service_shared_published_revision_rejects_a_foreign_source_link(): void
    {
        [$source, $target, $published] = $this->pair('help-result-recovery');
        $published->forceFill(['source_content_id' => $target->id])->saveQuietly();
        $target->forceFill(['working_revision_id' => $published->id, 'translation_status' => 'published'])->saveQuietly();
        [$file, $sha, $confirm] = $this->package($source, $target, 'help-service');
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $count = CmsTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--cohort' => 'help-service', '--execute' => true, '--confirm' => $confirm]));
            $this->assertContains('target_revision_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_service_shared_pointer_still_rejects_unsafe_source(): void
    {
        [$source, $target, $published] = $this->pair('help-use-boundaries');
        $target->forceFill(['working_revision_id' => $published->id, 'translation_status' => 'published'])->saveQuietly();
        $source->forceFill(['page_type' => 'boundary', 'publish_allowed' => false])->saveQuietly();
        [$file, $sha, $confirm] = $this->package($source, $target, 'help-service');
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $count = CmsTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--cohort' => 'help-service', '--execute' => true, '--confirm' => $confirm]));
            $this->assertContains('identity_or_publication_invalid', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    public function test_service_cohort_rejects_an_otherwise_valid_non_service_pair(): void
    {
        [$source, $target] = $this->pair('help-about');
        [$file, $sha] = $this->package($source, $target, 'help-service');
        try {
            $before = ContentPageSourceTargetSnapshot::hash($target);
            $count = CmsTranslationRevision::count();
            $this->assertSame(1, $this->runCommand($file, $sha, ['--cohort' => 'help-service']));
            $this->assertSame(['identity_or_publication_invalid'], json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, ContentPageSourceTargetSnapshot::hash($target->fresh()));
            $this->assertSame($count, CmsTranslationRevision::count());
            $this->assertSame(0, AuditLog::count());
        } finally {
            unlink($file);
        }
    }

    private function runCommand(string $file, string $sha, array $options = []): int
    {
        $args = ['--file' => $file, '--sha256' => $sha, '--json' => true] + $options;
        if (! ($options['--execute'] ?? false)) {
            $args['--dry-run'] = true;
        }

        return Artisan::call('translation:import-help6-prepared-draft', $args);
    }

    private function package(ContentPage $source, ContentPage $target, string $cohort = 'help6'): array
    {
        $package = ['schema' => 'fermat_'.$cohort.'_translation_draft_v1',
            'source' => ['id' => (int) $source->id, 'revision_id' => (int) $source->published_revision_id, 'snapshot_hash' => ContentPageSourceTargetSnapshot::hash($source)],
            'target' => ['id' => (int) $target->id, 'working_revision_id' => (int) $target->working_revision_id,
                'published_revision_id' => (int) $target->published_revision_id, 'snapshot_hash' => ContentPageSourceTargetSnapshot::hash($target)],
            'copy' => ['title' => 'About FermatMind', 'summary' => 'How to use the website.', 'content_md' => "## About\n\nEnglish copy.\n\n### Contact\n\nRead the guide.",
                'seo_title' => 'About FermatMind', 'seo_description' => 'Read about FermatMind and find the help pages.']];
        $file = tempnam(sys_get_temp_dir(), 'help6-draft-test-');
        file_put_contents($file, json_encode($package, JSON_THROW_ON_ERROR));
        $sha = hash_file('sha256', $file);

        $label = match ($cohort) {
            'core3' => 'Core3', 'help-service' => 'HelpService', default => 'Help6'
        };

        return [$file, $sha, sprintf('Import %s target %d with package %s.', $label, $target->id, $sha)];
    }

    private function pair(string $slug = 'help-about'): array
    {
        $source = ContentPage::create([
            'org_id' => 0, 'slug' => $slug, 'path' => '/help/about', 'kind' => 'help', 'page_type' => 'support_static',
            'title' => '中文帮助', 'summary' => '中文摘要', 'content_md' => '## 关于', 'content_html' => '<p>中文</p>',
            'template' => 'help', 'animation_profile' => 'none', 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
            'translation_group_id' => 'content-page-'.$slug, 'translation_status' => 'source',
            'seo_title' => '中文标题', 'seo_description' => '中文描述', 'status' => 'published', 'review_state' => 'approved',
            'is_public' => true, 'is_indexable' => false, 'published_at' => now(), 'headings_json' => ['关于'],
            'faq_items' => [], 'forbidden_claims' => [], 'schema_enabled' => false, 'publish_allowed' => true,
            'operator_approval_required' => true, 'faq_schema_eligible' => false, 'legal_review_required' => false,
            'science_review_required' => false, 'claim_gate_status' => 'not_applicable',
        ]);
        $sourceRevision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $source);
        $sourceRevision->forceFill(['revision_status' => 'source'])->saveQuietly();
        $source->refresh();
        $target = $source->replicate();
        $target->unsetRelations();
        $target->forceFill(['locale' => 'en', 'source_locale' => 'zh-CN', 'source_content_id' => (int) $source->id,
            'title' => 'Old title', 'summary' => 'Old summary', 'content_md' => '## Old body', 'content_html' => '<p>Old HTML</p>',
            'seo_title' => 'Old SEO', 'seo_description' => 'Old description', 'translation_status' => 'published',
            'translated_from_version_hash' => str_repeat('a', 64), 'working_revision_id' => null, 'published_revision_id' => null]);
        $target->save();
        $published = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $target);
        $target->refresh();
        $attributes = $published->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $working = CmsTranslationRevision::create(array_replace($attributes, ['revision_number' => 7, 'revision_status' => 'draft',
            'payload_json' => app(ContentPageTranslationAdapter::class)->snapshotPayload($target), 'supersedes_revision_id' => $published->id,
            'reviewed_at' => null, 'approved_at' => null, 'published_at' => null]));
        $target->forceFill(['working_revision_id' => $working->id, 'translation_status' => 'draft'])->saveQuietly();

        return [$source->fresh(), $target->fresh(), $published->fresh(), $working->fresh()];
    }
}
