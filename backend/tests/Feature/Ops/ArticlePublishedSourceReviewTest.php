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

    public function test_current_review_accepts_existing_reciprocal_localized_public_slugs(): void
    {
        [$source, $target] = $this->metadataDriftPair();
        $target->forceFill(['slug' => 'english-localized-slug', 'is_indexable' => true])->saveQuietly();
        $source->forceFill(['is_indexable' => true])->saveQuietly();
        foreach ([$source, $target] as $article) {
            $locale = $article->locale === 'en' ? 'en' : 'zh';
            $article->seoMeta->forceFill([
                'canonical_url' => 'https://fermatmind.com/'.$locale.'/articles/'.$article->slug,
                'is_indexable' => true,
                'schema_json' => ['editorial_package_v1' => ['hreflang_gate_v1' => ['enabled' => true]]],
            ])->saveQuietly();
        }

        $service = app(ArticlePublishedSourceReviewService::class);
        $this->assertTrue($service->needsCurrentReview($target));
        $before = $this->rows($source, $target);
        $input = $service->prepare($target);
        $service->confirm($target, [...$input, 'source_reviewed' => true]);
        $this->assertTrue($service->hasCurrentReview($target));
        $this->assertSame($before, $this->rows($source, $target));

        $target->seoMeta->forceFill(['canonical_url' => 'https://fermatmind.com/en/articles/another-article'])->saveQuietly();
        $this->assertFalse($service->hasCurrentReview($target));
        $this->assertFalse($service->needsCurrentReview($target));
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

    public function test_independent_draft_review_uses_public_source_and_keeps_private_source_and_history_unchanged(): void
    {
        [$source, $target] = $this->independentFixture();
        $sourceBefore = \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source);
        $oldHash = $target->workingRevision->translated_from_version_hash;
        $oldDraft = $target->workingRevision->getAttributes();
        app(OrgContext::class)->set(17, (int) auth('admin')->id(), 'admin');
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('forkPrivateWorkingRevision', ['target_hash' => \App\Support\ArticleSourceTargetSnapshot::publicationHash($target)])
            ->assertHasNoActionErrors()->assertRedirect();
        $target->refresh();
        Livewire::test(EditArticle::class, ['record' => $target->id])->fillForm([
            'title' => 'Final independently researched English article',
            'excerpt' => 'A revised complete summary from the real Ops editor.',
            'content_md' => '## Final independently researched body',
            'seo_title' => 'Can personality traits change?',
            'seo_description' => 'Separate adult development, intentional change and score differences.',
            'og_title' => 'Can personality traits change?',
            'og_description' => 'Separate adult development, intentional change and score differences.',
            'working_revision_status' => 'human_review',
        ])->call('save')->assertHasNoFormErrors();
        $target = $target->fresh(['workingRevision', 'seoMeta']);
        $this->assertNotSame($oldDraft['id'], $target->working_revision_id);
        $this->assertSame($oldDraft, ArticleTranslationRevision::withoutGlobalScopes()->find($oldDraft['id'])->getAttributes());
        foreach (['title', 'excerpt', 'content_md'] as $field) {
            $this->assertSame($target->workingRevision->{$field}, $target->{$field}, $field);
        }
        $service = app(ArticlePublishedSourceReviewService::class);
        $this->assertFalse(app(\App\Services\Cms\ArticleTranslationWorkflowService::class)->preflight($target)['ok']);
        $input = $service->prepareIndependent($target);
        $context = json_decode($input['review_context'], true);
        $this->assertSame($source->published_revision_id, $context['source_published_revision_id']);
        $this->assertSame($source->working_revision_id, $context['source_unpublished_working_revision_id']);
        $this->assertStringContainsString('revision='.$source->published_revision_id, $context['source_preview']);
        $this->assertStringContainsString('preview=published_source', $context['source_preview']);
        $org = \App\Models\Organization::create(['name' => 'Preview operator org', 'owner_user_id' => 9101, 'status' => 'active',
            'domain' => 'source-preview.example.test', 'timezone' => 'Asia/Shanghai', 'locale' => 'en']);
        $this->withSession(['ops_org_id' => $org->id, 'ops_admin_totp_verified_user_id' => auth('admin')->id()])
            ->get($context['source_preview'])->assertOk()->assertSee('当前源文')
            ->assertSee('Published source revision preview')->assertDontSee('Separate unpublished Chinese draft');
        $this->get($context['target_preview'])->assertOk()->assertSee('Final independently researched body');
        app(OrgContext::class)->set(17, (int) auth('admin')->id(), 'admin');
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('confirmIndependentSourceReview', [...$input, 'source_reviewed' => true,
                'review_notes' => 'Read the complete public source and independent research; retained evidence boundaries and documented new intervention evidence.'])
            ->assertHasNoActionErrors()->assertRedirect();
        $this->assertTrue($service->hasCurrentIndependentReview($target));
        $this->assertFalse($target->fresh()->isTranslationStale());
        $workflow = app(\App\Services\Cms\ArticleTranslationWorkflowService::class);
        $this->assertTrue($workflow->preflight($target->fresh())['ok']);
        $workflow->approveEditorialWorkingRevision($target, (int) auth('admin')->id());
        $target = $target->fresh(['workingRevision', 'seoMeta']);
        $this->assertTrue($service->hasCurrentIndependentReview($target));
        $this->assertTrue(app(\App\Services\Cms\CmsEditorialReviewAttestationService::class)
            ->hasApprovedEvidence('article_translation_revision', $source->publishedRevision));
        $this->assertSame($oldHash, $target->workingRevision->translated_from_version_hash);
        $this->assertSame($sourceBefore, \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()));
        $this->assertDatabaseCount('editorial_reviews', 0);
        $history = \App\Support\ArticleSourceTargetSnapshot::sourceRevisions($target, [(int) $target->working_revision_id]);
        $fingerprint = \App\Console\Commands\ArticleForkPrivateTranslationLinks::class;
        $package = ['schema' => 'reviewed_article_translation_publication_v1', 'source_id' => (int) $source->id,
            'target_id' => (int) $target->id, 'source_published_revision_id' => (int) $source->published_revision_id,
            'working_revision_id' => (int) $target->working_revision_id, 'source_snapshot_hash' => $fingerprint::sourceHash($source),
            'target_snapshot_hash' => $fingerprint::targetHash($source), 'body_sha256' => hash('sha256', $target->workingRevision->content_md)];
        $publication = app(\App\Services\Cms\ReviewedTranslationPublication::class);
        $this->assertTrue($publication->run($package, false, false, '', true)['ok']);
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            $payload = $request->data();
            $locale = $payload['content']['locale'];
            $slug = $payload['content']['slug'];
            $paths = array_values(array_unique([...$payload['cache_signal']['paths'], '/zh/articles', '/en/articles',
                '/zh/articles/'.$slug, '/en/articles/'.$slug, '/llms.txt', '/llms-full.txt']));

            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'revalidated_paths' => $paths, 'rejected_paths' => [],
                'invalidated_tags' => ['article-detail:'.$locale.':'.$slug, 'article-seo:'.$locale.':'.$slug]]);
        });
        $publishInput = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class)->prepare($target);
        Livewire::test(EditArticle::class, ['record' => $target->id])->callAction('publishControlled', [
            ...$publishInput, 'preview_approved' => true, 'make_indexable' => true,
        ])->assertHasNoActionErrors()->assertRedirect();
        \Illuminate\Support\Facades\Http::assertSent(fn ($request): bool => $request->url() === 'https://cache.example.test/revalidate');
        $target = $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
        $this->assertTrue($target->is_public);
        $this->assertSame($package['working_revision_id'], $target->published_revision_id);
        $this->assertSame($history, \App\Support\ArticleSourceTargetSnapshot::sourceRevisions($target, [(int) $target->working_revision_id]));
        $this->assertSame($sourceBefore, $fingerprint::sourceHash($source->fresh()));
        $this->assertTrue($service->hasCurrentIndependentReview($target));
        $code = \Illuminate\Support\Facades\Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $target->id, '--translation-group-id' => $target->translation_group_id,
            '--expected-slugs' => $target->slug, '--enable-article-schema' => true, '--enable-breadcrumb-schema' => true,
            '--hold-faq-schema' => true, '--execute' => true, '--no-publish' => true, '--no-search' => true,
            '--no-sitemap-llms-change' => true, '--no-content-change' => true, '--no-revalidation' => true, '--json' => true,
        ]);
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $this->assertTrue($service->hasCurrentIndependentReview($target->fresh()));
        $this->assertFalse($target->fresh()->isTranslationStale());
        $seo = $target->seoMeta->fresh();
        $schema = $seo->schema_json;
        $schema['editorial_package_v1']['faq_items'] = [['question' => 'Changed FAQ?', 'answer' => 'Changed substantive answer.']];
        $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        $this->assertFalse($service->hasCurrentIndependentReview($target->fresh()));
    }

    #[DataProvider('publishedSourcePreviewConflicts')]
    public function test_published_source_http_preview_is_explicit_revision_bound_and_preserves_working_preview(string $case): void
    {
        [$source, $target] = $this->independentFixture();
        $org = \App\Models\Organization::create(['name' => 'Preview operator org', 'owner_user_id' => 9101, 'status' => 'active',
            'domain' => 'source-preview.example.test', 'timezone' => 'Asia/Shanghai', 'locale' => 'en']);
        $this->withSession(['ops_org_id' => $org->id, 'ops_admin_totp_verified_user_id' => auth('admin')->id()]);
        $url = \App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace::publishedSourcePreviewUrl($source);
        $this->get($url)->assertOk()->assertSee('当前源文')->assertDontSee('Separate unpublished Chinese draft');
        $this->get(route('ops.articles.preview', ['article' => $source->id]))->assertOk()->assertSee('Separate unpublished Chinese draft');
        $this->get(route('ops.articles.preview', ['article' => $source->id, 'revision' => $source->published_revision_id]))->assertStatus(409);
        $status = 409;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        match ($case) {
            'source_public_body' => $source->publishedRevision->forceFill(['content_md' => 'Changed public source'])->saveQuietly(),
            'published_pointer' => $source->forceFill(['published_revision_id' => $source->working_revision_id])->saveQuietly(),
            'wrong_owner' => DB::table('article_translation_revisions')->where('id', $source->published_revision_id)->update(['article_id' => $target->id, 'revision_number' => 99]),
            'wrong_tenant' => DB::table('article_translation_revisions')->where('id', $source->published_revision_id)->update(['org_id' => 2]),
            'source_private' => $source->forceFill(['is_public' => false])->saveQuietly(),
            'wrong_hash' => $query['revision_hash'] = str_repeat('a', 64),
            'missing_hash' => (function () use (&$query, &$status): void {
                unset($query['revision_hash']);
                $status = 422;
            })(),
        };
        $response = $this->get(route('ops.articles.preview', ['article' => $source->id, ...$query]))->assertStatus($status);
        if ($status === 409) {
            $response->assertHeader('X-Robots-Tag', 'noindex, noarchive, nosnippet');
        }
    }

    public static function publishedSourcePreviewConflicts(): array
    {
        return array_map(fn ($case): array => [$case], ['source_public_body', 'published_pointer', 'wrong_owner', 'wrong_tenant', 'source_private', 'wrong_hash', 'missing_hash']);
    }

    #[DataProvider('independentDrifts')]
    public function test_independent_review_rejects_drift_and_does_not_make_default_stale_rules_optional(string $case): void
    {
        [$source, $target] = $this->independentFixture();
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepareIndependent($target);
        $input = [...$input, 'source_reviewed' => true, 'review_notes' => 'Read both complete previews and original research; independent added evidence has bounded claims.'];
        $service->confirmIndependent($target, $input);
        match ($case) {
            'source_private_body' => $source->workingRevision->forceFill(['content_md' => 'Changed unpublished draft'])->saveQuietly(),
            'source_public_body' => $source->publishedRevision->forceFill(['content_md' => 'Changed public source'])->saveQuietly(),
            'source_pointer' => $source->forceFill(['working_revision_id' => $source->published_revision_id])->saveQuietly(),
            'source_tenant' => DB::table('articles')->where('id', $source->id)->update(['org_id' => 3]),
            'target_body' => $target->workingRevision->forceFill(['content_md' => 'Changed target'])->saveQuietly(),
            'target_seo' => $target->seoMeta->forceFill(['seo_title' => 'Changed SEO'])->saveQuietly(),
            'target_metadata' => $target->forceFill(['related_test_slug' => 'another-test'])->saveQuietly(),
            'category_record' => DB::table('article_categories')->where('id', $target->category_id)->update(['name' => 'Changed category']),
            'tag_record' => DB::table('article_tags')->update(['name' => 'Changed tag']),
            'target_tags' => DB::table('article_tag_map')->insert(['article_id' => $target->id, 'tag_id' => 999, 'org_id' => 2]),
            'target_fork' => $target->forceFill(['working_revision_id' => null])->saveQuietly(),
            'missing_audit' => AuditLog::withoutGlobalScopes()->where('action', 'article_independent_source_reviewed')->delete(),
            'incomplete_evidence' => DB::table('review_attestation_target_evidences')->where('review_attestation_id', ReviewAttestation::sole()->id)->limit(1)->delete(),
        };
        $this->assertFalse($service->hasCurrentIndependentReview($target));
        if ($case !== 'missing_audit') {
            try {
                $service->confirmIndependent($target, $input);
                $this->fail('Old review must reject drift.');
            } catch (ValidationException|RuntimeException) {
            }
        }
    }

    public static function independentDrifts(): array
    {
        return array_map(fn ($case): array => [$case], ['source_private_body', 'source_public_body', 'source_pointer', 'source_tenant',
            'target_body', 'target_seo', 'target_metadata', 'category_record', 'tag_record', 'target_tags', 'target_fork', 'missing_audit', 'incomplete_evidence']);
    }

    public function test_independent_review_requires_owner_step_up_notes_and_audit_and_rolls_back_failed_audit(): void
    {
        [, $target] = $this->independentFixture();
        $service = app(ArticlePublishedSourceReviewService::class);
        $input = $service->prepareIndependent($target);
        try {
            $service->confirmIndependent($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Notes required.');
        } catch (ValidationException) {
        }
        config(['admin.totp.enabled' => true]);
        session()->forget('ops_admin_totp_verified_user_id');
        try {
            $service->confirmIndependent($target, [...$input, 'source_reviewed' => true, 'review_notes' => str_repeat('Reviewed ', 10)]);
            $this->fail('Step up required.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        session(['ops_admin_totp_verified_user_id' => auth('admin')->id()]);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failure'));
        try {
            $service->confirmIndependent($target, [...$input, 'source_reviewed' => true, 'review_notes' => str_repeat('Reviewed ', 10)]);
            $this->fail('Audit failure required.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('review_attestations', 0);
    }

    public function test_private_fork_preserves_original_draft_and_source_with_real_ops_org_context(): void
    {
        [$source, $target] = $this->independentFixture();
        $old = $target->workingRevision->getAttributes();
        $before = \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source);
        $hash = \App\Support\ArticleSourceTargetSnapshot::publicationHash($target);
        app(OrgContext::class)->set(17, (int) auth('admin')->id(), 'admin');
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('forkPrivateWorkingRevision', ['target_hash' => $hash])->assertHasNoActionErrors()->assertRedirect();
        $target->refresh()->load('workingRevision');
        $this->assertNotSame($old['id'], $target->working_revision_id);
        $this->assertSame($old, ArticleTranslationRevision::withoutGlobalScopes()->find($old['id'])->getAttributes());
        $this->assertSame($old['id'], $target->workingRevision->supersedes_revision_id);
        $this->assertNull($target->published_revision_id);
        $this->assertSame($before, \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()));
        try {
            app(ArticleTranslationRevisionWorkspace::class)->forkPrivateWorkingRevision($target, $hash, (int) auth('admin')->id());
            $this->fail('Stale snapshot must reject.');
        } catch (RuntimeException $e) {
            $this->assertSame('article_publication_state_changed', $e->getMessage());
        }
    }

    public function test_real_ops_save_of_published_target_preserves_public_body_and_source(): void
    {
        [$source, $target] = $this->fixture();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        app(OrgContext::class)->set(17, (int) auth('admin')->id(), 'admin');
        $sourceBefore = \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source);
        $old = $target->publishedRevision->getAttributes();
        $projection = $target->only(['title', 'excerpt', 'content_md', 'published_revision_id', 'status', 'is_public']);
        Livewire::test(EditArticle::class, ['record' => $target->id])->fillForm([
            'title' => 'Pending published-target edit', 'excerpt' => 'Pending excerpt', 'content_md' => '## Pending complete body',
        ])->call('save')->assertHasNoFormErrors();
        $target->refresh()->load(['workingRevision', 'publishedRevision']);
        $this->assertSame($projection, $target->only(array_keys($projection)));
        $this->assertSame($old, $target->publishedRevision->getAttributes());
        $this->assertNotSame($old['id'], $target->working_revision_id);
        $this->assertSame('## Pending complete body', $target->workingRevision->content_md);
        $this->assertSame($sourceBefore, \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()));
    }

    #[DataProvider('independentOgFields')]
    public function test_independent_review_rejects_og_that_native_publication_would_overwrite(string $field): void
    {
        [$source, $target] = $this->independentFixture();
        $target->seoMeta->forceFill([$field => 'Different reviewed OG content'])->saveQuietly();
        $before = \App\Console\Commands\ArticleForkPrivateTranslationLinks::targetHash($source);
        try {
            app(ArticlePublishedSourceReviewService::class)->prepareIndependent($target);
            $this->fail('An OG difference must reject before review or publication.');
        } catch (ValidationException $e) {
            $this->assertSame(['independent_source_publication_projection_mismatch'], $e->errors()['source_reviewed']);
        }
        $this->assertSame($before, \App\Console\Commands\ArticleForkPrivateTranslationLinks::targetHash($source));
        $this->assertFalse($target->fresh()->is_public);
        $this->assertDatabaseCount('review_attestations', 0);
    }

    public static function independentOgFields(): array
    {
        return [['og_title'], ['og_description']];
    }

    public function test_first_publication_with_separate_source_working_draft_still_rejects_without_independent_review(): void
    {
        [$source, $target] = $this->independentFixture();
        $target->workingRevision->forceFill(['revision_status' => 'approved', 'approved_at' => now(), 'reviewed_by' => auth('admin')->id()])->saveQuietly();
        app(\App\Services\Cms\CmsEditorialReviewAttestationService::class)->bindOrCreateApproved(null, 'cms_article_review',
            'test:unbound:'.$target->working_revision_id, [['surface_id' => 'article_translation_revision', 'record' => $target->workingRevision->fresh()]], (int) auth('admin')->id());
        $f = \App\Console\Commands\ArticleForkPrivateTranslationLinks::class;
        $package = ['schema' => 'reviewed_article_translation_publication_v1', 'source_id' => (int) $source->id,
            'target_id' => (int) $target->id, 'source_published_revision_id' => (int) $source->published_revision_id,
            'working_revision_id' => (int) $target->working_revision_id, 'source_snapshot_hash' => $f::sourceHash($source),
            'target_snapshot_hash' => $f::targetHash($source), 'body_sha256' => hash('sha256', $target->workingRevision->content_md)];
        $before = $f::targetHash($source);
        try {
            app(\App\Services\Cms\ReviewedTranslationPublication::class)->run($package, true, true,
                'I explicitly approve Codex to publish article id '.$target->id.' after preflight passes.', true);
            $this->fail('Separate private source must not be implicitly accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('translation_snapshot_drift', $e->getMessage());
        }
        $this->assertSame($before, $f::targetHash($source));
        $this->assertFalse($target->fresh()->is_public);
    }

    private function independentFixture(): array
    {
        [$source, $target] = $this->fixture();
        $working = $source->publishedRevision->replicate();
        $working->forceFill(['revision_number' => 2, 'revision_status' => 'human_review', 'published_at' => null,
            'content_md' => '## Separate unpublished Chinese draft', 'source_version_hash' => str_repeat('b', 64)])->saveQuietly();
        $source->forceFill(['working_revision_id' => $working->id, 'source_version_hash' => str_repeat('b', 64)])->saveQuietly();
        $targetRevision = ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $target->id)->where('revision_status', 'human_review')->sole();
        $target->forceFill(['status' => 'draft', 'is_public' => false, 'is_indexable' => false, 'published_at' => null,
            'published_revision_id' => null, 'working_revision_id' => $targetRevision->id, 'translation_status' => 'human_review',
            'title' => $targetRevision->title, 'excerpt' => $targetRevision->excerpt, 'content_md' => $targetRevision->content_md])->saveQuietly();
        $category = \App\Models\ArticleCategory::create(['org_id' => 0, 'name' => 'Big Five', 'slug' => 'big-five', 'is_active' => true]);
        $tag = \App\Models\ArticleTag::create(['org_id' => 0, 'name' => 'Big Five', 'slug' => 'big-five', 'is_active' => true]);
        $target->forceFill(['category_id' => $category->id, 'author_name' => 'FermatMind Editorial',
            'cover_image_url' => 'https://assets.fermatmind.com/cover.jpg', 'cover_image_alt' => 'Illustration of personality development'])->saveQuietly();
        $target->tags()->sync([$tag->id => ['org_id' => 0]]);
        $target->seoMeta->forceFill(['og_title' => $targetRevision->seo_title, 'og_description' => $targetRevision->seo_description,
            'og_image_url' => $target->cover_image_url, 'schema_json' => ['editorial_package_v1' => ['article_schema_enabled' => false,
                'breadcrumb_schema_enabled' => false, 'faq_schema_enabled' => false, 'schema_hold' => true,
                'faq_items' => [['question' => 'Can scores change?', 'answer' => 'A change needs context and evidence.']]]]])->saveQuietly();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        config(['admin.totp.enabled' => true]);
        auth('admin')->user()->forceFill(['totp_enabled_at' => now()])->saveQuietly();
        session(['ops_admin_totp_verified_user_id' => auth('admin')->id()]);

        return [$source->fresh(['workingRevision', 'publishedRevision']), $target->fresh(['workingRevision', 'seoMeta'])];
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
