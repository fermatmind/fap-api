<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Adapters\IqPublicArticlePromotionAdapter;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\IqPublicArticlePackage;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class IqPublicArticlePromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    private array $receiptFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->receiptFiles as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function test_bilingual_publish_and_rollback_preserve_foreign_drafts_and_indexability(): void
    {
        $before = $this->seedExisting();
        $drafts = ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->get()->map->getAttributes()->all();
        $control = Article::query()->create(['org_id' => 17, 'slug' => 'iq-test-tool-guide', 'locale' => 'en', 'title' => 'Other tenant', 'content_md' => 'Unrelated original', 'status' => 'draft']);
        $controlBefore = $control->fresh()->getAttributes();
        [$adapter, $context, $publication] = $this->publish();
        self::assertSame(10, $publication['published_count']);
        self::assertSame(10, $adapter->liveQa($context)['readback_count']);
        foreach ($before as $id => $row) {
            $article = Article::query()->findOrFail($id);
            foreach (['is_indexable', 'sitemap_eligible', 'llms_eligible'] as $field) {
                self::assertSame((bool) $row[$field], $article->$field);
            }
            if ((int) $row['working_revision_id'] !== (int) $row['published_revision_id']) {
                self::assertSame((int) $row['working_revision_id'], $article->working_revision_id);
            }
            $seo = $article->seoMeta;
            self::assertSame('protected note', data_get($seo->schema_json, 'editorial_package_v1.answer_surface_v1.notes'));
            self::assertSame($article->locale === 'en' ? 'noindex,follow' : 'index,follow', $seo->robots);
        }
        $new = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->orderBy('locale')->get();
        self::assertCount(2, $new);
        self::assertSame($new[0]->translation_group_id, $new[1]->translation_group_id);
        self::assertSame($new[1]->id, $new[0]->source_article_id);
        foreach ($new as $article) {
            self::assertFalse($article->is_indexable);
            self::assertFalse($article->sitemap_eligible);
            self::assertFalse($article->llms_eligible);
            self::assertCount(9, data_get($article->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
            self::assertNull($article->publishedRevision->reviewed_by);
            self::assertNull($article->publishedRevision->reviewed_at);
        }
        self::assertSame($drafts, ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->get()->map->getAttributes()->all());
        $adapter->rollback($context, $publication['rollback_reference']);
        foreach ($before as $id => $row) {
            $article = Article::query()->findOrFail($id);
            foreach (['title', 'excerpt', 'content_md', 'source_version_hash', 'translated_from_version_hash', 'published_revision_id', 'working_revision_id'] as $field) {
                self::assertSame($row[$field], $article->getAttributes()[$field]);
            }
        }
        self::assertSame(0, Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('is_public', true)->count());
        self::assertSame($drafts, ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->get()->map->getAttributes()->all());
        self::assertSame($controlBefore, $control->fresh()->getAttributes());
        self::assertTrue($adapter->recoverFailedPublication($context));
    }

    public function test_missing_native_english_seo_is_published_and_rolled_back_without_changing_qualification_or_foreign_drafts(): void
    {
        $before = $this->seedExisting();
        ArticleSeoMeta::query()->where('locale', 'en')->delete();
        $drafts = ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->get()->map->getAttributes()->all();
        [$adapter, $context, $publication] = $this->publish();
        self::assertSame(10, $adapter->liveQa($context)['readback_count']);
        foreach ($before as $id => $row) {
            $article = Article::query()->findOrFail($id);
            self::assertSame((bool) $row['is_indexable'], $article->is_indexable);
            self::assertSame((bool) $row['is_indexable'], $article->seoMeta->is_indexable);
            self::assertSame((bool) $row['sitemap_eligible'], $article->sitemap_eligible);
            self::assertSame((bool) $row['llms_eligible'], $article->llms_eligible);
            if ($article->locale === 'en') {
                self::assertNull($article->seoMeta->robots);
                self::assertNull($article->seoMeta->canonical_url);
            }
        }
        $adapter->rollback($context, $publication['rollback_reference']);
        self::assertSame(0, ArticleSeoMeta::query()->where('locale', 'en')->whereIn('article_id', array_keys($before))->count());
        foreach ($before as $id => $row) {
            $article = Article::query()->findOrFail($id);
            foreach (['title', 'excerpt', 'content_md', 'published_revision_id', 'working_revision_id'] as $field) {
                self::assertSame($row[$field], $article->getAttributes()[$field]);
            }
        }
        self::assertSame($drafts, ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->get()->map->getAttributes()->all());
        self::assertTrue($adapter->recoverFailedPublication($context));
    }

    public function test_rollback_refuses_to_delete_a_created_native_seo_row_with_later_operator_changes(): void
    {
        $before = $this->seedExisting();
        ArticleSeoMeta::query()->where('locale', 'en')->delete();
        [$adapter, $context, $publication] = $this->publish();
        $seo = ArticleSeoMeta::query()->where('locale', 'en')->whereIn('article_id', array_keys($before))->firstOrFail();
        $seo->forceFill(['og_image_url' => 'https://fermatmind.com/operator-image.png'])->saveQuietly();
        try {
            $adapter->rollback($context, $publication['rollback_reference']);
            self::fail('Later operator SEO changes must prevent row deletion.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_rollback_created_seo_drift', $error->getMessage());
        }
        self::assertSame('https://fermatmind.com/operator-image.png', $seo->fresh()->og_image_url);
        self::assertSame(10, Article::query()->where('is_public', true)->count());
    }

    public function test_preflight_detects_a_changed_foreign_draft_without_creating_any_package_rows(): void
    {
        $this->seedExisting();
        $adapter = app(IqPublicArticlePromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_MACHINE_DRAFT)->first()->forceFill(['content_md' => 'New operator draft bytes'])->saveQuietly();
        try {
            $adapter->draftImport($context);
            self::fail('Changed draft must invalidate preflight.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_prestate_drift', $error->getMessage());
        }
        self::assertSame(0, ArticleTranslationRevision::query()->where('authority_package_sha256', IqPublicArticlePackage::SHA256)->count());
        self::assertSame(8, Article::query()->count());
    }

    public function test_publication_rejects_a_changed_seo_or_indexability_prestate(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->import();
        ArticleSeoMeta::query()->first()->forceFill(['robots' => 'noindex,nofollow'])->saveQuietly();
        $this->expectExceptionMessage('iq_article_prestate_drift');
        $adapter->publish($context);
    }

    public function test_failure_on_the_last_article_rolls_back_the_whole_publication_transaction(): void
    {
        $this->seedExisting();
        ArticleSeoMeta::query()->where('locale', 'en')->delete();
        [$adapter, $context] = $this->import();
        $beforeArticles = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeRevisions = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeSeo = ArticleSeoMeta::query()->orderBy('id')->get()->map->getAttributes()->all();
        $injectFailure = true;
        if (DB::connection()->getDriverName() === 'mysql') {
            // Trigger DDL would implicitly commit the test's outer transaction.
            // Raise a real SQL error after the final write without changing it.
            DB::listen(static function ($event) use (&$injectFailure): void {
                $bindings = $event->bindings;
                if ($injectFailure && str_starts_with($event->sql, 'update `articles`')
                    && Article::query()->where('id', end($bindings))->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->where('is_public', true)->exists()) {
                    $injectFailure = false;
                    DB::statement("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'iq_test_atomic_failure'");
                }
            });
        } else {
            DB::unprepared("CREATE TRIGGER iq_abort_last_publish BEFORE UPDATE ON articles WHEN NEW.slug='what-is-iq-and-how-it-is-measured' AND NEW.locale='en' AND NEW.is_public=1 BEGIN SELECT RAISE(ABORT, 'iq_test_atomic_failure'); END");
        }
        try {
            $adapter->publish($context);
            self::fail('Injected database failure must abort.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('iq_test_atomic_failure', $error->getMessage());
        } finally {
            $injectFailure = false;
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::unprepared('DROP TRIGGER iq_abort_last_publish');
            }
        }
        self::assertSame($beforeArticles, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame($beforeRevisions, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame($beforeSeo, ArticleSeoMeta::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame(1, ContentReleaseSnapshot::query()->where('pack_id', 'iq-public-articles')->count());
        foreach ([$context, $this->nextContext($context, $context->sourceCommit)] as $failed) {
            try {
                $adapter->preflight($failed);
                self::fail('A transaction failure must remain terminal for its source SHA.');
            } catch (DomainException $error) {
                self::assertSame('iq_article_failed_source_requires_new_commit', $error->getMessage());
            }
        }
        $corrective = $this->nextContext($context, str_repeat('e', 40));
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'iq-corrective-receipt-');
            unlink($path);
            $this->receiptFiles[] = $path;
            $result = app(ExactPackagePromotionService::class)->execute($corrective, $phase, $path);
            self::assertSame(10, $result['receipt']['readback_count']);
            $previous = $path;
        }
    }

    public function test_rollback_preserves_later_unrelated_fields_and_an_operator_hold(): void
    {
        $before = $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $id = array_key_first($before);
        $article = Article::query()->findOrFail($id);
        // Match ArticlePublishService::unpublish's actual private-state transition.
        $article->forceFill(['author_name' => 'Later author', 'status' => 'draft', 'is_public' => false])->saveQuietly();
        $seo = $article->seoMeta;
        $schema = $seo->schema_json;
        Arr::set($schema, 'editorial_package_v1.answer_surface_v1.operator_note', 'Later note');
        $seo->forceFill(['robots' => 'noindex,nofollow', 'schema_json' => $schema])->saveQuietly();
        $adapter->rollback($context, $publication['rollback_reference']);
        $restored = $article->fresh();
        self::assertFalse($restored->is_public);
        self::assertSame('draft', $restored->status);
        self::assertSame('Later author', $restored->author_name);
        self::assertSame($before[$id]['content_md'], $restored->content_md);
        self::assertSame('noindex,nofollow', $restored->seoMeta->robots);
        self::assertSame('Later note', data_get($restored->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.operator_note'));
        self::assertJsonValueSame([['question' => 'Original question', 'answer' => 'Original answer']], data_get($restored->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
    }

    public function test_owned_copy_drift_aborts_the_entire_rollback(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->first()->forceFill(['content_md' => 'Later editorial body'])->saveQuietly();
        $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        try {
            $adapter->rollback($context, $publication['rollback_reference']);
            self::fail('Later body must not be overwritten.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_rollback_owned_copy_drift', $error->getMessage());
        }
        self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_rollback_preserves_a_later_related_test_change_and_recomputes_projection_hash(): void
    {
        $before = $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $article = Article::query()->findOrFail(array_key_first($before));
        $article->forceFill(['related_test_slug' => 'eq-test-emotional-intelligence-assessment'])->save();
        $adapter->rollback($context, $publication['rollback_reference']);
        $restored = $article->fresh();
        self::assertSame('eq-test-emotional-intelligence-assessment', $restored->related_test_slug);
        self::assertSame($before[$article->id]['content_md'], $restored->content_md);
        self::assertSame($restored->computeSourceVersionHash(), $restored->source_version_hash);
        self::assertNotSame($before[$article->id]['source_version_hash'], $restored->source_version_hash);
    }

    public function test_a_modified_package_revision_cannot_be_reused_by_a_fresh_preflight(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->import();
        ArticleTranslationRevision::query()->where('authority_package_sha256', IqPublicArticlePackage::SHA256)->where('locale', 'en')->first()->forceFill(['content_md' => 'Changed stored candidate'])->saveQuietly();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->expectExceptionMessage('iq_article_exact_draft_payload_drift');
        $adapter->draftImport($context);
    }

    public function test_a_modified_translation_source_hash_cannot_be_reused_by_a_fresh_preflight(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->import();
        ArticleTranslationRevision::query()->where('authority_package_sha256', IqPublicArticlePackage::SHA256)->where('locale', 'en')->first()->forceFill(['translated_from_version_hash' => str_repeat('f', 64)])->saveQuietly();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->expectExceptionMessage('iq_article_exact_draft_payload_drift');
        $adapter->draftImport($context);
    }

    public function test_new_identity_collision_fails_before_draft_import(): void
    {
        $this->seedExisting();
        Article::query()->create(['org_id' => 0, 'slug' => 'what-is-iq-and-how-it-is-measured', 'locale' => 'zh-CN', 'title' => 'Operator owns this draft', 'content_md' => 'Operator draft bytes', 'status' => 'draft']);
        $this->expectExceptionMessage('iq_article_new_identity_collision');
        app(IqPublicArticlePromotionAdapter::class)->preflight($this->context());
    }

    public function test_a_new_signed_execution_reuses_already_published_package_without_database_writes(): void
    {
        $this->seedExisting();
        $this->publish();
        $beforeArticles = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeRevisions = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
        $context = new PromotionContext(
            packageDirectory: base_path(IqPublicArticlePackage::PACKAGE), packageSha256: IqPublicArticlePackage::SHA256,
            lane: 'W3', subscope: IqPublicArticlePromotionAdapter::SUBSCOPE, sourceCommit: str_repeat('e', 40),
            executorReleaseSha256: str_repeat('b', 64), releasePolicySha256: $this->context()->releasePolicySha256,
            workflowRunId: '1002', workflowRunAttempt: 1, workflowSignature: str_repeat('c', 64), expectedRowCount: 10, idempotencyKey: str_repeat('f', 64),
        );
        $adapter = app(IqPublicArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        self::assertSame(0, $draft['written_count']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $published = $adapter->publish($context);
        self::assertSame(0, $published['written_count']);
        self::assertNull($published['rollback_reference']);
        self::assertSame($beforeArticles, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame($beforeRevisions, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_a_publication_receipt_write_failure_restores_the_package_owned_publication(): void
    {
        $before = $this->seedExisting();
        [$adapter, $context] = $this->import();
        $path = tempnam(sys_get_temp_dir(), 'iq-existing-receipt-');
        $this->receiptFiles[] = $path;
        file_put_contents($path, 'Operator-owned existing bytes');
        try {
            app(ExactPackagePromotionService::class)->execute($context, 'publish', $path);
            self::fail('An existing receipt destination must not be overwritten.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame('Operator-owned existing bytes', file_get_contents($path));
        foreach ($before as $id => $row) {
            self::assertSame($row['content_md'], Article::query()->findOrFail($id)->content_md);
            self::assertSame((int) $row['working_revision_id'], Article::query()->findOrFail($id)->working_revision_id);
        }
        self::assertSame(0, Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('is_public', true)->count());
    }

    public function test_registered_signed_command_runs_the_four_real_phases_and_binds_receipt_chain(): void
    {
        $this->seedExisting();
        $context = $this->context();
        $key = str_repeat('test-key', 8);
        config(['content_promotion.workflow_identity_key' => $key]);
        foreach ([
            'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => 1, 'expected_row_count' => 10,
            'executor_release_sha256' => $context->executorReleaseSha256, 'release_policy_sha256' => $context->releasePolicySha256,
            'workflow_signature' => hash_hmac('sha256', implode('|', ['content-promotion-v2', $context->sourceCommit, $context->workflowRunId, '1', 'W3', IqPublicArticlePromotionAdapter::SUBSCOPE, $context->packageSha256, $context->releasePolicySha256, '10', $context->executorReleaseSha256]), $key),
        ] as $field => $value) {
            config(['content_promotion.execution.'.$field => $value]);
        }
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'iq-signed-command-');
            unlink($path);
            $this->receiptFiles[] = $path;
            $output = new BufferedOutput;
            $exit = Artisan::call('content:promote-exact-package', [
                '--package' => IqPublicArticlePackage::PACKAGE, '--expected-package-sha256' => $context->packageSha256,
                '--lane' => 'W3', '--subscope' => IqPublicArticlePromotionAdapter::SUBSCOPE, '--phase' => $phase, '--receipt' => $path, '--json' => true,
            ], $output);
            $response = json_decode(trim($output->fetch()), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit, json_encode($response));
            $receipt = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($context->sourceCommit, $receipt['source_commit']);
            self::assertSame($context->workflowRunId, $receipt['workflow_run_id']);
            self::assertSame(10, $receipt['readback_count']);
            self::assertSame(hash_file('sha256', $path), $response['receipt_sha256']);
            self::assertSame($previous === '' ? null : hash_file('sha256', $previous), $receipt['previous_receipt_sha256']);
            $previous = $path;
        }
        self::assertSame(10, Article::query()->where('is_public', true)->count());
    }

    public function test_source_revision_baseline_is_rejected_before_any_write(): void
    {
        $this->seedExisting();
        $article = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $article->publishedRevision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_SOURCE])->saveQuietly();
        self::assertTrue($article->publishedRevision->fresh()->isPubliclyReadableForArticle($article));
        try {
            app(IqPublicArticlePromotionAdapter::class)->preflight($this->context());
            self::fail('Unsupported rollback baseline must fail before import.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_rollback_supported_baseline_required', $error->getMessage());
        }
        self::assertSame(8, Article::query()->count());
        self::assertSame(0, ArticleTranslationRevision::query()->where('authority_package_sha256', IqPublicArticlePackage::SHA256)->count());
        self::assertSame(0, ContentReleaseSnapshot::query()->where('pack_id', 'iq-public-articles')->count());
    }

    public function test_changed_prior_revision_body_aborts_the_entire_rollback(): void
    {
        $before = $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $last = end($before);
        $prior = ArticleTranslationRevision::query()->findOrFail($last['published_revision_id']);
        $prior->forceFill(['content_md' => 'Later old revision change'])->saveQuietly();
        $articles = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        try {
            $adapter->rollback($context, $publication['rollback_reference']);
            self::fail('Altered previous authority must not be re-exposed.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_rollback_prior_revision_drift', $error->getMessage());
        }
        self::assertSame($articles, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame('Later old revision change', $prior->fresh()->content_md);
    }

    public function test_recovery_replay_detects_restored_body_drift(): void
    {
        $before = $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $adapter->rollback($context, $publication['rollback_reference']);
        Article::query()->findOrFail(array_key_first($before))->forceFill(['content_md' => 'Later operator edit'])->saveQuietly();
        $this->expectExceptionMessage('iq_article_rollback_restored_copy_drift');
        $adapter->recoverFailedPublication($context);
    }

    public function test_live_qa_rejects_receipts_with_other_execution_or_changed_digest(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        foreach (['workflow_run_id' => '9999', 'workflow_run_attempt' => 2, 'executor_release_sha256' => str_repeat('e', 64), 'rollback_reference' => 'content-release-snapshot:9999'] as $field => $value) {
            $this->previous($context, 'cms_publication_receipt', $publication);
            $path = config('content_promotion.execution.previous_receipt');
            $receipt = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $receipt[$field] = $value;
            if ($field !== 'rollback_reference') {
                unset($receipt['receipt_content_sha256']);
                $receipt['receipt_content_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($receipt));
            }
            file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR));
            $output = tempnam(sys_get_temp_dir(), 'iq-rejected-qa-');
            unlink($output);
            try {
                app(ExactPackagePromotionService::class)->execute($context, 'live-qa', $output);
                self::fail('An unbound receipt must fail before QA or rollback.');
            } catch (DomainException $error) {
                self::assertSame($field === 'rollback_reference' ? 'iq_article_previous_receipt_digest_invalid' : 'iq_article_execution_identity_mismatch', $error->getMessage());
            }
            self::assertFileDoesNotExist($output);
            self::assertSame(10, $adapter->liveQa($context)['published_count']);
        }
    }

    public function test_executor_release_change_invalidates_the_iq_article_signature(): void
    {
        $context = $this->context();
        $key = str_repeat('test-key', 8);
        config(['content_promotion.workflow_identity_key' => $key]);
        foreach ([
            'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => 1, 'expected_row_count' => 10,
            'executor_release_sha256' => $context->executorReleaseSha256, 'release_policy_sha256' => $context->releasePolicySha256,
            'workflow_signature' => hash_hmac('sha256', implode('|', ['content-promotion-v2', $context->sourceCommit, $context->workflowRunId, '1', 'W3', IqPublicArticlePromotionAdapter::SUBSCOPE, $context->packageSha256, $context->releasePolicySha256, '10', $context->executorReleaseSha256]), $key),
        ] as $field => $value) {
            config(['content_promotion.execution.'.$field => $value]);
        }
        $factory = app(PromotionContextFactory::class);
        self::assertSame($context->executorReleaseSha256, $factory->make(IqPublicArticlePackage::PACKAGE, $context->packageSha256, 'W3', IqPublicArticlePromotionAdapter::SUBSCOPE)->executorReleaseSha256);
        config(['content_promotion.execution.executor_release_sha256' => str_repeat('e', 64)]);
        $this->expectExceptionMessage('workflow_identity_signature_invalid');
        $factory->make(IqPublicArticlePackage::PACKAGE, $context->packageSha256, 'W3', IqPublicArticlePromotionAdapter::SUBSCOPE);
    }

    public function test_same_sha_new_run_reuses_success_without_old_snapshot_recovery_rights(): void
    {
        $this->seedExisting();
        [$adapter, $original] = $this->publish();
        $context = $this->nextContext($original, $original->sourceCommit);
        $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        self::assertSame(0, $draft['written_count']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $published = $adapter->publish($context);
        self::assertSame(0, $published['written_count']);
        self::assertNull($published['rollback_reference']);
        self::assertFalse($adapter->recoverFailedPublication($context));
        self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_failed_sha_stays_terminal_and_a_new_commit_completes_all_four_phases(): void
    {
        $this->seedExisting();
        [$adapter, $original, $publication] = $this->publish();
        $adapter->rollback($original, $publication['rollback_reference']);
        foreach ([$original, $this->nextContext($original, $original->sourceCommit)] as $failedContext) {
            try {
                $adapter->preflight($failedContext);
                self::fail('A failed source SHA must not be retried.');
            } catch (DomainException $error) {
                self::assertSame('iq_article_failed_source_requires_new_commit', $error->getMessage());
            }
        }
        $corrective = $this->nextContext($original, str_repeat('e', 40));
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'iq-corrective-receipt-');
            unlink($path);
            $this->receiptFiles[] = $path;
            $result = app(ExactPackagePromotionService::class)->execute($corrective, $phase, $path);
            self::assertSame(10, $result['receipt']['readback_count']);
            self::assertSame($corrective->sourceCommit, $result['receipt']['source_commit']);
            self::assertSame($corrective->workflowRunId, $result['receipt']['workflow_run_id']);
            $previous = $path;
        }
        self::assertSame(10, $adapter->liveQa($corrective)['published_count']);
        self::assertSame(2, ContentReleaseSnapshot::query()->where('pack_id', 'iq-public-articles')->count());
        self::assertTrue($adapter->recoverFailedPublication($corrective));
    }

    public function test_a_corrective_commit_preserves_operator_hold_and_refuses_owned_body_drift(): void
    {
        $before = $this->seedExisting();
        [$adapter, $original, $publication] = $this->publish();
        $adapter->rollback($original, $publication['rollback_reference']);
        $article = Article::query()->findOrFail(array_key_first($before));
        $article->forceFill(['status' => 'draft', 'is_public' => false])->saveQuietly();
        $context = $this->nextContext($original, str_repeat('e', 40));
        try {
            $adapter->preflight($context);
            self::fail('A corrective commit must preserve operator withdrawal.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_existing_public_authority_required', $error->getMessage());
        }
        self::assertFalse($article->fresh()->is_public);
        $article->forceFill(['status' => 'published', 'is_public' => true])->saveQuietly();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $article->forceFill(['content_md' => 'Later operator edit'])->saveQuietly();
        try {
            $adapter->publish($context);
            self::fail('A changed prestate must abort corrective publication.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_prestate_drift', $error->getMessage());
        }
        self::assertSame('Later operator edit', $article->fresh()->content_md);
    }

    public function test_old_execution_cannot_restore_a_new_corrective_publication_of_the_same_package(): void
    {
        $this->seedExisting();
        [$adapter, $original, $publication] = $this->publish();
        $adapter->rollback($original, $publication['rollback_reference']);
        $corrective = $this->nextContext($original, str_repeat('e', 40));
        $this->previous($corrective, 'content_promotion_preflight_receipt', $adapter->preflight($corrective));
        $this->previous($corrective, 'cms_draft_import_receipt', $adapter->draftImport($corrective));
        $adapter->publish($corrective);
        $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        try {
            $adapter->recoverFailedPublication($original);
            self::fail('Old execution recovery must not restore a newer publication.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_execution_identity_mismatch', $error->getMessage());
        }
        self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame(10, $adapter->liveQa($corrective)['published_count']);
    }

    public function test_every_owned_faq_policy_visibility_and_marker_drift_aborts_recovery(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $seo = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->firstOrFail()->seoMeta;
        $original = $seo->schema_json;
        foreach ([
            'answer_surface_policy' => 'disabled', 'answer_surface_visibility' => 'hidden',
            'iq_article_exact_package_v1.package_sha256' => str_repeat('f', 64),
            'iq_article_exact_package_v1.snapshot_sha256' => str_repeat('f', 64),
        ] as $path => $value) {
            $schema = $original;
            Arr::set($schema, 'editorial_package_v1.'.$path, $value);
            $seo->forceFill(['schema_json' => $schema])->saveQuietly();
            $articles = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
            $revisions = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
            $metas = ArticleSeoMeta::query()->orderBy('id')->get()->map->getAttributes()->all();
            try {
                $adapter->rollback($context, $publication['rollback_reference']);
                self::fail('Later owned FAQ display constraints must not be overwritten.');
            } catch (DomainException $error) {
                self::assertSame('iq_article_rollback_owned_faq_drift', $error->getMessage());
            }
            self::assertSame($articles, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
            self::assertSame($revisions, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
            self::assertSame($metas, ArticleSeoMeta::query()->orderBy('id')->get()->map->getAttributes()->all());
        }
    }

    public function test_old_failed_source_stays_rejected_after_a_corrective_commit_succeeds(): void
    {
        $this->seedExisting();
        [$adapter, $original, $publication] = $this->publish();
        $adapter->rollback($original, $publication['rollback_reference']);
        $corrective = $this->nextContext($original, str_repeat('e', 40));
        $this->previous($corrective, 'content_promotion_preflight_receipt', $adapter->preflight($corrective));
        $this->previous($corrective, 'cms_draft_import_receipt', $adapter->draftImport($corrective));
        $adapter->publish($corrective);
        $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        try {
            $adapter->preflight($original);
            self::fail('Old failed SHA must stay failed after later corrective success.');
        } catch (DomainException $error) {
            self::assertSame('iq_article_failed_source_requires_new_commit', $error->getMessage());
        }
        self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_iq06_operator_withdrawal_in_either_locale_survives_recovery_and_blocks_corrective_publish(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        foreach (['en', 'zh-CN'] as $locale) {
            $article = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', $locale)->firstOrFail();
            DB::transaction(function () use ($article): void {
                $article->forceFill(['status' => 'draft', 'is_public' => false])->save();
                app(\App\Services\Cms\ArticleMaterialDecisionService::class)->recordUnpublished($article, now());
            });
        }
        $adapter->rollback($context, $publication['rollback_reference']);
        $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
        $corrective = $this->nextContext($context, str_repeat('e', 40));
        $this->expectExceptionMessage('iq_article_operator_withdrawal_requires_editorial_action');
        try {
            $adapter->preflight($corrective);
        } finally {
            self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
        }
    }

    public function test_operator_withdrawal_after_package_recovery_creates_distinct_authority_and_blocks_republication(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $adapter->rollback($context, $publication['rollback_reference']);
        foreach (['en', 'zh-CN'] as $locale) {
            $article = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', $locale)->firstOrFail();
            $service = app(\App\Services\Cms\ArticlePublishService::class);
            $service->unpublishArticle((int) $article->id);
            $decision = \App\Models\ContentMaterialDecision::query()->where('authority_subject_key', 'article:'.$article->id)->latest('id')->firstOrFail();
            self::assertNotSame($publication['rollback_reference'], $decision->evidence_ref);
            $service->unpublishArticle((int) $article->id);
            self::assertSame($decision->id, \App\Models\ContentMaterialDecision::query()->where('authority_subject_key', 'article:'.$article->id)->latest('id')->value('id'));
        }
        $this->expectExceptionMessage('iq_article_operator_withdrawal_requires_editorial_action');
        $adapter->preflight($this->nextContext($context, str_repeat('e', 40)));
    }

    public function test_recovery_replay_accepts_faq_object_key_order_but_rejects_list_or_answer_changes(): void
    {
        $before = $this->seedExisting();
        $seo = Article::query()->findOrFail(array_key_first($before))->seoMeta;
        $schema = $seo->schema_json;
        $faqs = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items');
        $faqs[] = ['question' => 'Second original question', 'answer' => 'Second original answer'];
        Arr::set($schema, 'editorial_package_v1.answer_surface_v1.faq_items', $faqs);
        $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        [$adapter, $context, $publication] = $this->publish();
        $adapter->rollback($context, $publication['rollback_reference']);
        $schema = $seo->fresh()->schema_json;
        $faqs = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items');
        Arr::set($schema, 'editorial_package_v1.answer_surface_v1.faq_items', array_map(static fn (array $faq): array => array_reverse($faq, true), $faqs));
        $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        $adapter->rollback($context, $publication['rollback_reference']);
        self::assertSame('Original answer', data_get($seo->fresh()->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items.0.answer'));
        foreach (['order', 'answer', 'missing', 'type'] as $change) {
            $changed = $schema;
            if ($change === 'order') {
                Arr::set($changed, 'editorial_package_v1.answer_surface_v1.faq_items', array_reverse($faqs));
            } elseif ($change === 'missing') {
                unset($changed['editorial_package_v1']['answer_surface_v1']['faq_items'][0]['answer']);
            } else {
                Arr::set($changed, 'editorial_package_v1.answer_surface_v1.faq_items.0.answer', $change === 'type' ? null : 'Later answer');
            }
            $seo->forceFill(['schema_json' => $changed])->saveQuietly();
            try {
                $adapter->rollback($context, $publication['rollback_reference']);
                self::fail('Changed FAQ list order or answer must fail closed.');
            } catch (DomainException $error) {
                self::assertSame('iq_article_rollback_restored_faq_drift', $error->getMessage());
            }
        }
    }

    public function test_recovery_replay_rejects_restored_seo_value_drift(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $adapter->rollback($context, $publication['rollback_reference']);
        ArticleSeoMeta::query()->firstOrFail()->forceFill(['seo_title' => 'Concurrent changed SEO title'])->saveQuietly();

        $this->expectExceptionMessage('iq_article_rollback_restored_seo_drift');
        $adapter->rollback($context, $publication['rollback_reference']);
    }

    public function test_marker_cannot_claim_a_failed_snapshot_after_a_corrective_publication(): void
    {
        $this->seedExisting();
        [$adapter, $original, $publication] = $this->publish();
        $adapter->rollback($original, $publication['rollback_reference']);
        $corrective = $this->nextContext($original, str_repeat('e', 40));
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'iq-corrective-receipt-');
            unlink($path);
            $this->receiptFiles[] = $path;
            app(ExactPackagePromotionService::class)->execute($corrective, $phase, $path);
            $previous = $path;
        }
        foreach (ArticleSeoMeta::query()->get() as $seo) {
            $schema = $seo->schema_json;
            foreach (['source_commit' => $original->sourceCommit, 'workflow_run_id' => $original->workflowRunId, 'workflow_run_attempt' => 1, 'executor_release_sha256' => $original->executorReleaseSha256] as $field => $value) {
                Arr::set($schema, 'editorial_package_v1.iq_article_exact_package_v1.'.$field, $value);
            }
            $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        }
        $this->expectExceptionMessage('iq_article_published_binding_invalid');
        $adapter->liveQa($corrective);
    }

    public function test_json_object_key_order_does_not_change_the_reviewed_snapshot_or_faq_semantics(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->publish();
        foreach (ArticleTranslationRevision::query()->where('authority_package_sha256', IqPublicArticlePackage::SHA256)->get() as $revision) {
            $metadata = $revision->authority_metadata_json;
            $metadata['snapshot'] = array_reverse($metadata['snapshot'], true);
            $metadata['snapshot']['faq_items'] = array_map(static fn (array $faq): array => array_reverse($faq, true), $metadata['snapshot']['faq_items']);
            $revision->forceFill(['authority_metadata_json' => $metadata])->saveQuietly();
        }
        foreach (ArticleSeoMeta::query()->get() as $seo) {
            $schema = $seo->schema_json;
            $faqs = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items');
            Arr::set($schema, 'editorial_package_v1.answer_surface_v1.faq_items', array_map(static fn (array $faq): array => array_reverse($faq, true), $faqs));
            $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        }
        self::assertSame(10, $adapter->liveQa($context)['published_count']);
    }

    public function test_consistent_marker_owner_drift_cannot_replace_the_immutable_snapshot(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->publish();
        foreach (ArticleSeoMeta::query()->get() as $seo) {
            $schema = $seo->schema_json;
            Arr::set($schema, 'editorial_package_v1.iq_article_exact_package_v1.workflow_run_id', '9999');
            $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        }
        $this->expectExceptionMessage('iq_article_published_binding_invalid');
        $adapter->liveQa($context);
    }

    public function test_article_projection_drift_rejects_success_and_rolls_back_no_rows(): void
    {
        $this->seedExisting();
        [$adapter, $context, $publication] = $this->publish();
        $article = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->firstOrFail();
        $attributes = $article->getAttributes();
        foreach (['content_html' => '<p>Later body</p>', 'source_version_hash' => str_repeat('f', 64), 'translated_from_version_hash' => str_repeat('e', 64)] as $field => $value) {
            $article->setRawAttributes($attributes);
            $article->forceFill([$field => $value])->saveQuietly();
            $before = Article::query()->orderBy('id')->get()->map->getAttributes()->all();
            $beforeRevisions = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
            foreach (['liveQa', 'rollback'] as $operation) {
                try {
                    if ($operation === 'rollback') {
                        $adapter->rollback($context, $publication['rollback_reference']);
                    } else {
                        $adapter->liveQa($context);
                    }
                    self::fail('Owned Article projection drift must fail closed: '.$field);
                } catch (DomainException $error) {
                    self::assertSame($operation === 'rollback' ? 'iq_article_rollback_owned_copy_drift' : 'iq_article_published_projection_invalid', $error->getMessage());
                }
                self::assertSame($before, Article::query()->orderBy('id')->get()->map->getAttributes()->all());
                self::assertSame($beforeRevisions, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
            }
        }
        $article->setRawAttributes($attributes)->saveQuietly();
        self::assertSame(10, $adapter->liveQa($context)['published_count']);
    }

    public function test_successful_acceptance_rejects_all_owned_seo_and_faq_marker_drift(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->publish();
        $article = Article::query()->where('locale', 'en')->firstOrFail();
        $seo = $article->seoMeta;
        $attributes = $seo->getAttributes();
        foreach (['og_title', 'og_description', 'answer_surface_policy', 'answer_surface_visibility', 'snapshot_sha256', 'workflow_run_id'] as $field) {
            $seo->setRawAttributes($attributes);
            if (str_starts_with($field, 'og_')) {
                $seo->forceFill([$field => 'Later owned edit']);
            } else {
                $schema = $seo->schema_json;
                $path = in_array($field, ['snapshot_sha256', 'workflow_run_id'], true) ? 'iq_article_exact_package_v1.'.$field : $field;
                Arr::set($schema, 'editorial_package_v1.'.$path, $field === 'snapshot_sha256' ? str_repeat('f', 64) : ($field === 'workflow_run_id' ? '9999' : 'disabled'));
                $seo->forceFill(['schema_json' => $schema]);
            }
            $seo->saveQuietly();
            try {
                $adapter->liveQa($context);
                self::fail('QA must detect later owned SEO/FAQ/marker edits.');
            } catch (DomainException $error) {
                self::assertContains($error->getMessage(), ['iq_article_published_projection_invalid', 'iq_article_published_binding_invalid']);
            }
        }
    }

    public function test_all_reviewed_faqs_reach_the_real_api_and_schema_projection_without_raising_other_limits(): void
    {
        $this->seedExisting();
        [$adapter, $context] = $this->publish();
        $controller = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        $faqProjection = new \ReflectionMethod($controller, 'publicArticleFaqBlocks');
        $seoService = app(\App\Services\Cms\ArticleSeoService::class);
        $schemaProjection = new \ReflectionMethod($seoService, 'buildVisibleFaqPage');
        foreach (app(IqPublicArticlePackage::class)->read(base_path(), IqPublicArticlePackage::SHA256) as $row) {
            $article = Article::query()->with(['seoMeta', 'publishedRevision'])->where($row['identity'])->firstOrFail();
            $faqs = $faqProjection->invoke($controller, $article);
            self::assertSame($row['snapshot']['faq_items'], array_map(static fn (array $item): array => Arr::only($item, ['question', 'answer']), $faqs));
            $schema = $schemaProjection->invoke($seoService, $article, $article->seoMeta, 'https://fermatmind.com/en/articles/'.$article->slug);
            self::assertSame(count($row['snapshot']['faq_items']), count($schema['mainEntity']));
            self::assertSame($row['snapshot']['faq_items'], array_map(static fn (array $item): array => ['question' => $item['name'], 'answer' => $item['acceptedAnswer']['text']], $schema['mainEntity']));
        }
        self::assertSame(10, $adapter->liveQa($context)['readback_count']);
        $article = Article::query()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->firstOrFail();
        $seo = $article->seoMeta;
        $schema = $seo->schema_json;
        unset($schema['editorial_package_v1']['iq_article_exact_package_v1']);
        $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        $article->unsetRelation('seoMeta')->load('seoMeta');
        self::assertCount(6, $faqProjection->invoke($controller, $article));
        self::assertCount(8, $schemaProjection->invoke($seoService, $article, $article->seoMeta, null)['mainEntity']);
    }

    public function test_real_bilingual_http_routes_expose_full_faq_and_current_seo(): void
    {
        $this->seedExisting();
        $this->publish();
        foreach (app(IqPublicArticlePackage::class)->read(base_path(), IqPublicArticlePackage::SHA256) as $row) {
            $base = '/api/v0.5/articles/'.$row['identity']['slug'];
            $query = '?locale='.$row['identity']['locale'].'&org_id=0';
            $response = $this->getJson($base.$query)->assertOk();
            self::assertSame($row['snapshot']['content_md'], $response->json('article.content_md'));
            self::assertSame($row['snapshot']['faq_items'], array_map(static fn (array $item): array => Arr::only($item, ['question', 'answer']), $response->json('answer_surface_v1.faq_blocks')));
            $seo = $this->getJson($base.'/seo'.$query)->assertOk();
            self::assertSame($row['snapshot']['seo_title'], $seo->json('meta.title'));
            self::assertSame($row['snapshot']['seo_description'], $seo->json('meta.description'));
            self::assertSame($row['snapshot']['seo_title'], $seo->json('meta.og.title'));
            self::assertSame($row['snapshot']['seo_description'], $seo->json('meta.og.description'));
            if ($row['identity']['locale'] === 'en' || $row['operation'] === 'new_source_pair') {
                self::assertStringContainsString('noindex', $seo->json('meta.robots'));
            }
        }
    }

    private function nextContext(PromotionContext $previous, string $source): PromotionContext
    {
        return new PromotionContext(
            packageDirectory: $previous->packageDirectory, packageSha256: $previous->packageSha256,
            lane: $previous->lane, subscope: $previous->subscope, sourceCommit: $source,
            executorReleaseSha256: $previous->executorReleaseSha256, releasePolicySha256: $previous->releasePolicySha256,
            workflowRunId: '1002', workflowRunAttempt: 1, workflowSignature: $previous->workflowSignature,
            expectedRowCount: 10, idempotencyKey: hash('sha256', $source),
        );
    }

    private function seedExisting(): array
    {
        $rows = app(IqPublicArticlePackage::class)->read(base_path(), IqPublicArticlePackage::SHA256);
        $sources = [];
        $before = [];
        foreach ($rows as $row) {
            if ($row['operation'] === 'new_source_pair') {
                continue;
            }
            $locale = $row['identity']['locale'];
            $source = $sources[$row['page_id']] ?? null;
            $article = Article::query()->create([
                ...$row['identity'], 'title' => 'Original '.$row['page_id'].' '.$locale, 'excerpt' => 'Original summary', 'content_md' => '## Original body',
                'status' => 'published', 'is_public' => true, 'published_at' => now(),
                'is_indexable' => $locale === 'zh-CN', 'sitemap_eligible' => $locale === 'zh-CN', 'llms_eligible' => $locale === 'zh-CN',
                'translation_status' => $source ? Article::TRANSLATION_STATUS_PUBLISHED : Article::TRANSLATION_STATUS_SOURCE,
                'source_locale' => 'zh-CN', 'source_article_id' => $source?->id, 'translated_from_article_id' => $source?->id,
                'translation_group_id' => 'fixture-'.$row['page_id'],
            ]);
            if ($locale === 'zh-CN') {
                $sources[$row['page_id']] = $article;
            }
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source?->id ?? $article->id,
                'translation_group_id' => $article->translation_group_id, 'locale' => $locale, 'source_locale' => 'zh-CN',
                'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md,
                'source_version_hash' => $article->computeSourceVersionHash(), 'published_at' => now(),
            ]);
            $working = $revision;
            if ($locale === 'en' && in_array($row['page_id'], ['IQ-03', 'IQ-04'], true)) {
                $working = ArticleTranslationRevision::query()->create([
                    'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source->id,
                    'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
                    'revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                    'title' => 'Operator draft', 'content_md' => 'Operator original draft bytes',
                ]);
            }
            $article->forceFill(['working_revision_id' => $working->id, 'published_revision_id' => $revision->id])->saveQuietly();
            ArticleSeoMeta::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'locale' => $locale, 'seo_title' => 'Original SEO', 'seo_description' => 'Original description',
                'og_title' => 'Original OG', 'og_description' => 'Original OG description', 'is_indexable' => $locale === 'zh-CN',
                'canonical_url' => 'https://www.fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/articles/'.$article->slug,
                'robots' => $locale === 'en' ? 'noindex,follow' : 'index,follow',
                'schema_json' => ['editorial_package_v1' => ['answer_surface_v1' => ['notes' => 'protected note', 'faq_items' => [['question' => 'Original question', 'answer' => 'Original answer']]]]],
            ]);
            $before[$article->id] = $article->fresh()->getAttributes();
        }

        return $before;
    }

    private function import(): array
    {
        $context = $this->context();
        $adapter = app(IqPublicArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        self::assertSame(10, $draft['written_count']);
        self::assertSame(2, $draft['created_count']);
        self::assertSame(8, $draft['updated_count']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);

        return [$adapter, $context];
    }

    private function publish(): array
    {
        [$adapter, $context] = $this->import();

        return [$adapter, $context, $adapter->publish($context)];
    }

    private function previous(PromotionContext $context, string $kind, array $result): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iq-article-receipt-');
        $this->receiptFiles[] = $path;
        $receipt = [
            'receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $context->lane, 'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256, 'source_commit' => $context->sourceCommit, 'release_policy_sha256' => $context->releasePolicySha256,
            'expected_count' => 10, 'target_state_sha256' => $result['target_state_sha256'],
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt, 'executor_release_sha256' => $context->executorReleaseSha256,
        ];
        $receipt['receipt_content_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($receipt));
        file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $path]);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(
            packageDirectory: base_path(IqPublicArticlePackage::PACKAGE), packageSha256: IqPublicArticlePackage::SHA256,
            lane: 'W3', subscope: IqPublicArticlePromotionAdapter::SUBSCOPE, sourceCommit: str_repeat('a', 40),
            executorReleaseSha256: str_repeat('b', 64), releasePolicySha256: hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy'))),
            workflowRunId: '1001', workflowRunAttempt: 1, workflowSignature: str_repeat('c', 64), expectedRowCount: 10, idempotencyKey: str_repeat('d', 64),
        );
    }
}
