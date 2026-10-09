<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\Adapters\EqNewSourceArticlePromotionAdapter;
use App\Services\ContentPromotion\EqPublicArticlePackage;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class EqNewSourceArticlePromotionAdapterTest extends TestCase
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

    public function test_import_publish_and_rollback_preserve_an_unrelated_english_working_draft(): void
    {
        $control = Article::query()->create(['org_id' => 0, 'slug' => 'eq-test-tool-guide', 'locale' => 'en', 'title' => 'User draft', 'content_md' => 'Unrelated original bytes', 'status' => 'draft', 'is_public' => false]);
        $working = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => $control->id, 'source_article_id' => $control->id,
            'translation_group_id' => $control->translation_group_id, 'locale' => 'en', 'source_locale' => 'en',
            'revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'title' => 'User working r2', 'content_md' => 'User r2 exact bytes',
        ]);
        $control->forceFill(['working_revision_id' => $working->id])->saveQuietly();
        $before = $control->fresh()->getAttributes();
        $beforeWorking = $working->fresh()->getAttributes();
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $preflight = $adapter->preflight($context);
        self::assertSame(1, Article::query()->count());
        $this->previous($context, 'content_promotion_preflight_receipt', $preflight);
        $import = $adapter->draftImport($context);
        self::assertSame(3, $import['written_count']);
        self::assertSame(0, $import['published_count']);
        self::assertSame(3, ArticleTranslationRevision::query()->where('locale', 'zh-CN')->count());
        $this->previous($context, 'cms_draft_import_receipt', $import);
        $published = $adapter->publish($context);
        self::assertSame(3, $published['published_count']);
        self::assertSame(3, $adapter->liveQa($context)['readback_count']);
        foreach (Article::query()->where('locale', 'zh-CN')->get() as $source) {
            self::assertSame($source->working_revision_id, $source->published_revision_id);
            self::assertFalse($source->is_indexable);
            self::assertNull($source->workingRevision->reviewed_by);
            self::assertNull($source->workingRevision->reviewed_at);
        }
        self::assertSame($before, $control->fresh()->getAttributes());
        self::assertSame($beforeWorking, $working->fresh()->getAttributes());
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame(0, Article::query()->where('is_public', true)->count());
        $restored = Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all();
        self::assertTrue($adapter->recoverFailedPublication($context));
        self::assertSame($restored, Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all());
        self::assertSame($before, $control->fresh()->getAttributes());
        self::assertSame($beforeWorking, $working->fresh()->getAttributes());
    }

    public function test_import_rejects_a_changed_native_draft_since_preflight(): void
    {
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $preflight = $adapter->preflight($context);
        $this->previous($context, 'content_promotion_preflight_receipt', $preflight);
        Article::query()->create(['org_id' => 0, 'slug' => 'eq60-score-and-results-guide', 'locale' => 'zh-CN', 'title' => 'Concurrent content', 'content_md' => 'Concurrent bytes', 'status' => 'draft', 'is_public' => false]);
        $this->expectExceptionMessage('eq_source_prestate_drift');
        $adapter->draftImport($context);
    }

    public function test_exact_native_machine_drafts_are_preserved_and_import_is_idempotent(): void
    {
        $context = $this->context();
        $package = app(EqPublicArticlePackage::class)->read(base_path(), $context->packageSha256);
        $originals = [];
        foreach ($package['candidates'] as $row) {
            if ($row['identity']['locale'] !== 'zh-CN') {
                continue;
            }
            $article = Article::query()->create([
                ...$row['identity'], 'title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'],
                'content_md' => $row['snapshot']['content_md'], 'status' => 'draft',
                'is_public' => false, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false,
            ]);
            $native = ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
                'translation_group_id' => $article->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
                'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                ...$row['snapshot'],
            ]);
            $article->forceFill(['working_revision_id' => $native->id])->saveQuietly();
            $originals[$native->id] = $native->fresh()->getAttributes();
        }
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        self::assertSame(3, $adapter->draftImport($context)['written_count']);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        self::assertSame(0, $adapter->draftImport($context)['written_count']);
        self::assertSame(6, ArticleTranslationRevision::query()->count());
        foreach ($originals as $id => $attributes) {
            self::assertSame($attributes, ArticleTranslationRevision::query()->findOrFail($id)->getAttributes());
        }
    }

    public function test_rollback_refuses_a_concurrent_protected_metadata_change(): void
    {
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $import = $adapter->draftImport($context);
        $this->previous($context, 'cms_draft_import_receipt', $import);
        $published = $adapter->publish($context);
        Article::query()->where('locale', 'zh-CN')->first()->seoMeta->forceFill(['seo_title' => 'Concurrent edit'])->saveQuietly();
        $this->expectExceptionMessage('eq_source_rollback_concurrent_change');
        $adapter->rollback($context, $published['rollback_reference']);
    }

    public function test_failed_execution_before_publication_does_not_mutate_drafts(): void
    {
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        self::assertFalse($adapter->recoverFailedPublication($context));
        self::assertSame(0, Article::query()->count());
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $adapter->draftImport($context);
        $before = Article::query()->get()->map->getAttributes()->all();
        self::assertFalse($adapter->recoverFailedPublication($context));
        self::assertSame($before, Article::query()->get()->map->getAttributes()->all());
    }

    public function test_publication_receipt_failure_restores_private_approved_sources(): void
    {
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
        try {
            app(ExactPackagePromotionService::class)->execute($context, 'publish', '');
            self::fail('Receipt destination failure must fail the release.');
        } catch (DomainException $error) {
            self::assertSame('eq_source_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame(0, Article::query()->where('is_public', true)->count());
        self::assertSame(3, ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_APPROVED)->count());
        self::assertTrue($adapter->recoverFailedPublication($context));
    }

    public function test_real_publication_receipt_and_failed_live_api_roll_back_the_exact_sources(): void
    {
        $api = Mockery::mock(ArticleController::class);
        $api->shouldReceive('show')->once()->andReturn(response()->json(['ok' => false], 503));
        app()->instance(ArticleController::class, $api);
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
        $receipt = tempnam(sys_get_temp_dir(), 'eq-published-');
        unlink($receipt);
        $service = app(ExactPackagePromotionService::class);
        $published = $service->execute($context, 'publish', $receipt);
        $this->receiptFiles[] = $receipt;
        self::assertSame(3, $published['receipt']['published_count']);
        self::assertSame(hash_file('sha256', $receipt), $published['receipt_sha256']);
        config(['content_promotion.execution.previous_receipt' => $receipt]);
        try {
            $service->execute($context, 'live-qa', '');
            self::fail('Unavailable public API must fail the release.');
        } catch (DomainException $error) {
            self::assertSame('live_qa_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame(0, Article::query()->where('is_public', true)->count());
        self::assertSame(3, ArticleTranslationRevision::query()->where('revision_status', ArticleTranslationRevision::STATUS_APPROVED)->count());
    }

    public function test_signed_registered_command_runs_all_four_real_phases(): void
    {
        $context = $this->context();
        $key = str_repeat('test-key', 8);
        config(['content_promotion.workflow_identity_key' => $key]);
        foreach ([
            'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
            'workflow_run_attempt' => 1, 'expected_row_count' => 3,
            'executor_release_sha256' => $context->executorReleaseSha256,
            'release_policy_sha256' => $context->releasePolicySha256,
            'workflow_signature' => hash_hmac('sha256', implode('|', [
                'content-promotion-v2', $context->sourceCommit, $context->workflowRunId, '1',
                'W3', EqNewSourceArticlePromotionAdapter::SUBSCOPE, $context->packageSha256,
                $context->releasePolicySha256, '3',
            ]), $key),
        ] as $field => $value) {
            config(['content_promotion.execution.'.$field => $value]);
        }
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'eq-signed-command-');
            unlink($path);
            $output = new BufferedOutput;
            $exit = Artisan::call('content:promote-exact-package', [
                '--package' => EqPublicArticlePackage::PACKAGE,
                '--expected-package-sha256' => $context->packageSha256,
                '--lane' => 'W3', '--subscope' => EqNewSourceArticlePromotionAdapter::SUBSCOPE,
                '--phase' => $phase, '--receipt' => $path, '--json' => true,
            ], $output);
            $response = json_decode(trim($output->fetch()), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit, json_encode($response));
            $this->receiptFiles[] = $path;
            $receipt = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($context->sourceCommit, $receipt['source_commit']);
            self::assertSame($context->workflowRunId, $receipt['workflow_run_id']);
            self::assertSame(3, $receipt['readback_count']);
            self::assertSame(hash_file('sha256', $path), $response['receipt_sha256']);
            self::assertSame($previous === '' ? null : hash_file('sha256', $previous), $receipt['previous_receipt_sha256']);
            $previous = $path;
        }
        self::assertSame(3, Article::query()->where('is_public', true)->where('locale', 'zh-CN')->count());
        self::assertSame(0, Article::query()->where('locale', 'en')->count());
    }

    public function test_import_requires_the_exact_preflight_receipt(): void
    {
        $this->expectException(DomainException::class);
        app(EqNewSourceArticlePromotionAdapter::class)->draftImport($this->context());
    }

    public function test_publish_rejects_changes_after_import_readback(): void
    {
        $context = $this->context();
        $adapter = app(EqNewSourceArticlePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $import = $adapter->draftImport($context);
        $this->previous($context, 'cms_draft_import_receipt', $import);
        Article::query()->where('locale', 'zh-CN')->first()->forceFill(['is_indexable' => true])->saveQuietly();
        $this->expectExceptionMessage('eq_source_prestate_drift');
        $adapter->publish($context);
    }

    private function previous(PromotionContext $context, string $kind, array $result): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eq-source-receipt-');
        $this->receiptFiles[] = $file;
        file_put_contents($file, json_encode([
            'receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $context->lane,
            'subscope' => $context->subscope, 'package_sha256' => $context->packageSha256,
            'source_commit' => $context->sourceCommit, 'release_policy_sha256' => $context->releasePolicySha256,
            'expected_count' => 3, 'target_state_sha256' => $result['target_state_sha256'],
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256,
        ], JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $file]);
    }

    private function context(): PromotionContext
    {
        $bytes = file_get_contents(base_path(EqPublicArticlePackage::PACKAGE.'/assets.json'));
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $proofs = [];
        foreach ($package['candidates'] as $row) {
            foreach (['independent_review_input', 'independent_review_output'] as $field) {
                $proofs[$row[$field]['path']] = $row[$field]['sha256'];
            }
        }
        ksort($proofs);
        $chain = 'fermatmind.eq_public_article_candidates.v1'."\n".hash('sha256', $bytes)."\n";
        foreach ($proofs as $path => $hash) {
            $chain .= $path."\n".$hash."\n";
        }

        return new PromotionContext(
            packageDirectory: base_path(EqPublicArticlePackage::PACKAGE), packageSha256: hash('sha256', $chain),
            lane: 'W3', subscope: EqNewSourceArticlePromotionAdapter::SUBSCOPE,
            sourceCommit: str_repeat('a', 40), executorReleaseSha256: str_repeat('b', 64),
            releasePolicySha256: hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy'))),
            workflowRunId: '1001', workflowRunAttempt: 1, workflowSignature: str_repeat('c', 64),
            expectedRowCount: 3, idempotencyKey: str_repeat('d', 64),
        );
    }
}
