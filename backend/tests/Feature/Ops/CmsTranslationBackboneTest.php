<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Filament\Ops\Resources\ContentPageResource\Pages\EditContentPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportArticle;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\Cms\DisabledCmsMachineTranslationProvider;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\Cms\SiblingTranslationWorkflowService;
use App\Services\Ops\CmsTranslationOpsService;
use App\Support\CanonicalTranslationPayloadHash;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class CmsTranslationBackboneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_translation_payload_hash_survives_database_json_object_key_reordering(): void
    {
        $beforeStorage = ['body_md' => 'text', 'nested' => ['z' => true, 'a' => ['y' => 2, 'x' => 1]]];
        $afterStorage = ['nested' => ['a' => ['x' => 1, 'y' => 2], 'z' => true], 'body_md' => 'text'];

        $this->assertSame(CanonicalTranslationPayloadHash::hash($beforeStorage), CanonicalTranslationPayloadHash::hash($afterStorage));
        $this->assertNotSame(CanonicalTranslationPayloadHash::hash($beforeStorage), CanonicalTranslationPayloadHash::hash([
            'body_md' => 'text', 'nested' => ['z' => true, 'a' => ['y' => '2', 'x' => 1]],
        ]));
    }

    public function test_unified_translation_ops_page_lists_multiple_content_types(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $selectedOrg = $this->createOrganization($admin);

        $this->createPublishedArticleGroup('ops-article');
        $sourceSupport = $this->createSourceSupportArticle('support-faq');
        $this->createSourceInterpretationGuide('guide-reading');
        $this->createSourceContentPage('company-charter', '/charter');

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->assertSee('Unified Translation Ops Console')
            ->assertSee('Support Articles')
            ->assertSee('Interpretation Guides')
            ->assertSee('Content Pages')
            ->assertSee(__('ops.translation_ops.authority_label'))
            ->assertSet('metrics.translation_groups', 4)
            ->assertSet('metrics.missing_translation_count', 3)
            ->assertSet('metrics.published_target_coverage_rate', 25)
            ->assertSet('summaryCards.1.value', '3')
            ->set('contentTypeFilter', 'support_article')
            ->assertSee('support-faq')
            ->assertDontSee('ops-article')
            ->call('createTranslationDraft', 'support_article', (int) $sourceSupport->id, 'en')
            ->assertForbidden();
    }

    public function test_dashboard_and_page_reads_do_not_create_shadow_revisions_or_change_pointers(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $organization = $this->createOrganization($admin);
        $this->createPublishedArticleGroup('readonly-article');
        $sources = [
            'content_page' => $this->createSourceContentPage('readonly-source', '/readonly-source'),
            'support_article' => $this->createSourceSupportArticle('readonly-support'),
            'interpretation_guide' => $this->createSourceInterpretationGuide('readonly-guide'),
        ];
        $targets = [];
        foreach ($sources as $contentType => $source) {
            $targets[$contentType] = $this->createTargetTranslation($contentType, $source, 'en');
        }

        $this->assertDatabaseCount('cms_translation_revisions', 0);
        $articleRevisionCount = ArticleTranslationRevision::query()->count();
        foreach ($targets as $target) {
            $this->assertNull($target->working_revision_id);
            $this->assertNull($target->published_revision_id);
        }

        $this->withSession($this->opsSession($admin, $organization))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $first = app(CmsTranslationOpsService::class)->dashboard();
        $second = app(CmsTranslationOpsService::class)->dashboard();
        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->set('contentTypeFilter', 'content_page')
            ->set('slugSearch', 'readonly-source')
            ->call('inspectGroup', (string) $sources['content_page']->translation_group_id)
            ->call('resetFilters');
        foreach ($targets as $contentType => $target) {
            app(SiblingTranslationWorkflowService::class)->preflight($contentType, $target);
            app(SiblingTranslationWorkflowService::class)->adapter($contentType)->snapshotPayload($target);
        }
        $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
            static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
        )->values()->all();
        DB::connection()->disableQueryLog();

        $this->assertSame([], $writes);
        $this->assertSame($first['coverage_matrix'], $second['coverage_matrix']);
        $this->assertDatabaseCount('cms_translation_revisions', 0);
        $this->assertSame($articleRevisionCount, ArticleTranslationRevision::query()->count());
        foreach ($sources as $source) {
            $this->assertNull($source->fresh()->working_revision_id);
        }
        foreach ($targets as $target) {
            $this->assertNull($target->fresh()->working_revision_id);
            $this->assertNull($target->fresh()->published_revision_id);
        }

        foreach (['content_page', 'support_article', 'interpretation_guide'] as $contentType) {
            $group = collect($first['groups'])->firstWhere('content_type', $contentType);
            $targetLocale = collect($group['locales'])->firstWhere('locale', 'en');
            $this->assertFalse($targetLocale['preflight']['ok']);
            $this->assertContains('working revision missing', $targetLocale['preflight']['blockers']);
        }
    }

    public function test_preflight_ok_matches_payload_blockers(): void
    {
        $source = $this->createSourceContentPage('payload-blocker', '/payload-blocker');
        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $revision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $target);
        $payload = $revision->payload_json;
        unset($payload['seo_description']);
        $revision->forceFill(['payload_json' => $payload])->save();

        $preflight = app(SiblingTranslationWorkflowService::class)->preflight('content_page', $target->fresh());

        $this->assertFalse($preflight['ok']);
        $this->assertContains('seo description missing', $preflight['blockers']);
        $this->assertSame($preflight['ok'], $preflight['blockers'] === []);
        $this->assertSame((int) $revision->id, (int) $target->fresh()->working_revision_id);
    }

    public function test_legacy_content_page_payload_fork_keeps_published_revision_immutable(): void
    {
        $source = $this->createSourceContentPage('legacy-payload-fork', '/legacy-payload-fork');
        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $target->forceFill([
            'status' => ContentPage::STATUS_PUBLISHED,
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'legal_review_required' => false,
            'science_review_required' => false,
            'headings_json' => [],
            'faq_items' => [],
            'forbidden_claims' => [],
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'faq_schema_eligible' => false,
            'claim_gate_status' => 'not_reviewed',
            'published_at' => now(),
        ])->saveQuietly();
        $target->refresh();
        $revision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $target);
        $oldPayload = $revision->payload_json;
        unset($oldPayload['body_md'], $oldPayload['seo_description']);
        $oldPayload['seo_title'] = 'Old published SEO title';
        $legacyGroup = 'content_page-'.$source->id;
        $revision->forceFill([
            'payload_json' => $oldPayload,
            'translation_group_id' => $legacyGroup,
        ])->saveQuietly();
        $revision->refresh();
        $target->refresh();
        $adapter = app(SiblingTranslationWorkflowService::class)->adapter('content_page');
        $rowPayload = $adapter->snapshotPayload($target);
        $rowPayload['body_html'] = $target->getRawOriginal('content_html');
        $hash = CanonicalTranslationPayloadHash::hash(...);
        $options = [
            '--source-id' => (int) $source->id,
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $source->translation_group_id,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            '--revision-id' => (int) $revision->id,
            '--revision-updated-at' => (string) $revision->getRawOriginal('updated_at'),
            '--revision-payload-hash' => $hash($oldPayload),
            '--row-payload-hash' => $hash($rowPayload),
            '--json' => true,
        ];

        $revision->forceFill(['translation_group_id' => 'unrelated-group'])->saveQuietly();
        $options['--revision-updated-at'] = (string) $revision->fresh()->getRawOriginal('updated_at');
        $this->assertSame(1, Artisan::call('translation:fork-content-page-payload', $options + ['--dry-run' => true]));
        $this->assertContains('revision_lock_or_identity_mismatch', json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['errors']);

        $revision->forceFill(['translation_group_id' => $legacyGroup, 'source_content_id' => null])->saveQuietly();
        $options['--revision-updated-at'] = (string) $revision->fresh()->getRawOriginal('updated_at');
        $this->assertSame(1, Artisan::call('translation:fork-content-page-payload', $options + ['--dry-run' => true]));
        $this->assertContains('revision_lock_or_identity_mismatch', json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['errors']);

        $revision->forceFill(['source_content_id' => (int) $source->id])->saveQuietly();
        $revision->refresh();
        $options['--revision-updated-at'] = (string) $revision->getRawOriginal('updated_at');

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertSame(0, Artisan::call('translation:fork-content-page-payload', $options + ['--dry-run' => true]));
        $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
            static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
        )->values()->all();
        DB::connection()->disableQueryLog();
        $this->assertSame([], $writes);
        $this->assertSame((int) $revision->id, (int) $target->fresh()->working_revision_id);
        $this->assertDatabaseCount('cms_translation_revisions', 1);

        $this->assertSame(0, Artisan::call('translation:fork-content-page-payload', $options + [
            '--execute' => true,
            '--confirm' => sprintf('Fork content page target %d payload in group %s.', $target->id, $source->translation_group_id),
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        $this->assertSame((int) $revision->id, (int) $result['after']['published_revision_id']);
        $this->assertNotSame((int) $revision->id, (int) $result['after']['working_revision_id']);
        $this->assertSame('draft', $result['after']['working_status']);
        $this->assertContains('seo_title', $result['after']['row_vs_published_payload_conflict_keys']);
        $this->assertSame($oldPayload, $revision->fresh()->payload_json);
        $this->assertSame('published', $revision->fresh()->revision_status);
        $this->assertSame('EN body', $target->fresh()->content_md);
        $this->assertSame('published', $target->fresh()->status);
        $this->assertDatabaseCount('cms_translation_revisions', 2);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'content_page_payload_draft_forked')->count());

        $this->assertSame(1, Artisan::call('translation:fork-content-page-payload', $options + ['--dry-run' => true]));
        $this->assertDatabaseCount('cms_translation_revisions', 2);

        $target->refresh();
        $draft = CmsTranslationRevision::query()->findOrFail((int) $target->working_revision_id);
        $restoreOptions = [
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $target->translation_group_id,
            '--source-id' => (int) $source->id,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            '--published-revision-id' => (int) $revision->id,
            '--published-revision-updated-at' => (string) $revision->getRawOriginal('updated_at'),
            '--published-payload-hash' => $hash($oldPayload),
            '--draft-revision-id' => (int) $draft->id,
            '--draft-revision-updated-at' => (string) $draft->getRawOriginal('updated_at'),
            '--draft-payload-hash' => $hash($draft->payload_json),
            '--fork-audit-id' => (int) $result['after']['audit_id'],
            '--json' => true,
        ];
        $this->assertSame(1, Artisan::call('translation:restore-content-page-payload-fork', array_replace(
            $restoreOptions,
            ['--draft-payload-hash' => 'wrong', '--dry-run' => true]
        )));
        $this->assertSame((int) $draft->id, (int) $target->fresh()->working_revision_id);
        $this->assertSame(0, Artisan::call('translation:restore-content-page-payload-fork', $restoreOptions + ['--dry-run' => true]));
        $this->assertSame(0, Artisan::call('translation:restore-content-page-payload-fork', $restoreOptions + [
            '--execute' => true,
            '--confirm' => sprintf('Restore content page target %d payload fork %d.', $target->id, $draft->id),
        ]));
        $this->assertSame((int) $revision->id, (int) $target->fresh()->working_revision_id);
        $this->assertSame((int) $revision->id, (int) $target->fresh()->published_revision_id);
        $this->assertSame('archived', $draft->fresh()->revision_status);
        $this->assertSame($oldPayload, $revision->fresh()->payload_json);
        $this->assertSame('published', $target->fresh()->status);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'content_page_payload_draft_fork_restored')->count());
        $this->assertDatabaseCount('cms_translation_revisions', 2);
    }

    public function test_legacy_payload_fork_rejects_null_raw_arrays(): void
    {
        $source = $this->createSourceContentPage('legacy-null-payload', '/legacy-null-payload');
        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $target->forceFill([
            'status' => ContentPage::STATUS_PUBLISHED,
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now(),
        ])->saveQuietly();
        $target->refresh();
        $revision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $target);
        $revision->forceFill(['payload_json' => ['title' => 'EN page']])->saveQuietly();
        $target->refresh();

        $exit = Artisan::call('translation:fork-content-page-payload', [
            '--source-id' => (int) $source->id,
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $source->translation_group_id,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            '--revision-id' => (int) $revision->id,
            '--revision-updated-at' => (string) $revision->getRawOriginal('updated_at'),
            '--revision-payload-hash' => CanonicalTranslationPayloadHash::hash($revision->fresh()->payload_json),
            '--row-payload-hash' => 'wrong',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('row_field_missing:faq_items', $result['errors']);
        $this->assertContains('row_field_missing:forbidden_claims', $result['errors']);
        $this->assertDatabaseCount('cms_translation_revisions', 1);
    }

    public function test_editing_an_unpublished_translation_draft_preserves_the_published_content_page(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $organization = $this->createOrganization($admin);
        $source = $this->createSourceContentPage('published-target-draft-edit', '/published-target-draft-edit');
        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $target->forceFill([
            'status' => ContentPage::STATUS_PUBLISHED,
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'headings_json' => [],
            'faq_items' => [],
            'forbidden_claims' => [],
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'faq_schema_eligible' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'published_at' => now(),
        ])->saveQuietly();
        $target->refresh();
        $published = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $target);
        $target->refresh();
        $adapter = app(SiblingTranslationWorkflowService::class)->adapter('content_page');
        $payload = $adapter->snapshotPayload($target);
        $payload['body_html'] = $target->getRawOriginal('content_html');
        $payload['support_contact'] = 'draft@example.com';
        $payload['policy_version'] = 'draft-policy';
        $payload['reviewer'] = 'Draft Reviewer';
        $payload['faq_items'] = [['question' => 'Draft question?', 'answer' => 'Draft answer.']];
        $payload['schema_enabled'] = true;
        $drafted = app(RowBackedRevisionWorkspace::class)->saveWorkingDraft(
            'content_page', $target, $payload, CmsTranslationRevision::STATUS_DRAFT
        );
        $draftId = (int) $drafted->working_revision_id;
        $this->assertNotSame((int) $published->id, $draftId);

        $this->withSession($this->opsSession($admin, $organization))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
        Livewire::test(EditContentPage::class, ['record' => $target->id])
            ->fillForm(['content_md' => 'Updated English working draft'])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $target->fresh();
        $this->assertSame(ContentPage::STATUS_PUBLISHED, $after->status);
        $this->assertTrue((bool) $after->is_public);
        $this->assertSame('EN body', $after->content_md);
        $this->assertSame((int) $published->id, (int) $after->published_revision_id);
        $this->assertSame($draftId, (int) $after->working_revision_id);
        $savedPayload = CmsTranslationRevision::query()->findOrFail($draftId)->payload_json;
        $this->assertSame('Updated English working draft', $savedPayload['body_md']);
        $this->assertSame('draft@example.com', $savedPayload['support_contact']);
        $this->assertSame('draft-policy', $savedPayload['policy_version']);
        $this->assertSame('Draft Reviewer', $savedPayload['reviewer']);
        $this->assertSame([['question' => 'Draft question?', 'answer' => 'Draft answer.']], $savedPayload['faq_items']);
        $this->assertTrue($savedPayload['schema_enabled']);
        $this->assertNull($after->support_contact);
        $this->assertFalse((bool) $after->schema_enabled);
        $this->assertSame(CmsTranslationRevision::STATUS_PUBLISHED, $published->fresh()->revision_status);

        Livewire::test(EditContentPage::class, ['record' => $target->id])
            ->fillForm(['status' => ContentPage::STATUS_DRAFT])
            ->call('save')
            ->assertHasErrors(['status']);
        $this->assertSame(ContentPage::STATUS_PUBLISHED, $target->fresh()->status);
        $this->assertSame($savedPayload, CmsTranslationRevision::query()->findOrFail($draftId)->payload_json);

        CmsTranslationRevision::query()->findOrFail($draftId)
            ->forceFill(['translation_group_id' => 'unrelated-group'])
            ->saveQuietly();
        Livewire::test(EditContentPage::class, ['record' => $target->id])
            ->fillForm(['content_md' => 'Should not save'])
            ->call('save')
            ->assertHasErrors(['status']);
        $this->assertSame($savedPayload, CmsTranslationRevision::query()->findOrFail($draftId)->payload_json);
    }

    public function test_reviewed_public_content_page_source_can_be_reconciled_without_changing_target_or_old_revision(): void
    {
        $source = $this->createSourceContentPage('legacy-source-reconcile', '/legacy-source-reconcile');
        $source->forceFill([
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'template' => 'company',
            'animation_profile' => 'none',
            'claim_gate_status' => 'not_reviewed',
            'headings_json' => [],
            'faq_items' => [],
            'forbidden_claims' => [],
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'faq_schema_eligible' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
        ])->saveQuietly();
        $source->refresh();
        $oldRevision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $source);
        $source->forceFill(['source_version_hash' => str_repeat('a', 64)])->saveQuietly();
        $oldRevision->forceFill(['source_version_hash' => str_repeat('a', 64)])->saveQuietly();
        $source->refresh();
        $oldRevision->refresh();

        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $target->forceFill([
            'status' => ContentPage::STATUS_PUBLISHED,
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now(),
        ])->saveQuietly();
        $source->refresh();
        $target->refresh();
        $adapter = app(SiblingTranslationWorkflowService::class)->adapter('content_page');
        $payload = $adapter->snapshotPayload($source);
        $payload['body_html'] = $source->getRawOriginal('content_html');
        $hash = CanonicalTranslationPayloadHash::hash(...);
        $options = [
            '--source-id' => (int) $source->id,
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $source->translation_group_id,
            '--slug' => (string) $source->slug,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--fresh-source-hash' => $source->freshSourceVersionHash(),
            '--revision-id' => (int) $oldRevision->id,
            '--revision-hash' => (string) $oldRevision->source_version_hash,
            '--revision-payload-hash' => $hash($oldRevision->payload_json),
            '--row-payload-hash' => $hash($payload),
            '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            '--revision-updated-at' => (string) $oldRevision->getRawOriginal('updated_at'),
            '--json' => true,
        ];
        $targetBefore = $target->getAttributes();
        $oldBefore = $oldRevision->getAttributes();

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertSame(0, Artisan::call('translation:reconcile-content-page-source-version', $options + ['--dry-run' => true]));
        $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
            static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
        )->values()->all();
        DB::connection()->disableQueryLog();
        $this->assertSame([], $writes);

        $this->assertSame(0, Artisan::call('translation:reconcile-content-page-source-version', $options + [
            '--execute' => true,
            '--confirm' => sprintf('Reconcile content page source %d for target %d in group %s.',
                $source->id, $target->id, $source->translation_group_id),
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        $source->refresh();
        $this->assertSame('source', $source->translation_status);
        $this->assertSame($source->freshSourceVersionHash(), $source->source_version_hash);
        $this->assertSame((int) $source->published_revision_id, (int) $source->working_revision_id);
        $this->assertNotSame((int) $oldRevision->id, (int) $source->published_revision_id);
        $this->assertSame($targetBefore, $target->fresh()->getAttributes());
        $this->assertSame($oldBefore, $oldRevision->fresh()->getAttributes());
        $this->assertDatabaseCount('cms_translation_revisions', 2);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'content_page_source_version_reconciled')->count());
        $this->assertSame(1, Artisan::call('translation:reconcile-content-page-source-version', $options + ['--dry-run' => true]));

        $newRevision = CmsTranslationRevision::query()->findOrFail((int) $source->published_revision_id);
        $this->assertSame('source', $newRevision->revision_status);
        $restoreOptions = [
            '--source-id' => (int) $source->id,
            '--target-id' => (int) $target->id,
            '--group-id' => (string) $source->translation_group_id,
            '--slug' => (string) $source->slug,
            '--source-hash' => (string) $source->source_version_hash,
            '--target-hash' => (string) $target->source_version_hash,
            '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            '--old-revision-id' => (int) $oldRevision->id,
            '--new-revision-id' => (int) $newRevision->id,
            '--new-revision-updated-at' => (string) $newRevision->getRawOriginal('updated_at'),
            '--audit-id' => (int) $result['after']['audit_id'],
            '--json' => true,
        ];
        $this->assertSame(1, Artisan::call('translation:restore-content-page-source-version', array_replace(
            $restoreOptions, ['--target-hash' => 'wrong', '--dry-run' => true]
        )));
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertSame(0, Artisan::call('translation:restore-content-page-source-version', $restoreOptions + ['--dry-run' => true]));
        $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(
            static fn (string $sql): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1
        )->values()->all();
        DB::connection()->disableQueryLog();
        $this->assertSame([], $writes);
        $this->assertSame(0, Artisan::call('translation:restore-content-page-source-version', $restoreOptions + [
            '--execute' => true,
            '--confirm' => sprintf('Restore content page source %d revision %d.', $source->id, $newRevision->id),
        ]));
        $source->refresh();
        $this->assertSame('published', $source->translation_status);
        $this->assertSame(str_repeat('a', 64), $source->source_version_hash);
        $this->assertSame((int) $oldRevision->id, (int) $source->published_revision_id);
        $this->assertSame('archived', $newRevision->fresh()->revision_status);
        $this->assertSame($targetBefore, $target->fresh()->getAttributes());
        $this->assertSame($oldBefore, $oldRevision->fresh()->getAttributes());
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'content_page_source_version_reconciliation_restored')->count());
    }

    public function test_missing_provenance_is_unverified_and_blocks_publication_for_article_and_page(): void
    {
        $this->createPublishedArticleGroup('unknown-provenance-article');
        $article = Article::query()->where('slug', 'unknown-provenance-article')->where('locale', 'en')->firstOrFail();
        $article->publishedRevision->forceFill(['translated_from_version_hash' => null])->saveQuietly();

        $source = $this->createSourceContentPage('unknown-provenance-page', '/unknown-provenance-page');
        $page = $this->createTargetTranslation('content_page', $source, 'en');
        $revision = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $page);
        $revision->forceFill(['translated_from_version_hash' => null])->saveQuietly();

        foreach ([['article', 'unknown-provenance-article'], ['content_page', 'unknown-provenance-page']] as [$type, $slug]) {
            $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => $type, 'slug' => $slug]);
            $group = $dashboard['groups'][0];
            $target = collect($group['locales'])->firstWhere('locale', 'en');
            $cell = $dashboard['coverage_matrix'][0]['cells']['en'];

            $this->assertFalse($target['is_freshness_known']);
            $this->assertFalse($target['is_stale']);
            $this->assertFalse($target['preflight']['ok']);
            $this->assertContains('translation provenance unknown', $target['preflight']['blockers']);
            $this->assertSame('Unverified', $cell['freshness_label']);
            $this->assertSame('warning', $cell['freshness_state']);
            $this->assertContains('source hash cannot be verified', $target['compare_summary']);
            $this->assertSame(0, $dashboard['metrics']['stale_translation_count']);
            $this->assertSame([], app(CmsTranslationOpsService::class)->dashboard([
                'content_type' => $type, 'slug' => $slug, 'stale' => 'current',
            ])['groups']);
        }
    }

    public function test_source_locale_is_not_counted_as_a_translation_target(): void
    {
        $source = $this->createSourceContentPage('english-source', '/english-source');
        $source->forceFill(['locale' => 'en', 'source_locale' => 'en'])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'content_page']);

        $this->assertSame(0, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(0, $dashboard['metrics']['missing_translation_count']);
        $this->assertSame(0, $dashboard['metrics']['published_target_locale_count']);
        $this->assertSame([], $dashboard['groups'][0]['coverage']['target_locales']);
        $this->assertSame('source', $dashboard['coverage_matrix'][0]['cells']['en']['state']);
    }

    public function test_duplicate_source_rows_are_not_counted_as_published_targets(): void
    {
        $source = $this->createSourceContentPage('duplicate-source', '/duplicate-source');
        $secondSource = $this->createTargetTranslation('content_page', $source, 'en');
        $secondSource->forceFill([
            'source_locale' => 'en',
            'source_content_id' => null,
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now(),
        ])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'content_page']);

        $this->assertSame(1, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(0, $dashboard['metrics']['published_target_locale_count']);
        $this->assertSame(0, $dashboard['metrics']['missing_translation_count']);
        $this->assertSame('failed', $dashboard['coverage_matrix'][0]['health_state']);
        $this->assertSame('source', $dashboard['coverage_matrix'][0]['cells']['en']['state']);
    }

    public function test_english_source_article_does_not_create_a_false_missing_translation(): void
    {
        $this->createPublishedArticleGroup('chinese-group');
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'english-source',
            'locale' => 'en',
            'source_locale' => 'en',
            'translation_group_id' => 'article-english-source',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'English source',
            'content_md' => 'English content',
            'status' => 'published',
            'is_public' => true,
        ]);

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);
        $english = collect($dashboard['coverage_matrix'])->firstWhere('translation_group_id', 'article-english-source');

        $this->assertSame(1, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(0, $dashboard['metrics']['missing_translation_count']);
        $this->assertSame('not_applicable', $english['cells']['zh-CN']['state']);
        $this->assertSame('blocked', $english['cells']['en']['state']);
    }

    public function test_fresh_working_revisions_do_not_hide_stale_published_versions(): void
    {
        $articleSlug = 'published-stale-working-fresh';
        $this->createPublishedArticleGroup($articleSlug);
        $articleSource = Article::query()->where('slug', $articleSlug)->where('locale', 'zh-CN')->firstOrFail();
        $articleTarget = Article::query()->where('slug', $articleSlug)->where('locale', 'en')->firstOrFail();
        $articleSource->workingRevision->forceFill(['source_version_hash' => 'article-source-v2'])->saveQuietly();
        $articleDraft = $articleTarget->publishedRevision->replicate();
        $articleDraft->forceFill([
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => 'article-source-v2',
            'translated_from_version_hash' => 'article-source-v2',
            'published_at' => null,
        ])->save();
        $articleTarget->forceFill(['working_revision_id' => (int) $articleDraft->id])->saveQuietly();

        $pageSource = $this->createSourceContentPage('published-stale-page', '/published-stale-page');
        $pageTarget = $this->createTargetTranslation('content_page', $pageSource, 'en');
        $pageTarget->forceFill([
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'published_at' => now(),
        ])->saveQuietly();
        $pagePublished = app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $pageTarget);
        $pageSource->forceFill(['source_version_hash' => 'page-source-v2'])->saveQuietly();
        $pageDraft = $pagePublished->replicate();
        $pageDraft->forceFill([
            'revision_number' => 2,
            'revision_status' => 'machine_draft',
            'source_version_hash' => 'page-source-v2',
            'translated_from_version_hash' => 'page-source-v2',
            'published_at' => null,
        ])->save();
        $pageTarget->forceFill(['working_revision_id' => (int) $pageDraft->id])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard();

        $this->assertSame(2, $dashboard['metrics']['stale_published_count']);
        $this->assertSame(0, $dashboard['metrics']['stale_draft_count']);
        $this->assertSame(2, $dashboard['metrics']['stale_translation_count']);
        $this->assertSame(2, $dashboard['metrics']['stale_groups']);
    }

    public function test_unpublished_source_working_revision_does_not_age_published_translation(): void
    {
        $slug = 'unpublished-source-working';
        $this->createPublishedArticleGroup($slug);
        $source = Article::query()->where('slug', $slug)->where('locale', 'zh-CN')->firstOrFail();
        $sourceDraft = $source->publishedRevision->replicate();
        $sourceDraft->forceFill([
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => 'unpublished-source-v2',
            'published_at' => null,
        ])->saveQuietly();
        $source->forceFill(['working_revision_id' => (int) $sourceDraft->id])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);
        $english = collect($dashboard['groups'][0]['locales'])->firstWhere('locale', 'en');

        $this->assertFalse($english['is_published_stale']);
        $this->assertSame(0, $dashboard['metrics']['stale_published_count']);
    }

    public function test_article_with_source_status_and_target_lineage_is_not_treated_as_a_source(): void
    {
        $this->createPublishedArticleGroup('invalid-source-status');
        $target = Article::query()->where('slug', 'invalid-source-status')->where('locale', 'en')->firstOrFail();
        $target->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);
        $group = $dashboard['groups'][0];
        $english = collect($group['locales'])->firstWhere('locale', 'en');

        $this->assertFalse($english['is_source']);
        $this->assertFalse($english['preflight']['ok']);
        $this->assertContains('target source status conflicts with lineage', $english['preflight']['blockers']);
        $this->assertSame(1, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(1, $dashboard['metrics']['published_target_locale_count']);
        $this->assertSame('failed', $dashboard['coverage_matrix'][0]['health_state']);
    }

    public function test_legacy_article_root_is_diagnostic_source_but_remains_blocked_and_exposes_stale_target(): void
    {
        $slug = 'legacy-source-status';
        $this->createPublishedArticleGroup($slug);
        $source = Article::query()->where('slug', $slug)->where('locale', 'zh-CN')->firstOrFail();
        $source->forceFill(['translation_status' => Article::TRANSLATION_STATUS_APPROVED])->saveQuietly();
        $source->workingRevision->forceFill(['source_version_hash' => 'new-source-hash'])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);
        $group = $dashboard['groups'][0];
        $sourceLocale = collect($group['locales'])->firstWhere('locale', 'zh-CN');
        $targetLocale = collect($group['locales'])->firstWhere('locale', 'en');

        $this->assertSame((int) $source->id, $group['source_record_id']);
        $this->assertFalse($group['canonical_ok']);
        $this->assertTrue($sourceLocale['is_source']);
        $this->assertFalse($sourceLocale['preflight']['ok']);
        $this->assertSame('blocked', $dashboard['coverage_matrix'][0]['cells']['zh-CN']['state']);
        $this->assertTrue($targetLocale['is_published_stale']);
        $this->assertSame(1, $dashboard['metrics']['stale_published_count']);
        $this->assertFalse($group['group_actions'][1]['enabled']);
    }

    public function test_legacy_content_page_root_is_diagnostic_source_without_becoming_a_translation_target(): void
    {
        $source = $this->createSourceContentPage('legacy-page-source', '/legacy-page-source');
        $target = $this->createTargetTranslation('content_page', $source, 'en');
        $source->forceFill(['translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED])->saveQuietly();
        $target->forceFill([
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now(),
        ])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'content_page']);
        $group = $dashboard['groups'][0];
        $sourceLocale = collect($group['locales'])->firstWhere('locale', 'zh-CN');

        $this->assertSame((int) $source->id, $group['source_record_id']);
        $this->assertFalse($group['canonical_ok']);
        $this->assertTrue($sourceLocale['is_source']);
        $this->assertFalse($sourceLocale['preflight']['ok']);
        $this->assertSame('blocked', $dashboard['coverage_matrix'][0]['cells']['zh-CN']['state']);
        $this->assertSame(1, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(1, $dashboard['metrics']['published_target_locale_count']);
    }

    public function test_unified_matrix_uses_article_target_locale_configuration(): void
    {
        config()->set('services.article_translation.target_locales', ['en', 'ja']);
        config()->set('services.cms_translation.target_locales', ['en']);
        $this->createPublishedArticleGroup('article-ja-target');

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);

        $this->assertContains('ja', $dashboard['locale_columns']);
        $this->assertContains('ja', $dashboard['filter_options']['locales']);
        $this->assertSame('missing', $dashboard['coverage_matrix'][0]['cells']['ja']['state']);
        $this->assertSame(2, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(1, $dashboard['metrics']['missing_translation_count']);
    }

    public function test_row_backed_translation_workflow_publishes_with_invalidation_signals(): void
    {
        $owner = $this->createAdminWithPermissions([PermissionNames::ADMIN_APPROVAL_REVIEW]);
        config()->set('review_governance.solo_owner_admin_user_id', (int) $owner->id);
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));

        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/invalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        config()->set('ops.content_release_observability.broadcast_webhook', '');

        Http::fake([
            'https://cache.example.test/invalidate' => Http::response(['ok' => true], 202),
        ]);

        $workflow = app(SiblingTranslationWorkflowService::class);

        foreach (['support_article', 'interpretation_guide', 'content_page'] as $contentType) {
            $source = match ($contentType) {
                'support_article' => $this->createSourceSupportArticle('support-'.$contentType),
                'interpretation_guide' => $this->createSourceInterpretationGuide('guide-'.$contentType),
                'content_page' => $this->createSourceContentPage('page-'.$contentType, '/'.$contentType),
            };

            $target = $this->createTargetTranslation($contentType, $source, 'en');

            $workflow->promoteToHumanReview($contentType, $target);
            $workflow->approveTranslation($contentType, $target->fresh());
            $published = $workflow->publishTranslation($contentType, $target->fresh());

            $this->assertSame('published', (string) $published->translation_status);
            $this->assertSame((int) $source->id, (int) $published->source_content_id);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'content_release_publish',
                'target_type' => $contentType,
                'target_id' => (string) $published->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'content_release_cache_signal',
                'target_type' => $contentType,
                'target_id' => (string) $published->id,
                'result' => 'success',
            ]);
        }

        $this->assertDatabaseCount('review_attestations', 3);
        $this->assertDatabaseCount('review_attestation_target_evidences', 6);
    }

    public function test_row_backed_translation_preflight_blocks_stale_publish(): void
    {
        $workflow = app(SiblingTranslationWorkflowService::class);
        $source = $this->createSourceSupportArticle('stale-support');
        $target = $this->createTargetTranslation('support_article', $source, 'en');

        $source->forceFill([
            'title' => 'Updated source title',
        ])->save();
        $target->forceFill([
            'translation_status' => SupportArticle::TRANSLATION_STATUS_APPROVED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
        ])->save();

        $this->expectException(CmsTranslationWorkflowException::class);
        $this->expectExceptionMessage('Translation publish preflight failed.');

        $workflow->publishTranslation('support_article', $target->fresh());
    }

    public function test_unified_translation_ops_localizes_blockers_and_provider_reasons(): void
    {
        app()->setLocale('zh_CN');
        config()->set('services.cms_translation.providers.support_article', DisabledCmsMachineTranslationProvider::class);

        $source = $this->createSourceSupportArticle('localized-blockers');
        $target = $this->createTargetTranslation('support_article', $source, 'en');
        $target->forceFill([
            'body_md' => '',
            'body_html' => '',
            'seo_description' => '',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_APPROVED,
        ])->save();

        $missingSource = $this->createSourceSupportArticle('localized-provider-reason');

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'support_article']);
        $blockedGroup = collect($dashboard['groups'])->firstWhere('translation_group_id', $source->translation_group_id);
        $this->assertIsArray($blockedGroup);
        $targetLocale = collect($blockedGroup['locales'])->firstWhere('locale', 'en');
        $this->assertIsArray($targetLocale);

        $this->assertContains('缺少正文', $targetLocale['preflight']['blockers']);
        $this->assertContains('缺少 SEO 描述', $targetLocale['preflight']['blockers']);

        $missingGroup = collect($dashboard['groups'])->firstWhere('translation_group_id', $missingSource->translation_group_id);
        $this->assertIsArray($missingGroup);
        $disabledReasons = collect($missingGroup['group_actions'])->pluck('reason')->filter()->values()->all();

        $this->assertTrue(
            collect($disabledReasons)->contains(fn (string $reason): bool => str_contains($reason, '尚未配置机器翻译 provider')),
            'Expected at least one disabled reason to be localized for the missing provider.',
        );
        $this->assertFalse(
            collect($disabledReasons)->contains(fn (string $reason): bool => str_contains($reason, 'Machine translation provider is not configured')),
            'Provider-disabled reason should not leak the raw English provider message in zh-CN.',
        );
        $this->assertNotContains('body missing', $targetLocale['preflight']['blockers']);
        $this->assertNotContains('seo description missing', $targetLocale['preflight']['blockers']);
    }

    public function test_unified_translation_ops_keeps_provider_reasons_natural_in_english(): void
    {
        app()->setLocale('en');
        config()->set('services.cms_translation.providers.support_article', DisabledCmsMachineTranslationProvider::class);

        $missingSource = $this->createSourceSupportArticle('english-provider-reason');

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'support_article']);
        $missingGroup = collect($dashboard['groups'])->firstWhere('translation_group_id', $missingSource->translation_group_id);
        $this->assertIsArray($missingGroup);
        $disabledReasons = collect($missingGroup['group_actions'])->pluck('reason')->filter()->values()->all();

        $this->assertTrue(
            collect($disabledReasons)->contains(fn (string $reason): bool => str_contains($reason, 'Machine translation provider is not configured for Support Articles')),
            'Expected the provider-disabled reason to remain natural English in en mode.',
        );
    }

    public function test_unified_translation_ops_coverage_rate_counts_only_target_locales(): void
    {
        $this->createPublishedArticleGroup('coverage-rate-fixture');

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'article']);

        $this->assertSame(1, $dashboard['metrics']['translation_groups']);
        $this->assertSame(1, $dashboard['metrics']['published_target_locale_count']);
        $this->assertSame(1, $dashboard['metrics']['target_slot_count']);
        $this->assertSame(100, $dashboard['metrics']['published_target_coverage_rate']);
        $this->assertSame('100%', $dashboard['summary_cards'][0]['value']);
    }

    public function test_unified_translation_ops_keeps_missing_published_revision_out_of_ownership_mismatch(): void
    {
        $this->createPublishedArticleGroup('missing-published-pointer-fixture');
        $translation = Article::query()
            ->where('translation_group_id', 'article-missing-published-pointer-fixture')
            ->where('locale', 'en')
            ->firstOrFail();

        $translation->forceFill([
            'published_revision_id' => null,
        ])->saveQuietly();

        $dashboard = app(CmsTranslationOpsService::class)->dashboard([
            'content_type' => 'article',
            'slug' => 'missing-published-pointer-fixture',
        ]);

        $this->assertSame(0, $dashboard['metrics']['ownership_mismatch_groups']);
        $this->assertSame('failed', $dashboard['coverage_matrix'][0]['health_state']);

        $cell = data_get($dashboard, 'coverage_matrix.0.cells.en');
        $this->assertIsArray($cell);
        $this->assertSame([], $cell['ownership_blockers']);
        $this->assertNotEmpty($cell['readiness_blockers']);
    }

    private function createPublishedArticleGroup(string $slug): void
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文 '.$slug,
            'excerpt' => '摘要',
            'content_md' => "## 参考文献\n\n- [1] source citation",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $sourceRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => 'source-'.$slug,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => $source->title,
            'excerpt' => $source->excerpt,
            'content_md' => $source->content_md,
        ]);
        $source->forceFill([
            'working_revision_id' => (int) $sourceRevision->id,
            'published_revision_id' => (int) $sourceRevision->id,
            'source_version_hash' => 'source-'.$slug,
        ])->saveQuietly();

        $translation = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'en',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => 'EN '.$slug,
            'excerpt' => 'excerpt',
            'content_md' => "## References\n\n- [1] source citation",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $translationRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $translation->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => 'source-'.$slug,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => $translation->title,
            'excerpt' => $translation->excerpt,
            'content_md' => $translation->content_md,
        ]);
        $translation->forceFill([
            'working_revision_id' => (int) $translationRevision->id,
            'published_revision_id' => (int) $translationRevision->id,
            'source_version_hash' => 'source-'.$slug,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'locale' => 'en',
            'seo_title' => 'SEO '.$slug,
            'seo_description' => 'SEO description',
            'is_indexable' => true,
        ]);
    }

    private function createSourceSupportArticle(string $slug): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 support',
            'summary' => 'support summary',
            'body_md' => 'support body',
            'body_html' => '<p>support body</p>',
            'support_category' => SupportArticle::CATEGORIES[0],
            'support_intent' => SupportArticle::INTENTS[0],
            'locale' => 'zh-CN',
            'translation_group_id' => 'support-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'support seo',
            'seo_description' => 'support seo description',
            'canonical_path' => '/support/articles/'.$slug,
        ]);
    }

    private function createSourceInterpretationGuide(string $slug): InterpretationGuide
    {
        return InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 guide',
            'summary' => 'guide summary',
            'body_md' => 'guide body',
            'body_html' => '<p>guide body</p>',
            'test_family' => InterpretationGuide::TEST_FAMILIES[0],
            'result_context' => InterpretationGuide::RESULT_CONTEXTS[0],
            'audience' => 'general',
            'locale' => 'zh-CN',
            'translation_group_id' => 'interpretation-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => InterpretationGuide::TRANSLATION_STATUS_SOURCE,
            'status' => InterpretationGuide::STATUS_PUBLISHED,
            'review_state' => InterpretationGuide::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'guide seo',
            'seo_description' => 'guide seo description',
            'canonical_path' => '/support/guides/'.$slug,
        ]);
    }

    private function createSourceContentPage(string $slug, string $path): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => $path,
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => '中文 page',
            'summary' => 'page summary',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'translation_group_id' => 'content-page-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'content_md' => 'page body',
            'content_html' => '<p>page body</p>',
            'seo_title' => 'page seo',
            'seo_description' => 'page seo description',
            'meta_description' => 'page meta',
            'canonical_path' => $path,
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
    }

    private function createTargetTranslation(string $contentType, object $source, string $targetLocale): object
    {
        return match ($contentType) {
            'support_article' => SupportArticle::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'title' => 'EN support',
                'summary' => 'EN summary',
                'body_md' => 'EN body',
                'body_html' => '<p>EN body</p>',
                'support_category' => $source->support_category,
                'support_intent' => $source->support_intent,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => SupportArticle::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'status' => SupportArticle::STATUS_DRAFT,
                'review_state' => SupportArticle::REVIEW_DRAFT,
                'seo_title' => 'EN support seo',
                'seo_description' => 'EN support seo description',
                'canonical_path' => '/support/articles/'.$source->slug,
            ]),
            'interpretation_guide' => InterpretationGuide::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'title' => 'EN guide',
                'summary' => 'EN summary',
                'body_md' => 'EN body',
                'body_html' => '<p>EN body</p>',
                'test_family' => $source->test_family,
                'result_context' => $source->result_context,
                'audience' => $source->audience,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => InterpretationGuide::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'status' => InterpretationGuide::STATUS_DRAFT,
                'review_state' => InterpretationGuide::REVIEW_DRAFT,
                'seo_title' => 'EN guide seo',
                'seo_description' => 'EN guide seo description',
                'canonical_path' => '/support/guides/'.$source->slug,
            ]),
            'content_page' => ContentPage::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'path' => $source->path,
                'kind' => $source->kind,
                'page_type' => $source->page_type,
                'title' => 'EN page',
                'summary' => 'EN summary',
                'template' => $source->template,
                'animation_profile' => $source->animation_profile,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'content_md' => 'EN body',
                'content_html' => '<p>EN body</p>',
                'seo_title' => 'EN page seo',
                'seo_description' => 'EN page seo description',
                'meta_description' => 'EN meta',
                'canonical_path' => $source->path,
                'status' => ContentPage::STATUS_DRAFT,
                'review_state' => 'draft',
                'is_public' => false,
                'is_indexable' => true,
            ]),
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'Ops Tester '.uniqid('', true),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin = AdminUser::query()->create([
            'name' => 'Ops Tester',
            'email' => 'ops-tester-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
        ]);
        $admin->roles()->sync([$role->id]);

        return $admin;
    }

    private function createOrganization(AdminUser $admin): Organization
    {
        return Organization::query()->create([
            'name' => 'CMS Translation Org '.uniqid(),
            'slug' => 'cms-translation-org-'.uniqid(),
            'owner_user_id' => (int) $admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(AdminUser $admin, Organization $organization): array
    {
        return [
            'ops_org_id' => (int) $organization->id,
            'ops_org_name' => (string) $organization->name,
            'ops_org_slug' => (string) $organization->slug,
            'ops_actor_admin_id' => (int) $admin->id,
        ];
    }
}
