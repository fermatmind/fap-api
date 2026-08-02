<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

require_once __DIR__.'/../../Unit/ContentPromotion/Concerns/AssertsExactPackagePromotionConformance.php';

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\Content\Eq60PackLoader;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use App\Services\ContentImport\MbtiResultEnglishPackageImporter;
use App\Services\ContentImport\RiasecEnglishPackageImporter;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

final class ContentPromoteExactPackageCommandTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;
    use RefreshDatabase;

    private string $receiptDirectory;

    private string $w9Directory;

    private ?string $mbtiResultPackageDirectory = null;

    private ?string $articlePackageDirectory = null;

    /** @var list<string> */
    private array $careerPackageDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptDirectory = sys_get_temp_dir().'/content-promotion-v2-'.bin2hex(random_bytes(8));
        mkdir($this->receiptDirectory, 0700, true);
        $this->w9Directory = sys_get_temp_dir().'/content-promotion-w9-'.bin2hex(random_bytes(8));
        mkdir($this->w9Directory, 0700, true);
        config()->set('content_promotion.w9_authority_root', $this->w9Directory);
        $this->setExecutionEnvironment();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->receiptDirectory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->receiptDirectory);
        if ($this->mbtiResultPackageDirectory !== null) {
            File::deleteDirectory($this->mbtiResultPackageDirectory);
        }
        if ($this->articlePackageDirectory !== null) {
            File::deleteDirectory($this->articlePackageDirectory);
        }
        foreach ($this->careerPackageDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        File::deleteDirectory($this->w9Directory);
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
        self::assertSame(7, $draftReceipt['created_count']);
        self::assertSame(0, $draftReceipt['updated_count']);
        self::assertSame(0, $draftReceipt['unchanged_count']);
        self::assertSame(7, $draftReceipt['readback_count']);
        self::assertSame(0, $draftReceipt['published_count']);
        self::assertFalse($draftReceipt['server_topology_exposed']);
        self::assertSame(0, $draftReceipt['deploy_mutation_count']);

        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/draft.json');
        $publish = $this->runPhase('publish', 'publish.json');
        self::assertTrue($publish['ok']);
        $publicationReceipt = $this->receipt('publish.json');
        $this->assertReceiptChainsFrom($this->receiptDirectory.'/draft.json', $publicationReceipt, 'cms_draft_import_receipt');
        self::assertSame(7, $publicationReceipt['published_count']);

        $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/publish.json');
        $liveQa = $this->runPhase('live-qa', 'live-qa.json');
        self::assertTrue($liveQa['ok']);
        $liveQaReceipt = $this->receipt('live-qa.json');
        self::assertSame('cms_live_qa_receipt', $liveQaReceipt['receipt_kind']);
        $this->assertReceiptChainsFrom($this->receiptDirectory.'/publish.json', $liveQaReceipt, 'cms_publication_receipt');
        self::assertSame(7, $liveQaReceipt['published_count']);
        self::assertSame(0, $liveQaReceipt['indexability_mutation_count']);
        self::assertSame(0, $liveQaReceipt['sitemap_mutation_count']);
        self::assertSame(0, $liveQaReceipt['llms_mutation_count']);
        self::assertSame(0, $liveQaReceipt['search_mutation_count']);
        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('review_status', 'automation_published')->count());
    }

    public function test_mbti_result_adapter_runs_the_full_receipt_chain_against_database_backed_authority(): void
    {
        $this->withExpectedCount(46, function (): void {
            $preflight = $this->runMbtiResultPhase('preflight', 'result-preflight.json');
            self::assertTrue($preflight['ok']);

            $draft = $this->runMbtiResultPhase('draft-import', 'result-draft.json');
            self::assertTrue($draft['ok']);
            self::assertSame(46, $this->receipt('result-draft.json')['written_count']);
            self::assertSame(46, $this->receipt('result-draft.json')['created_count']);
            self::assertSame(0, $this->receipt('result-draft.json')['updated_count']);
            self::assertSame(0, $this->receipt('result-draft.json')['unchanged_count']);
            self::assertSame(0, $this->receipt('result-draft.json')['published_count']);

            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/result-draft.json');
            $publish = $this->runMbtiResultPhase('publish', 'result-publish.json');
            self::assertTrue($publish['ok']);
            $publication = $this->receipt('result-publish.json');
            $this->assertReceiptChainsFrom($this->receiptDirectory.'/result-draft.json', $publication, 'cms_draft_import_receipt');
            self::assertSame(46, $publication['published_count']);

            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/result-publish.json');
            $liveQa = $this->runMbtiResultPhase('live-qa', 'result-live-qa.json');
            self::assertTrue($liveQa['ok']);
            $this->assertReceiptChainsFrom($this->receiptDirectory.'/result-publish.json', $this->receipt('result-live-qa.json'), 'cms_publication_receipt');
            self::assertSame(1, \Illuminate\Support\Facades\DB::table('content_pack_releases')
                ->where('action', 'content_promotion_w1_mbti_results_v2')->count());
            self::assertSame(1, \Illuminate\Support\Facades\DB::table('content_pack_activations')
                ->where('pack_id', 'MBTI.global.en.default')->where('pack_version', 'v0.3')->count());
        });
    }

    public function test_w3_article_adapter_runs_the_full_receipt_chain_against_revision_bound_authority(): void
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'command-w3-article', 'locale' => 'en',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Original command article', 'excerpt' => 'Original excerpt', 'content_md' => 'Original body',
            'status' => 'published', 'is_public' => true, 'is_indexable' => false,
            'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => now(),
        ]);
        $previous = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
            'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en',
            'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash, 'title' => 'Original command article',
            'excerpt' => 'Original excerpt', 'content_md' => 'Original body', 'seo_title' => 'Original SEO',
            'seo_description' => 'Original description', 'published_at' => now(),
        ]);
        $article->forceFill(['published_revision_id' => $previous->id])->saveQuietly();
        $package = $this->articlePackageDirectory();
        $sha = (string) json_decode((string) File::get($package.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['package_sha256'];
        $this->withExpectedCount(1, function () use ($package, $sha): void {
            self::assertTrue($this->runArticlePhase($package, $sha, 'preflight', 'article-preflight.json')['ok']);
            self::assertTrue($this->runArticlePhase($package, $sha, 'draft-import', 'article-draft.json')['ok']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/article-draft.json');
            self::assertTrue($this->runArticlePhase($package, $sha, 'publish', 'article-publish.json')['ok']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/article-publish.json');
            self::assertTrue($this->runArticlePhase($package, $sha, 'live-qa', 'article-live-qa.json')['ok']);
        });
        self::assertSame('Promoted command article', $article->refresh()->title);
        self::assertSame(1, $this->receipt('article-live-qa.json')['published_count']);
        self::assertSame(0, $this->receipt('article-live-qa.json')['sitemap_mutation_count']);
    }

    public function test_career_guide_and_job_adapters_run_independent_machine_receipt_chains(): void
    {
        CareerGuide::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'guide_code' => 'command-guide', 'slug' => 'command-guide', 'locale' => 'en', 'title' => 'Original guide', 'excerpt' => 'Original excerpt', 'category_slug' => 'career', 'body_md' => 'Original guide body.', 'body_html' => '<p>Original guide body.</p>', 'related_industry_slugs_json' => [], 'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'published_at' => now(), 'schema_version' => 'v1', 'sort_order' => 0,
        ]);
        CareerJob::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'job_code' => 'command-job', 'slug' => 'command-job', 'locale' => 'en', 'title' => 'Original job', 'subtitle' => 'Original subtitle', 'excerpt' => 'Original excerpt', 'hero_kicker' => 'Explore', 'hero_quote' => 'Original quote.', 'industry_slug' => 'technology', 'industry_label' => 'Technology', 'body_md' => 'Original job body.', 'body_html' => '<p>Original job body.</p>', 'salary_json' => [], 'outlook_json' => [], 'skills_json' => [], 'work_contents_json' => [], 'growth_path_json' => [], 'fit_personality_codes_json' => [], 'mbti_primary_codes_json' => [], 'mbti_secondary_codes_json' => [], 'riasec_profile_json' => [], 'big5_targets_json' => [], 'iq_eq_notes_json' => [], 'market_demand_json' => [], 'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'published_at' => now(), 'schema_version' => 'v1', 'sort_order' => 0,
        ]);
        foreach ([['guide', 'W3', 'career-guides'], ['job', 'W8', 'career-jobs']] as [$kind, $lane, $subscope]) {
            $package = $this->careerPackageDirectory($kind);
            $sha = (string) json_decode((string) File::get($package.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['package_sha256'];
            $this->withExpectedCount(1, function () use ($package, $sha, $lane, $subscope, $kind): void {
                self::assertTrue($this->runCareerPhase($package, $sha, $lane, $subscope, 'preflight', 'career-'.$kind.'-preflight.json')['ok']);
                self::assertTrue($this->runCareerPhase($package, $sha, $lane, $subscope, 'draft-import', 'career-'.$kind.'-draft.json')['ok']);
                $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/career-'.$kind.'-draft.json');
                self::assertTrue($this->runCareerPhase($package, $sha, $lane, $subscope, 'publish', 'career-'.$kind.'-publish.json')['ok']);
                $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/career-'.$kind.'-publish.json');
                self::assertTrue($this->runCareerPhase($package, $sha, $lane, $subscope, 'live-qa', 'career-'.$kind.'-live-qa.json')['ok']);
                self::assertSame(1, $this->receipt('career-'.$kind.'-live-qa.json')['published_count']);
            });
        }
    }

    public function test_w4_riasec_adapter_runs_the_full_receipt_chain_against_the_exact_english_release_authority(): void
    {
        $package = RiasecEnglishPackageImporter::defaultPackageDirectory();
        $sha = RiasecEnglishPackageImporter::PACKAGE_SHA256;
        $this->withExpectedCount(1550, function () use ($package, $sha): void {
            self::assertTrue($this->runRiasecPhase($package, $sha, 'preflight', 'riasec-preflight.json')['ok']);
            self::assertTrue($this->runRiasecPhase($package, $sha, 'draft-import', 'riasec-draft.json')['ok']);
            self::assertSame(1550, $this->receipt('riasec-draft.json')['written_count']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/riasec-draft.json');
            self::assertTrue($this->runRiasecPhase($package, $sha, 'publish', 'riasec-publish.json')['ok']);
            self::assertSame(1550, $this->receipt('riasec-publish.json')['published_count']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/riasec-publish.json');
            self::assertTrue($this->runRiasecPhase($package, $sha, 'live-qa', 'riasec-live-qa.json')['ok']);
        });
        self::assertSame(1550, $this->receipt('riasec-live-qa.json')['published_count']);
        self::assertSame(0, $this->receipt('riasec-live-qa.json')['deploy_mutation_count']);
    }

    public function test_w7_eq_adapter_runs_the_full_receipt_chain_against_the_exact_compiled_english_result_content_authority(): void
    {
        $loader = app(Eq60PackLoader::class);
        $package = $loader->compiledDir(Eq60PackLoader::PACK_VERSION);
        $sha = $loader->resolveManifestHash(Eq60PackLoader::PACK_VERSION);
        $this->withExpectedCount(2, function () use ($package, $sha): void {
            self::assertTrue($this->runEqPhase($package, $sha, 'preflight', 'eq-preflight.json')['ok']);
            self::assertTrue($this->runEqPhase($package, $sha, 'draft-import', 'eq-draft.json')['ok']);
            self::assertSame(2, $this->receipt('eq-draft.json')['written_count']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/eq-draft.json');
            self::assertTrue($this->runEqPhase($package, $sha, 'publish', 'eq-publish.json')['ok']);
            self::assertSame(2, $this->receipt('eq-publish.json')['published_count']);
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/eq-publish.json');
            self::assertTrue($this->runEqPhase($package, $sha, 'live-qa', 'eq-live-qa.json')['ok']);
        });
        self::assertSame(2, $this->receipt('eq-live-qa.json')['published_count']);
        self::assertSame(0, $this->receipt('eq-live-qa.json')['deploy_mutation_count']);
    }

    public function test_mbti_result_live_qa_failure_restores_only_the_pre_publication_activation(): void
    {
        $this->withExpectedCount(46, function (): void {
            $this->runMbtiResultPhase('draft-import', 'result-failure-draft.json');
            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/result-failure-draft.json');
            $this->runMbtiResultPhase('publish', 'result-failure-publish.json');
            \Illuminate\Support\Facades\DB::table('content_pack_releases')
                ->where('action', 'content_promotion_w1_mbti_results_v2')
                ->update(['manifest_json' => '{}']);

            $this->setEnv('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $this->receiptDirectory.'/result-failure-publish.json');
            $result = $this->runMbtiResultPhase('live-qa', 'result-failure-live-qa.json', expectedExit: 1);

            self::assertSame('live_qa_failed_rollback_succeeded', $result['error_code']);
            self::assertSame(0, \Illuminate\Support\Facades\DB::table('content_pack_activations')
                ->where('pack_id', 'MBTI.global.en.default')->where('pack_version', 'v0.3')->count());
        });
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
        self::assertSame(7, $this->receipt('draft-replay.json')['unchanged_count']);
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
        self::assertSame('personality_promotion_manifest_missing', $unknown['error_code']);

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
    private function runMbtiResultPhase(string $phase, string $filename, int $expectedExit = 0): array
    {
        $packageDirectory = $this->mbtiResultPackageDirectory();
        $sha = (string) (json_decode((string) File::get($packageDirectory.'/package_manifest.json'), true, 512, JSON_THROW_ON_ERROR)['package_sha256'] ?? '');
        $this->setWorkflowSignature('W1', 'mbti-results', $sha);
        $output = new BufferedOutput;
        $exit = Artisan::call('content:promote-exact-package', [
            '--package' => $packageDirectory,
            '--expected-package-sha256' => $sha,
            '--lane' => 'W1',
            '--subscope' => 'mbti-results',
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

    private function mbtiResultPackageDirectory(): string
    {
        if ($this->mbtiResultPackageDirectory !== null) {
            return $this->mbtiResultPackageDirectory;
        }
        $directory = base_path('content_assets/en-content-parity/.testing-mbti-result-'.bin2hex(random_bytes(8)));
        File::copyDirectory(MbtiResultEnglishPackageImporter::defaultPackageDirectory(), $directory);
        $manifest = json_decode((string) File::get($directory.'/package_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $report = [
            'schema_version' => 'fermatmind.en_parity.independent_w9_report.v1',
            'review_kind' => 'independent_w9',
            'verdict' => 'PASS',
            'package_sha256' => $manifest['package_sha256'],
            'lane_id' => 'W1',
            'subscope' => 'mbti-results',
            'reviewed_row_count' => 46,
        ];
        $bytes = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $reportRef = 'mbti-result-command-fixture.json';
        File::put($this->w9Directory.'/'.$reportRef, $bytes);
        $manifest['quality_gates']['independent_w9'] = [
            'status' => 'pass',
            'report_ref' => $reportRef,
            'report_sha256' => hash('sha256', $bytes),
        ];
        File::put($directory.'/package_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return $this->mbtiResultPackageDirectory = $directory;
    }

    private function articlePackageDirectory(): string
    {
        if ($this->articlePackageDirectory !== null) {
            return $this->articlePackageDirectory;
        }
        $directory = base_path('content_assets/en-content-parity/.testing-w3-articles-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $assets = ['assets' => [[
            'identity' => ['org_id' => 0, 'slug' => 'command-w3-article', 'locale' => 'en'],
            'snapshot' => ['title' => 'Promoted command article', 'excerpt' => 'Promoted excerpt', 'content_md' => 'Promoted English body.', 'seo_title' => 'Promoted SEO', 'seo_description' => 'Promoted description'],
        ]]];
        $assetsBytes = json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/assets.json', $assetsBytes);
        $manifest = [
            'schema_version' => 'fermatmind.article_cms_promotion.v2', 'lane' => 'W3', 'subscope' => 'articles', 'locale' => 'en',
            'permissions' => ['cms_draft_import' => false, 'public_publish' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search' => false, 'deploy' => false],
            'expected_row_count' => 1, 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $assetsBytes)]],
        ];
        $sha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($manifest))."\nassets.json\n".hash('sha256', $assetsBytes)."\n");
        $report = ['schema_version' => 'fermatmind.en_parity.independent_w9_report.v1', 'review_kind' => 'independent_w9', 'verdict' => 'PASS', 'package_sha256' => $sha, 'lane_id' => 'W3', 'subscope' => 'articles', 'reviewed_row_count' => 1];
        $reportBytes = json_encode($report, JSON_THROW_ON_ERROR);
        File::put($this->w9Directory.'/w3-article-command-fixture.json', $reportBytes);
        $manifest['quality_gates'] = ['independent_w9' => ['status' => 'pass', 'report_ref' => 'w3-article-command-fixture.json', 'report_sha256' => hash('sha256', $reportBytes)]];
        $manifest['package_sha256'] = $sha;
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->articlePackageDirectory = $directory;
    }

    private function careerPackageDirectory(string $kind): string
    {
        $directory = base_path('content_assets/en-content-parity/.testing-t5-career-'.$kind.'-'.bin2hex(random_bytes(8)));
        $this->careerPackageDirectories[] = $directory;
        File::ensureDirectoryExists($directory);
        $isGuide = $kind === 'guide';
        $snapshot = $isGuide
            ? ['title' => 'Promoted guide', 'excerpt' => 'Promoted excerpt', 'category_slug' => 'career', 'body_md' => 'Promoted guide body.', 'body_html' => null, 'related_industry_slugs_json' => [], 'schema_version' => 'v1', 'sort_order' => 1]
            : ['title' => 'Promoted job', 'subtitle' => 'Promoted subtitle', 'excerpt' => 'Promoted excerpt', 'hero_kicker' => 'Explore', 'hero_quote' => 'Promoted quote.', 'industry_slug' => 'technology', 'industry_label' => 'Technology', 'body_md' => 'Promoted job body.', 'body_html' => null, 'salary_json' => [], 'outlook_json' => [], 'skills_json' => [], 'work_contents_json' => [], 'growth_path_json' => [], 'fit_personality_codes_json' => [], 'mbti_primary_codes_json' => [], 'mbti_secondary_codes_json' => [], 'riasec_profile_json' => [], 'big5_targets_json' => [], 'iq_eq_notes_json' => [], 'market_demand_json' => [], 'schema_version' => 'v1', 'sort_order' => 1];
        $lane = $isGuide ? 'W3' : 'W8';
        $subscope = $isGuide ? 'career-guides' : 'career-jobs';
        $assetsBytes = json_encode(['assets' => [['identity' => ['org_id' => 0, 'slug' => $isGuide ? 'command-guide' : 'command-job', 'locale' => 'en'], 'snapshot' => $snapshot]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/assets.json', $assetsBytes);
        $manifest = ['schema_version' => 'fermatmind.career_cms_promotion.v2', 'lane' => $lane, 'subscope' => $subscope, 'locale' => 'en', 'permissions' => ['cms_draft_import' => false, 'public_publish' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search' => false, 'deploy' => false], 'expected_row_count' => 1, 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $assetsBytes)]]];
        $manifest['package_sha256'] = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($manifest))."\nassets.json\n".hash('sha256', $assetsBytes)."\n");
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        return $directory;
    }

    /** @return array<string,mixed> */
    private function runArticlePhase(string $package, string $sha, string $phase, string $filename): array
    {
        $this->setWorkflowSignature('W3', 'articles', $sha);
        $output = new BufferedOutput;
        $exit = Artisan::call('content:promote-exact-package', [
            '--package' => $package, '--expected-package-sha256' => $sha, '--lane' => 'W3', '--subscope' => 'articles',
            '--phase' => $phase, '--receipt' => $this->receiptDirectory.'/'.$filename, '--json' => true,
        ], $output);
        $stdout = $output->fetch();
        self::assertSame(0, $exit, $stdout);

        return json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function runCareerPhase(string $package, string $sha, string $lane, string $subscope, string $phase, string $filename): array
    {
        $this->setWorkflowSignature($lane, $subscope, $sha);
        $output = new BufferedOutput;
        $exit = Artisan::call('content:promote-exact-package', ['--package' => $package, '--expected-package-sha256' => $sha, '--lane' => $lane, '--subscope' => $subscope, '--phase' => $phase, '--receipt' => $this->receiptDirectory.'/'.$filename, '--json' => true], $output);
        $stdout = $output->fetch();
        self::assertSame(0, $exit, $stdout);
        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        self::assertCount(1, $lines);

        return json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function runRiasecPhase(string $package, string $sha, string $phase, string $filename): array
    {
        return $this->runCareerPhase($package, $sha, 'W4', 'riasec', $phase, $filename);
    }

    /** @return array<string,mixed> */
    private function runEqPhase(string $package, string $sha, string $phase, string $filename): array
    {
        return $this->runCareerPhase($package, $sha, 'W7', 'eq', $phase, $filename);
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
        $configKeys = [
            'CONTENT_PROMOTION_SOURCE_COMMIT' => 'source_commit',
            'CONTENT_PROMOTION_WORKFLOW_RUN_ID' => 'workflow_run_id',
            'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT' => 'workflow_run_attempt',
            'CONTENT_PROMOTION_EXPECTED_ROW_COUNT' => 'expected_row_count',
            'CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256' => 'executor_release_sha256',
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256' => 'release_policy_sha256',
            'CONTENT_PROMOTION_WORKFLOW_SIGNATURE' => 'workflow_signature',
            'CONTENT_PROMOTION_PREVIOUS_RECEIPT' => 'previous_receipt',
        ];
        if (isset($configKeys[$name])) {
            config(['content_promotion.execution.'.$configKeys[$name] => $value]);
        }
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
