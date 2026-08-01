<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ContentPromoteExactPackageCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $receiptDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptDirectory = sys_get_temp_dir().'/content-promotion-v2-'.bin2hex(random_bytes(8));
        mkdir($this->receiptDirectory, 0700, true);
        $this->setExecutionEnvironment();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->receiptDirectory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->receiptDirectory);
        foreach ([
            'CONTENT_PROMOTION_SOURCE_COMMIT',
            'CONTENT_PROMOTION_WORKFLOW_RUN_ID',
            'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT',
            'CONTENT_PROMOTION_EXPECTED_ROW_COUNT',
            'CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256',
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256',
            'CONTENT_PROMOTION_WORKFLOW_SIGNATURE',
            'CONTENT_PROMOTION_PREVIOUS_RECEIPT',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function test_exact_package_runs_preflight_import_publish_and_live_qa_with_machine_receipt_chain(): void
    {
        $preflight = $this->runPhase('preflight', 'preflight.json');
        self::assertTrue($preflight['ok']);

        $draft = $this->runPhase('draft-import', 'draft.json');
        self::assertTrue($draft['ok']);
        $draftReceipt = $this->receipt('draft.json');
        self::assertSame('cms_draft_import_receipt', $draftReceipt['receipt_kind']);
        self::assertSame(7, $draftReceipt['expected_count']);
        self::assertSame(7, $draftReceipt['written_count']);
        self::assertSame(7, $draftReceipt['readback_count']);
        self::assertSame(0, $draftReceipt['published_count']);
        self::assertFalse($draftReceipt['server_topology_exposed']);
        self::assertSame(0, $draftReceipt['deploy_mutation_count']);

        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/draft.json');
        $publish = $this->runPhase('publish', 'publish.json');
        self::assertTrue($publish['ok']);
        $publicationReceipt = $this->receipt('publish.json');
        self::assertSame(hash_file('sha256', $this->receiptDirectory.'/draft.json'), $publicationReceipt['previous_receipt_sha256']);
        self::assertSame(7, $publicationReceipt['published_count']);

        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/publish.json');
        $liveQa = $this->runPhase('live-qa', 'live-qa.json');
        self::assertTrue($liveQa['ok']);
        $liveQaReceipt = $this->receipt('live-qa.json');
        self::assertSame('cms_live_qa_receipt', $liveQaReceipt['receipt_kind']);
        self::assertSame(hash_file('sha256', $this->receiptDirectory.'/publish.json'), $liveQaReceipt['previous_receipt_sha256']);
        self::assertSame(7, $liveQaReceipt['published_count']);
        self::assertSame(0, $liveQaReceipt['indexability_mutation_count']);
        self::assertSame(0, $liveQaReceipt['sitemap_mutation_count']);
        self::assertSame(0, $liveQaReceipt['llms_mutation_count']);
        self::assertSame(0, $liveQaReceipt['search_mutation_count']);
        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('review_status', 'automation_published')->count());
    }

    public function test_repeated_dispatch_is_idempotent_and_receipt_destinations_are_immutable(): void
    {
        $this->runPhase('draft-import', 'draft.json');
        $firstTimestamps = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()->where('locale', 'en')->orderBy('slug')->get()
            ->mapWithKeys(static fn (MbtiCrossTypeComparisonAuthority $row): array => [$row->slug => $row->updated_at?->toJSON()])
            ->all();

        $second = $this->runPhase('draft-import', 'draft-replay.json');
        self::assertTrue($second['ok']);
        self::assertSame(0, $this->receipt('draft-replay.json')['written_count']);
        self::assertSame(
            $firstTimestamps,
            MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()->where('locale', 'en')->orderBy('slug')->get()
                ->mapWithKeys(static fn (MbtiCrossTypeComparisonAuthority $row): array => [$row->slug => $row->updated_at?->toJSON()])
                ->all(),
        );

        $blocked = $this->runPhase('draft-import', 'draft.json', expectedExit: 1);
        self::assertFalse($blocked['ok']);
        self::assertSame('receipt_destination_invalid_or_not_immutable', $blocked['error_code']);
    }

    public function test_wrong_sha_path_count_locale_and_unknown_adapter_fail_closed_without_writes(): void
    {
        $wrongSha = $this->runPhase('preflight', 'wrong-sha.json', str_repeat('0', 64), expectedExit: 1);
        self::assertSame('mbti_comparison_exact_package_mismatch', $wrongSha['error_code']);

        $wrongCount = $this->withExpectedCount(8, fn (): array => $this->runPhase('preflight', 'wrong-count.json', expectedExit: 1));
        self::assertSame('mbti_comparison_exact_package_mismatch', $wrongCount['error_code']);

        $unknown = $this->runPhase('preflight', 'unknown.json', lane: 'W2', subscope: 'big-five', expectedExit: 1);
        self::assertSame('adapter_audit_metadata_incompatible', $unknown['error_code']);

        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_live_qa_failure_restores_the_previous_draft_snapshot_and_stops(): void
    {
        $this->runPhase('draft-import', 'draft.json');
        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/draft.json');
        $this->runPhase('publish', 'publish.json');

        $row = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])->firstOrFail();
        $row->forceFill(['title' => '中文泄漏'])->save();

        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/publish.json');
        $result = $this->runPhase('live-qa', 'failed-live-qa.json', expectedExit: 1);
        self::assertSame('live_qa_failed_rollback_succeeded', $result['error_code']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('publish_status', 'published')->count());
        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('publish_status', 'draft')->count());
    }

    public function test_authority_services_reject_forged_automation_context_outside_the_executor(): void
    {
        $forged = $this->forgedAutomationContext();
        try {
            app(MbtiComparisonEnglishPackageImporter::class)->importDraft(
                MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
                MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
                '',
                '',
                $forged,
            );
            self::fail('The importer accepted a forged automation context.');
        } catch (DomainException $exception) {
            self::assertStringStartsWith('automation_workflow_authorization_invalid:', $exception->getMessage());
        }
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());

        $this->runPhase('draft-import', 'trusted-draft.json');
        try {
            app(MbtiComparisonEnglishPublishService::class)->publishAutomated($forged);
            self::fail('The publisher accepted a forged automation context.');
        } catch (DomainException $exception) {
            self::assertStringStartsWith('automation_workflow_authorization_invalid:', $exception->getMessage());
        }
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('publish_status', 'published')->count());
    }

    /** @return array<string, mixed> */
    private function runPhase(
        string $phase,
        string $filename,
        string $sha = MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
        string $lane = 'W1',
        string $subscope = 'mbti-comparisons',
        int $expectedExit = 0,
    ): array {
        $this->setWorkflowSignature($lane, $subscope, $sha);
        $output = new BufferedOutput;
        $exit = Artisan::call('content:promote-exact-package', [
            '--package' => MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
            '--expected-package-sha256' => $sha,
            '--lane' => $lane,
            '--subscope' => $subscope,
            '--phase' => $phase,
            '--receipt' => $this->receiptDirectory.'/'.$filename,
            '--json' => true,
        ], $output);
        $stdout = $output->fetch();
        self::assertSame($expectedExit, $exit, $stdout);
        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        self::assertCount(1, $lines, 'stdout must contain exactly one JSON object.');

        return json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function receipt(string $filename): array
    {
        return json_decode((string) file_get_contents($this->receiptDirectory.'/'.$filename), true, 512, JSON_THROW_ON_ERROR);
    }

    private function setExecutionEnvironment(): void
    {
        $this->setEnv('CONTENT_PROMOTION_SOURCE_COMMIT', str_repeat('a', 40));
        $this->setEnv('CONTENT_PROMOTION_WORKFLOW_RUN_ID', '123456789');
        $this->setEnv('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT', '1');
        $this->setEnv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT', '7');
        $this->setEnv('CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256', str_repeat('b', 64));
        $policy = PromotionContextFactory::canonicalJson((array) config('content_promotion.release_policy'));
        $policySha = hash('sha256', $policy);
        $this->setEnv('CONTENT_PROMOTION_RELEASE_POLICY_SHA256', $policySha);
        $key = str_repeat('test-content-promotion-key-', 2);
        config(['content_promotion.workflow_identity_key' => $key]);
        $this->setWorkflowSignature('W1', 'mbti-comparisons', MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256);
    }

    private function setWorkflowSignature(string $lane, string $subscope, string $packageSha256): void
    {
        $key = (string) config('content_promotion.workflow_identity_key');
        $material = implode('|', [
            'content-promotion-v2',
            (string) env('CONTENT_PROMOTION_SOURCE_COMMIT'),
            (string) env('CONTENT_PROMOTION_WORKFLOW_RUN_ID'),
            (string) env('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT'),
            $lane,
            $subscope,
            $packageSha256,
            (string) env('CONTENT_PROMOTION_RELEASE_POLICY_SHA256'),
            (string) env('CONTENT_PROMOTION_EXPECTED_ROW_COUNT'),
        ]);
        $this->setEnv('CONTENT_PROMOTION_WORKFLOW_SIGNATURE', hash_hmac('sha256', $material, $key));
    }

    /** @return array<string, mixed> */
    private function forgedAutomationContext(): array
    {
        $sourceCommit = (string) env('CONTENT_PROMOTION_SOURCE_COMMIT');
        $policySha256 = (string) env('CONTENT_PROMOTION_RELEASE_POLICY_SHA256');

        return [
            'schema_version' => 'fermatmind.content_promotion_automation_context.v2',
            'lane' => 'W1',
            'subscope' => 'mbti-comparisons',
            'package_sha256' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            'source_repository' => 'fermatmind/fap-api',
            'source_commit' => $sourceCommit,
            'executor_release_sha256' => str_repeat('b', 64),
            'release_policy_sha256' => $policySha256,
            'workflow_run_id' => '123456789',
            'workflow_run_attempt' => 1,
            'workflow_signature' => str_repeat('0', 64),
            'expected_row_count' => 7,
            'idempotency_key' => hash('sha256', implode('|', [
                'content-promotion-v2',
                'W1',
                'mbti-comparisons',
                MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
                $sourceCommit,
                $policySha256,
            ])),
            'cms_draft_import_authorized' => true,
            'public_publish_authorized' => true,
            'indexability_authorized' => false,
            'sitemap_authorized' => false,
            'llms_authorized' => false,
            'search_submission_authorized' => false,
            'deploy_authorized' => false,
        ];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** @template T
     * @param  callable():T  $callback
     * @return T
     */
    private function withExpectedCount(int $count, callable $callback): mixed
    {
        $prior = (string) env('CONTENT_PROMOTION_EXPECTED_ROW_COUNT');
        $this->setEnv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT', (string) $count);
        try {
            return $callback();
        } finally {
            $this->setEnv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT', $prior);
        }
    }
}
