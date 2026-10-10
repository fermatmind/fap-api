<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\Adapters\EqExistingPublicPagePromotionAdapter;
use App\Services\ContentPromotion\EqExistingPublicPagePackage;
use App\Services\ContentPromotion\EqExistingPublicPageState;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

require_once __DIR__.'/EqExistingPublicPageWriterTest.php';

final class EqExistingPublicPagePromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '427f656fc3d6ac40eac4819c749e4962a54df0aca1ce7549ccd2eb12e25896ad';

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_four_phases_use_immutable_bound_journal_and_real_public_api_readback(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $context = $this->context();
        $adapter = app(EqExistingPublicPagePromotionAdapter::class);
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $preflight = $adapter->preflight($context);
        $this->previous($context, 'content_promotion_preflight_receipt', $preflight);
        $draft = $adapter->draftImport($context);
        self::assertSame(0, $draft['written_count']);
        self::assertSame($before, $states->read(self::SHA));
        self::assertSame($draft['rollback_reference'], $adapter->draftImport($context)['rollback_reference']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $draftReceipt = config('content_promotion.execution.previous_receipt');
        $published = $adapter->publish($context);
        self::assertSame(6, $published['published_count']);
        $this->previous($context, 'cms_publication_receipt', $published);
        self::assertSame(6, $adapter->liveQa($context)['readback_count']);
        config(['content_promotion.execution.previous_receipt' => $draftReceipt]);
        $replay = $adapter->publish($context);
        self::assertSame(0, $replay['written_count']);
        self::assertSame($published['rollback_reference'], $replay['rollback_reference']);
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before['registries'], $states->read(self::SHA)['registries']);
        self::assertSame($before['articles'], $states->read(self::SHA)['articles']);
    }

    public function test_cache_failure_after_commit_restores_original_native_authority(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $realCache = app(ScaleRegistryWriter::class);
        $cache = Mockery::mock(ScaleRegistryWriter::class);
        $calls = 0;
        $cache->shouldReceive('invalidatePublicContentProjection')->twice()->andReturnUsing(function ($org, $code, $slugs) use ($realCache, &$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Fixture cache failure');
            }
            $realCache->invalidatePublicContentProjection($org, $code, $slugs);
        });
        $this->app->instance(ScaleRegistryWriter::class, $cache);
        $adapter = app(EqExistingPublicPagePromotionAdapter::class);
        $context = $this->context();
        $this->prepare($adapter, $context);
        try {
            $adapter->publish($context);
            self::fail('Cache failure must not become a successful publication');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_cache_closeout_failed', $error->getMessage());
        }
        $restored = $states->read(self::SHA);
        self::assertSame($before['registries'], $restored['registries']);
        self::assertSame($before['articles'], $restored['articles']);
        self::assertSame(2, $calls);
    }

    public function test_previous_receipt_from_a_different_attempt_cannot_authorize_writes(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $adapter = app(EqExistingPublicPagePromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context), ['workflow_run_attempt' => 2]);
        $this->expectExceptionMessage('eq_existing_previous_execution_mismatch');
        $adapter->draftImport($context);
    }

    public function test_stale_working_draft_prestate_fails_before_publication(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $adapter = app(EqExistingPublicPagePromotionAdapter::class);
        $context = $this->context();
        $this->prepare($adapter, $context);
        ArticleTranslationRevision::query()->where('revision_number', 2)->update(['content_md' => 'Later user r2']);
        $this->expectExceptionMessage('eq_existing_previous_state_drift');
        $adapter->publish($context);
    }

    public function test_signed_service_runs_all_four_phases_and_reads_back_six_native_pages(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $context = $this->signedContext();
        $service = app(ExactPackagePromotionService::class);
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = $this->newReceiptPath();
            $result = $service->execute($context, $phase, $path);
            self::assertSame('SUCCEEDED', $result['receipt']['result']);
            self::assertSame(6, $result['receipt']['readback_count']);
            self::assertSame($context->executorReleaseSha256, $result['receipt']['executor_release_sha256']);
            self::assertSame(hash_file('sha256', $path), $result['receipt_sha256']);
            $previous = $path;
        }
        self::assertSame(6, $result['receipt']['published_count']);
    }

    public function test_service_receipt_failure_restores_original_rows_and_user_working_revision(): void
    {
        EqExistingPublicPageWriterTest::seedNativeTargets();
        $context = $this->signedContext();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $service = app(ExactPackagePromotionService::class);
        foreach (['preflight', 'draft-import'] as $phase) {
            $path = $this->newReceiptPath();
            $service->execute($context, $phase, $path);
            config(['content_promotion.execution.previous_receipt' => $path]);
        }
        $alreadyWritten = tempnam(sys_get_temp_dir(), 'eq-existing-immutable-test-');
        $this->files[] = $alreadyWritten;
        file_put_contents($alreadyWritten, 'Immutable existing receipt');
        try {
            $service->execute($context, 'publish', $alreadyWritten);
            self::fail('Receipt failure must restore the newly published package');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        $after = $states->read(self::SHA);
        foreach (['registries', 'articles'] as $part) {
            self::assertSame($before[$part], $after[$part]);
        }
        self::assertSame('Immutable existing receipt', file_get_contents($alreadyWritten));
    }

    public function test_signed_context_rejects_changed_executor_digest(): void
    {
        $this->signedContext();
        config(['content_promotion.execution.executor_release_sha256' => str_repeat('f', 64)]);
        $this->expectExceptionMessage('workflow_identity_signature_invalid');
        app(PromotionContextFactory::class)->make(EqExistingPublicPagePackage::PACKAGE, self::SHA, 'W3', EqExistingPublicPagePromotionAdapter::SUBSCOPE);
    }

    private function signedContext(): PromotionContext
    {
        $key = str_repeat('fixture-key-', 5);
        $source = str_repeat('a', 40);
        $executor = str_repeat('b', 64);
        $policy = hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
        $material = implode('|', ['content-promotion-v2', $source, '12345', 1, 'W3', EqExistingPublicPagePromotionAdapter::SUBSCOPE, self::SHA, $policy, 6, $executor]);
        config(['content_promotion.workflow_identity_key' => $key,
            'content_promotion.execution.source_commit' => $source,
            'content_promotion.execution.workflow_run_id' => '12345',
            'content_promotion.execution.workflow_run_attempt' => 1,
            'content_promotion.execution.expected_row_count' => 6,
            'content_promotion.execution.executor_release_sha256' => $executor,
            'content_promotion.execution.release_policy_sha256' => $policy,
            'content_promotion.execution.workflow_signature' => hash_hmac('sha256', $material, $key),
            'content_promotion.execution.previous_receipt' => '',
        ]);

        return app(PromotionContextFactory::class)->make(EqExistingPublicPagePackage::PACKAGE, self::SHA, 'W3', EqExistingPublicPagePromotionAdapter::SUBSCOPE);
    }

    private function newReceiptPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eq-existing-service-test-');
        unlink($path);
        $this->files[] = $path;

        return $path;
    }

    private function prepare(EqExistingPublicPagePromotionAdapter $adapter, PromotionContext $context): void
    {
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
    }

    private function previous(PromotionContext $context, string $kind, array $result, array $override = []): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eq-existing-receipt-test-');
        $this->files[] = $path;
        $receipt = ['receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $context->lane, 'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256, 'source_commit' => $context->sourceCommit,
            'release_policy_sha256' => $context->releasePolicySha256, 'expected_count' => 6,
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256, 'target_state_sha256' => $result['target_state_sha256'],
            'rollback_reference' => $result['rollback_reference'], ...$override];
        $receipt['receipt_content_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($receipt));
        file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $path]);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(EqExistingPublicPagePackage::PACKAGE), self::SHA, 'W3', EqExistingPublicPagePromotionAdapter::SUBSCOPE,
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 6, str_repeat('e', 64));
    }
}
