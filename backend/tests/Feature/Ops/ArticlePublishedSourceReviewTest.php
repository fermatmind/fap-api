<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\EditorialReviewPage;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\EditorialReview;
use App\Models\Permission;
use App\Models\ReviewAttestation;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticlePublishedSourceReviewService;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use App\Services\Ops\ArticleTranslationOpsService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticlePublishedSourceReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_preserves_both_publications_and_historical_provenance_and_binds_one_complete_pair(): void
    {
        [$source, $target] = $this->fixture();
        $before = $this->rows($source, $target);
        $service = app(ArticlePublishedSourceReviewService::class);
        $this->assertTrue(app(ArticleTranslationRevisionWorkspace::class)->isWorkingRevisionStale($target));
        $this->assertTrue($target->isTranslationStale());
        $input = $service->prepare($target);
        $context = json_decode($input['review_context'], true);
        $this->assertStringContainsString('revision='.$source->published_revision_id.'&', $context['source_preview']);
        $this->assertStringContainsString('revision='.$target->published_revision_id.'&', $context['target_preview']);
        $service->confirm($target, [...$input, 'source_reviewed' => true]);
        $this->assertSame($before, $this->rows($source, $target));
        $this->assertTrue($service->hasCurrentReview($target));
        $this->assertFalse(app(ArticleTranslationRevisionWorkspace::class)->isWorkingRevisionStale($target));
        $this->assertFalse($target->isTranslationStale());
        $record = ReviewAttestation::query()->sole();
        $this->assertSame(4, $record->targetEvidences()->where('target_decision', 'approved')->count());
        $this->assertSame('cms_article_source_review', $record->scope_type);
        $this->assertSame($input['pair_hash'], $record->package_sha256);
        $this->assertDatabaseCount('article_translation_revisions', 3);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertDatabaseCount('editorial_reviews', 0);
        $this->assertSame(str_repeat('a', 64), $target->fresh('publishedRevision')->publishedRevision->translated_from_version_hash);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('drifts')]
    public function test_any_pair_or_metadata_drift_invalidates_evidence_and_old_confirmation(string $case): void
    {
        [$source, $target] = $this->fixture();
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepare($target);
        $service->confirm($target, [...$input, 'source_reviewed' => true]);
        match ($case) {
            'source_article' => $source->forceFill(['excerpt' => 'changed'])->saveQuietly(),
            'target_article' => $target->forceFill(['excerpt' => 'changed'])->saveQuietly(),
            'source_revision' => $source->publishedRevision->forceFill(['approved_at' => now()->subDay()])->saveQuietly(),
            'target_revision' => $target->publishedRevision->forceFill(['approved_at' => now()->subDay()])->saveQuietly(),
            'target_history' => $target->publishedRevision->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->saveQuietly(),
            'source_seo' => ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $source->id, 'locale' => 'zh-CN', 'seo_title' => 'changed']),
            'target_seo' => $target->seoMeta->forceFill(['robots' => 'index,follow'])->saveQuietly(),
            'source_pointer' => $source->forceFill(['working_revision_id' => null])->saveQuietly(),
            'target_pointer' => $target->forceFill(['working_revision_id' => null])->saveQuietly(),
            'source_identity' => $source->forceFill(['org_id' => 2])->saveQuietly(),
            'target_identity' => $target->forceFill(['translation_group_id' => 'other'])->saveQuietly(),
            'target_future' => $target->forceFill(['published_at' => now()->addDay()])->saveQuietly(),
            'source_hash' => $source->forceFill(['source_version_hash' => str_repeat('c', 64)])->saveQuietly(),
            'target_private' => $target->forceFill(['is_public' => false])->saveQuietly(),
        };
        $this->assertFalse($service->hasCurrentReview($target));
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Changed pair must reject.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('review_attestations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function drifts(): array
    {
        return array_map(fn ($x) => [$x], ['source_article', 'target_article', 'source_revision', 'target_revision', 'target_history', 'source_seo', 'target_seo', 'source_pointer', 'target_pointer', 'source_identity', 'target_identity', 'target_future', 'source_hash', 'target_private']);
    }

    #[DataProvider('revisionIdentityMismatches')]
    public function test_mismatched_published_revision_identity_is_rejected_before_approval(string $side, string $field): void
    {
        [$source, $target] = $this->fixture();
        $article = $side === 'source' ? $source : $target;
        $other = $side === 'source' ? $target : $source;
        $value = match ($field) {
            'article_id' => Article::withoutGlobalScopes()->create([
                'org_id' => 0, 'slug' => 'unrelated-owner-'.$side, 'locale' => $article->locale,
                'title' => 'Unrelated article', 'content_md' => '## Unrelated article',
            ])->id,
            'locale' => $other->locale,
            'translation_group_id' => 'other-group',
        };
        $article->publishedRevision->forceFill([$field => $value])->saveQuietly();
        $service = app(ArticlePublishedSourceReviewService::class);
        try {
            $service->prepare($target);
            $this->fail('Mismatched published identity must reject.');
        } catch (ValidationException) {
        }
        $this->assertFalse($service->hasCurrentReview($target));
        $this->assertDatabaseCount('review_attestations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function revisionIdentityMismatches(): array
    {
        return [['source', 'article_id'], ['source', 'locale'], ['source', 'translation_group_id'],
            ['target', 'article_id'], ['target', 'locale'], ['target', 'translation_group_id']];
    }

    #[DataProvider('brokenEvidence')]
    public function test_incomplete_or_unrelated_formal_evidence_never_clears_stale(string $case): void
    {
        [$source, $target] = $this->fixture();
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        match ($case) {
            'scope' => DB::table('review_attestations')->update(['scope_type' => 'cms_article_review']),
            'package' => DB::table('review_attestations')->update(['package_sha256' => str_repeat('d', 64)]),
            'decision' => DB::table('review_attestations')->update(['decision' => 'approved_with_exceptions']),
            'partial' => DB::table('review_attestation_target_evidences')->where('id', DB::table('review_attestation_target_evidences')->min('id'))->delete(),
            'mixed' => DB::table('review_attestation_target_evidences')->update(['target_decision' => 'excepted']),
            'audit' => DB::table('audit_logs')->delete(),
        };
        $this->assertFalse($service->hasCurrentReview($target));
        $this->assertTrue(app(ArticleTranslationRevisionWorkspace::class)->isWorkingRevisionStale($target));
    }

    public static function brokenEvidence(): array
    {
        return array_map(fn ($x) => [$x], ['scope', 'package', 'decision', 'partial', 'mixed', 'audit']);
    }

    public function test_owner_permission_mfa_and_explicit_confirmation_are_required(): void
    {
        [, $target] = $this->fixture();
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepare($target);
        try {
            $service->confirm($target, $input);
            $this->fail('Review required');
        } catch (ValidationException) {
        }
        config(['admin.totp.enabled' => true]);
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('MFA required');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        session(['ops_admin_totp_verified_user_id' => auth('admin')->id()]);
        config(['review_governance.solo_owner_admin_user_id' => 999]);
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Configured owner required');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        auth('admin')->logout();
        try {
            $service->prepare($target);
            $this->fail('Authentication required');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('review_attestations', 0);
    }

    public function test_audit_failure_rolls_back_attestation_without_touching_content(): void
    {
        [$source, $target] = $this->fixture();
        $before = $this->rows($source, $target);
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepare($target);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('injected audit failure'));
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Audit failure required');
        } catch (RuntimeException $e) {
            $this->assertSame('injected audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('review_attestations', 0);
        $this->assertSame($before, $this->rows($source, $target));
        $this->assertFalse($service->hasCurrentReview($target));
    }

    public function test_active_operations_org_is_preserved_while_evidence_binds_global_article_resources(): void
    {
        [$source, $target] = $this->fixture();
        $before = $this->rows($source, $target);
        app(OrgContext::class)->set(17, null, 'admin');
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        $audit = AuditLog::withoutGlobalScopes()->sole();
        $this->assertSame(17, $audit->org_id);
        $this->assertSame(0, $audit->meta_json['resource_org_id']);
        $this->assertTrue($service->hasCurrentReview($target));
        $this->assertSame($before, $this->rows($source, $target));
        app(OrgContext::class)->set(18, null, 'admin');
        $this->assertTrue($service->hasCurrentReview($target));
        DB::table('audit_logs')->where('id', $audit->id)->update([
            'meta_json' => json_encode([...$audit->meta_json, 'resource_org_id' => 2]),
        ]);
        $this->assertFalse($service->hasCurrentReview($target));
    }

    public function test_real_editor_action_records_review_and_missing_storage_fails_closed(): void
    {
        [, $target] = $this->fixture();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepare($target);
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('confirmPublishedSourceReview', [...$input, 'source_reviewed' => true])->assertHasNoActionErrors()->assertRedirect();
        $this->assertTrue($service->hasCurrentReview($target));
        \Illuminate\Support\Facades\Schema::drop('review_attestation_target_evidences');
        $this->assertFalse($service->hasCurrentReview($target));
    }

    public function test_current_published_review_resolves_metadata_drift_without_rewriting_history(): void
    {
        [$source, $target] = $this->metadataDriftPair();
        $before = $this->rows($source, $target);
        $service = app(ArticlePublishedSourceReviewService::class);
        $this->assertFalse(app(ArticleTranslationRevisionWorkspace::class)->isWorkingRevisionStale($target));
        $this->assertTrue($service->needsCurrentReview($target));
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->assertActionVisible('confirmPublishedSourceReview');
        $this->assertFalse($this->metadataDashboard($source)['canonical_ok']);
        $actor = (string) auth('admin')->id();
        Livewire::test(EditorialReviewPage::class)
            ->set('ownerAssignments.article_'.$source->id, $actor)->call('assignOwnerItem', 'article', $source->id)
            ->set('reviewerAssignments.article_'.$source->id, $actor)->call('assignReviewerItem', 'article', $source->id)
            ->call('submitItem', 'article', $source->id)->call('approveItem', 'article', $source->id)
            ->assertHasNoErrors();
        $this->assertSame($before, $this->rows($source, $target));
        $this->assertFalse($this->metadataDashboard($source)['canonical_ok']);
        $input = $service->prepare($target);
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('confirmPublishedSourceReview', [...$input, 'source_reviewed' => true])
            ->assertHasNoActionErrors()->assertRedirect();
        $group = $this->metadataDashboard($source);
        $this->assertTrue($group['canonical_ok']);
        $this->assertSame('reviewed_current', $group['source_metadata_review']['state']);
        $this->assertSame($source->source_version_hash, $group['source_metadata_review']['recorded_hash']);
        $this->assertSame($source->computeSourceVersionHash(), $group['source_metadata_review']['computed_hash']);
        $this->assertNotSame($group['source_metadata_review']['recorded_hash'], $group['source_metadata_review']['computed_hash']);
        foreach ($group['locales'] as $locale) {
            $this->assertTrue($locale['is_freshness_known']);
            $this->assertFalse($locale['is_stale']);
            $this->assertTrue($locale['preflight']['ok'], implode('; ', $locale['preflight']['blockers']));
            $this->assertStringContainsString('historical fingerprint', implode(' ', $locale['compare_summary']));
        }
        $this->assertSame($before, $this->rows($source, $target));
        $this->assertFalse($service->needsCurrentReview($target));
        $this->assertDatabaseCount('article_translation_revisions', 3);
    }

    public function test_pair_attestation_alone_does_not_replace_current_source_editorial_review(): void
    {
        [$source, $target] = $this->metadataDriftPair();
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        $this->assertTrue($service->hasCurrentReview($target));
        $group = $this->metadataDashboard($source);
        $this->assertFalse($group['canonical_ok']);
        $this->assertNull($group['source_metadata_review']);
        $this->assertDatabaseCount('editorial_reviews', 0);
    }

    #[DataProvider('metadataReviewDrifts')]
    public function test_metadata_drift_explanation_requires_the_exact_current_pair_and_source_review(string $case): void
    {
        [$source, $target] = $this->metadataDriftPair();
        $actor = (int) auth('admin')->id();
        EditorialReviewAudit::assignOwner($actor, 'article', $source);
        EditorialReviewAudit::assignReviewer($actor, 'article', $source);
        EditorialReviewAudit::submit('article', $source);
        EditorialReviewAudit::mark('approved', 'article', $source);
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        $this->assertTrue($this->metadataDashboard($source)['canonical_ok']);
        match ($case) {
            'source_metadata' => $source->forceFill(['related_test_slug' => 'another-test'])->saveQuietly(),
            'source_body' => $source->forceFill(['content_md' => 'Changed body'])->saveQuietly(),
            'source_seo' => $source->seoMeta->forceFill(['seo_title' => 'Changed SEO'])->saveQuietly(),
            'target_seo' => $target->seoMeta->forceFill(['seo_title' => 'Changed SEO'])->saveQuietly(),
            'target_body' => $target->forceFill(['content_md' => 'Changed body'])->saveQuietly(),
            'target_history' => $target->publishedRevision->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->saveQuietly(),
            'source_published_hash' => $source->publishedRevision->forceFill(['source_version_hash' => str_repeat('b', 64)])->saveQuietly(),
            'source_private_working' => $source->forceFill(['working_revision_id' => null])->saveQuietly(),
            'target_private_working' => $target->forceFill(['working_revision_id' => null])->saveQuietly(),
            'source_tenant' => $source->forceFill(['org_id' => 2])->saveQuietly(),
            'target_tenant' => $target->forceFill(['org_id' => 2])->saveQuietly(),
            'target_slug' => $target->forceFill(['slug' => 'wrong-slug'])->saveQuietly(),
            'missing_source_review' => EditorialReview::withoutGlobalScopes()->delete(),
            'source_rejected' => EditorialReview::withoutGlobalScopes()->update(['workflow_state' => 'rejected']),
            'source_changes_requested' => EditorialReview::withoutGlobalScopes()->update(['workflow_state' => 'changes_requested']),
            'source_review_tenant' => EditorialReview::withoutGlobalScopes()->update(['org_id' => 2]),
            'missing_audit' => AuditLog::withoutGlobalScopes()->where('action', 'article_published_source_reviewed')->delete(),
            'incomplete_evidence' => DB::table('review_attestation_target_evidences')->where('review_attestation_id', ReviewAttestation::where('scope_type', 'cms_article_source_review')->sole()->id)->limit(1)->delete(),
            'third_member' => Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'third-member', 'locale' => 'en', 'source_locale' => 'zh-CN', 'translation_group_id' => $source->translation_group_id, 'source_article_id' => $source->id, 'title' => 'Another article', 'content_md' => '## Another body']),
        };
        $group = $this->metadataDashboard($source);
        $this->assertNull($group['source_metadata_review']);
        if ($case !== 'source_private_working') {
            $this->assertFalse($group['canonical_ok']);
        }
        if ($case === 'source_published_hash') {
            $this->assertContains('source row and published revision hashes differ', $group['canonical_issues']);
        }
    }

    public static function metadataReviewDrifts(): array
    {
        return array_map(fn ($case): array => [$case], ['source_metadata', 'source_body', 'source_seo', 'target_seo', 'target_body', 'target_history',
            'source_published_hash', 'source_private_working', 'target_private_working', 'source_tenant', 'target_tenant', 'target_slug',
            'missing_source_review', 'source_rejected', 'source_changes_requested', 'source_review_tenant', 'missing_audit', 'incomplete_evidence', 'third_member']);
    }

    private function metadataDashboard(Article $source): array
    {
        return app(ArticleTranslationOpsService::class)->dashboard(['slug' => $source->slug])['groups'][0];
    }

    public function test_console_consumes_current_published_pair_review_without_rewriting_historical_hashes(): void
    {
        [$source, $target] = $this->differentSourcePair();
        $before = $this->rows($source, $target);
        $this->assertTrue($this->consoleLocale($source, $target)['is_published_stale']);
        $service = app(ArticlePublishedSourceReviewService::class);
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('confirmPublishedSourceReview', [...$service->prepare($target), 'source_reviewed' => true])
            ->assertHasNoActionErrors();
        $locale = $this->consoleLocale($source, $target);
        $this->assertFalse($locale['is_stale']);
        $this->assertFalse($locale['is_published_stale']);
        $this->assertTrue($locale['is_freshness_known']);
        $this->assertFalse($locale['preflight']['ok']);
        $this->assertStringContainsString('editorial approval missing', implode(' ', $locale['preflight']['blockers']));
        $this->assertStringContainsString('historical source fingerprint retained', implode(' ', $locale['compare_summary']));
        $this->assertStringNotContainsString('source/target hash current', implode(' ', $locale['compare_summary']));
        $actor = (string) auth('admin')->id();
        Livewire::test(EditorialReviewPage::class)
            ->set('ownerAssignments.article_'.$source->id, $actor)->call('assignOwnerItem', 'article', $source->id)
            ->set('reviewerAssignments.article_'.$source->id, $actor)->call('assignReviewerItem', 'article', $source->id)
            ->call('submitItem', 'article', $source->id)->call('approveItem', 'article', $source->id)
            ->assertHasNoErrors();
        $locale = $this->consoleLocale($source, $target);
        $this->assertFalse($locale['is_stale']);
        $this->assertTrue($locale['preflight']['ok'], implode(' ', $locale['preflight']['blockers']));
        $this->assertSame($before, $this->rows($source, $target));
        $this->assertDatabaseCount('article_translation_revisions', 3);
        $this->assertNotSame($source->source_version_hash, $target->publishedRevision->translated_from_version_hash);
    }

    #[DataProvider('opsInvalidCurrentPairs')]
    public function test_console_rejects_changed_or_incomplete_current_pair_evidence(string $case): void
    {
        [$source, $target] = $this->differentSourcePair();
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        $this->assertFalse($this->consoleLocale($source, $target)['is_stale']);
        match ($case) {
            'source_body' => $source->forceFill(['content_md' => 'Changed source'])->saveQuietly(),
            'source_metadata' => $source->forceFill(['related_test_slug' => 'another-test'])->saveQuietly(),
            'source_seo' => $source->seoMeta->forceFill(['seo_title' => 'Changed source SEO'])->saveQuietly(),
            'target_seo' => $target->seoMeta->forceFill(['seo_title' => 'Changed target SEO'])->saveQuietly(),
            'target_history' => $target->publishedRevision->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->saveQuietly(),
            'target_private_working' => $target->forceFill(['working_revision_id' => null])->saveQuietly(),
            'separate_private_working' => $target->forceFill(['working_revision_id' => ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $target->id)->where('id', '!=', $target->published_revision_id)->sole()->id])->saveQuietly(),
            'source_pointer' => $source->forceFill(['working_revision_id' => null])->saveQuietly(),
            'missing_audit' => AuditLog::withoutGlobalScopes()->where('action', 'article_published_source_reviewed')->delete(),
            'incomplete_evidence' => DB::table('review_attestation_target_evidences')->where('review_attestation_id', ReviewAttestation::sole()->id)->limit(1)->delete(),
        };
        $this->assertTrue($this->consoleLocale($source, $target)['is_stale']);
    }

    public static function opsInvalidCurrentPairs(): array
    {
        return array_map(fn ($case): array => [$case], ['source_body', 'source_metadata', 'source_seo', 'target_seo', 'target_history',
            'target_private_working', 'separate_private_working', 'source_pointer', 'missing_audit', 'incomplete_evidence']);
    }

    #[DataProvider('nonApprovedSourceStates')]
    public function test_current_pair_review_does_not_replace_source_editorial_approval(string $state): void
    {
        [$source, $target] = $this->differentSourcePair();
        $actor = (int) auth('admin')->id();
        EditorialReviewAudit::assignOwner($actor, 'article', $source);
        EditorialReviewAudit::assignReviewer($actor, 'article', $source);
        EditorialReviewAudit::submit('article', $source);
        EditorialReviewAudit::mark('approved', 'article', $source);
        $service = app(ArticlePublishedSourceReviewService::class);
        $service->confirm($target, [...$service->prepare($target), 'source_reviewed' => true]);
        if ($state === 'missing') {
            EditorialReview::withoutGlobalScopes()->delete();
        } else {
            EditorialReview::withoutGlobalScopes()->update(['workflow_state' => $state]);
        }
        $locale = $this->consoleLocale($source, $target);
        $this->assertFalse($locale['is_stale']);
        $this->assertFalse($locale['preflight']['ok']);
        $this->assertStringContainsString('editorial approval missing', implode(' ', $locale['preflight']['blockers']));
    }

    public static function nonApprovedSourceStates(): array
    {
        return [['missing'], ['ready'], ['rejected'], ['changes_requested']];
    }

    private function consoleLocale(Article $source, Article $target): array
    {
        return collect($this->metadataDashboard($source)['locales'])->firstWhere('article_id', $target->id);
    }

    private function differentSourcePair(): array
    {
        [$source, $target] = $this->metadataDriftPair();
        $source->forceFill(['related_test_slug' => null])->saveQuietly();
        $target->publishedRevision->forceFill(['source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64)])->saveQuietly();
        $target->forceFill(['translated_from_version_hash' => str_repeat('a', 64)])->saveQuietly();

        return [$source->fresh(['workingRevision', 'publishedRevision', 'seoMeta']), $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta'])];
    }

    private function metadataDriftPair(): array
    {
        [$source, $target] = $this->fixture();
        $hash = $source->source_version_hash;
        $target->publishedRevision->forceFill(['source_version_hash' => $hash, 'translated_from_version_hash' => $hash])->saveQuietly();
        $target->forceFill(['source_version_hash' => $hash, 'translated_from_version_hash' => $hash, 'translation_status' => 'published'])->saveQuietly();
        foreach ([$source, $target] as $article) {
            ArticleSeoMeta::withoutGlobalScopes()->updateOrCreate(['article_id' => $article->id], ['org_id' => 0, 'locale' => $article->locale,
                'seo_title' => 'Current title', 'seo_description' => 'Current description', 'canonical_url' => 'https://fermatmind.com/'.($article->locale === 'en' ? 'en' : 'zh').'/articles/'.$article->slug,
                'og_title' => 'Current title', 'og_description' => 'Current description', 'og_image_url' => 'https://assets.fermatmind.com/fixture.jpg', 'robots' => 'index,follow']);
        }
        $source->forceFill(['related_test_slug' => 'iq-test-intelligence-quotient-assessment'])->saveQuietly();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        config(['admin.totp.enabled' => true]);
        session(['ops_admin_totp_verified_user_id' => auth('admin')->id()]);
        app(OrgContext::class)->set(17, (int) auth('admin')->id(), 'admin');

        return [$source->fresh(['workingRevision', 'publishedRevision', 'seoMeta']), $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta'])];
    }

    private function rows(Article $source, Article $target): array
    {
        return [$source->fresh()->getAttributes(), $target->fresh()->getAttributes(), $source->publishedRevision->fresh()->getAttributes(), $target->publishedRevision->fresh()->getAttributes(), $target->seoMeta->fresh()->getAttributes()];
    }

    private function fixture(): array
    {
        $admin = AdminUser::create(['name' => 'Owner', 'email' => 'manual-review@example.test', 'password' => bcrypt('fixture-only'), 'is_active' => true]);
        $role = Role::create(['name' => 'manual-source-review-owner', 'guard_name' => 'admin']);
        $permission = Permission::firstOrCreate(['name' => PermissionNames::ADMIN_OWNER], ['guard_name' => 'admin']);
        $role->permissions()->sync([$permission->id]);
        $admin->roles()->sync([$role->id]);
        $this->actingAs($admin, 'admin');
        $source = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'manual-source-review', 'locale' => 'zh-CN', 'title' => '当前源文', 'excerpt' => '当前摘要', 'content_md' => '## 当前源文', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $sourceRevision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash, 'title' => $source->title, 'excerpt' => $source->excerpt, 'content_md' => $source->content_md, 'published_at' => now()]);
        $source->forceFill(['working_revision_id' => $sourceRevision->id, 'published_revision_id' => $sourceRevision->id])->saveQuietly();
        $target = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => $source->slug, 'locale' => 'en', 'source_locale' => 'zh-CN', 'source_article_id' => $source->id, 'translated_from_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'translated_from_version_hash' => str_repeat('a', 64), 'translation_status' => 'human_review', 'title' => 'Published English', 'excerpt' => 'Published excerpt', 'content_md' => '## Published body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $payload = ['org_id' => 0, 'article_id' => $target->id, 'source_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN', 'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64), 'title' => 'English final article', 'excerpt' => 'A complete English summary.', 'content_md' => '## Reviewed English body', 'seo_title' => 'English SEO title', 'seo_description' => 'English SEO description'];
        $published = ArticleTranslationRevision::withoutGlobalScopes()->create([...$payload, 'revision_number' => 1, 'revision_status' => 'published', 'published_at' => now()]);
        $working = ArticleTranslationRevision::withoutGlobalScopes()->create([...$payload, 'revision_number' => 2, 'revision_status' => 'human_review']);
        $target->forceFill(['working_revision_id' => $working->id, 'published_revision_id' => $published->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $target->id, 'locale' => 'en', 'seo_title' => $payload['seo_title'], 'seo_description' => $payload['seo_description'], 'canonical_url' => 'https://fermatmind.com/en/articles/'.$target->slug, 'robots' => 'noindex,nofollow']);

        config(['admin.totp.enabled' => false, 'review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $admin->id]);
        $source->forceFill(['published_at' => now()])->saveQuietly();
        $target->forceFill(['title' => $published->title, 'excerpt' => $published->excerpt, 'content_md' => $published->content_md, 'published_at' => now(), 'working_revision_id' => $published->id])->saveQuietly();

        return [$source->fresh(['workingRevision', 'publishedRevision']), $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta'])];
    }
}
