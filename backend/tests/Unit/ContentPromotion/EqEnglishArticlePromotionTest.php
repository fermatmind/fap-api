<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublicListReadCache;
use App\Services\ContentPromotion\Adapters\ArticleCmsPromotionAdapter;
use App\Services\ContentPromotion\ArticleCmsPromotionAuthority;
use App\Services\ContentPromotion\EqEnglishArticlePackage;
use App\Services\ContentPromotion\EqPublicArticlePackage;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EqEnglishArticlePromotionTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_PACKAGE = 'd839b7cdbf3b9f41140868a4a29df150ecc2b7b0964b14a9e0a43bde6969f694';

    private ?string $originalMarker = null;

    private string $markerPath;

    private PromotionContext $context;

    private array $receiptFiles = [];

    private bool $isolatedSqliteDriverTest = false;

    protected function beforeRefreshingDatabase(): void
    {
        // This one test exports an in-memory database for real child processes.
        // Other publication and recovery cases keep the suite's native driver.
        if ($this->name() === 'test_signed_driver_executes_real_child_phases_and_recovery_against_isolated_sqlite'
            && config('database.default') !== 'sqlite') {
            $this->isolatedSqliteDriverTest = true;
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->markerPath = base_path(EqPublicArticlePackage::PACKAGE.'/'.EqEnglishArticlePackage::MARKER);
        $this->originalMarker = is_file($this->markerPath) ? file_get_contents($this->markerPath) : null;
        $marker = json_encode([
            'schema' => 'fermatmind.eq_english_article_publication.v1',
            'source_commit' => str_repeat('a', 40), 'staging_source_commit' => str_repeat('8', 40), 'source_package_sha256' => self::SOURCE_PACKAGE,
            'locale' => 'en', 'expected_row_count' => 3,
        ], JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->markerPath, $marker);
        $digest = hash('sha256', 'fermatmind.eq_english_article_publication.v1'."\n".hash('sha256', $marker)."\n".self::SOURCE_PACKAGE."\n");
        $this->context = new PromotionContext(
            packageDirectory: dirname($this->markerPath), packageSha256: $digest,
            lane: 'W3', subscope: 'W3-ARTICLES', sourceCommit: str_repeat('b', 40),
            executorReleaseSha256: str_repeat('c', 64), releasePolicySha256: str_repeat('d', 64),
            workflowRunId: '1234567', workflowRunAttempt: 1, workflowSignature: str_repeat('e', 64),
            expectedRowCount: 3, idempotencyKey: str_repeat('f', 64),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->receiptFiles as $path) {
            unlink($path);
        }
        if ($this->originalMarker === null) {
            unlink($this->markerPath);
        } else {
            file_put_contents($this->markerPath, $this->originalMarker);
        }
        parent::tearDown();
        if ($this->isolatedSqliteDriverTest) {
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_exact_reviewed_english_targets_publish_and_rollback_without_changing_sources_or_other_working_drafts(): void
    {
        $this->seedSources();
        $control = Article::query()->create(['org_id' => 0, 'slug' => 'eq-test-tool-guide', 'locale' => 'en', 'title' => 'User working draft', 'content_md' => 'User original bytes', 'status' => 'draft', 'is_public' => false]);
        $working = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => $control->id, 'source_article_id' => $control->id,
            'translation_group_id' => $control->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
            'revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $control->source_version_hash, 'title' => 'User r2 working title',
            'content_md' => 'User r2 protected bytes',
        ]);
        $control->forceFill(['working_revision_id' => $working->id])->saveQuietly();
        $workingBefore = $working->fresh()->getAttributes();
        $before = Article::query()->get()->map->getAttributes()->keyBy('id')->all();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $preflight = $adapter->preflight($this->context);
        self::assertSame(3, $preflight['readback_count']);
        $this->previous('content_promotion_preflight_receipt', $preflight);
        self::assertSame(3, $adapter->draftImport($this->context)['written_count']);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $import = $adapter->draftImport($this->context);
        self::assertSame(0, $import['written_count']);
        $this->previous('cms_draft_import_receipt', $import);
        $published = $adapter->publish($this->context);
        self::assertSame(3, $published['published_count']);
        self::assertSame(3, $adapter->liveQa($this->context)['readback_count']);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        self::assertSame(0, $adapter->draftImport($this->context)['written_count']);
        foreach (Article::query()->where('locale', 'en')->where('id', '!=', $control->id)->get() as $article) {
            self::assertSame($article->sourceArticle()->translation_group_id, $article->translation_group_id);
            self::assertSame($article->sourceArticle()->source_version_hash, $article->translated_from_version_hash);
            self::assertNull($article->reviewer_name);
            self::assertNull($article->publishedRevision->reviewed_by);
            self::assertNull($article->publishedRevision->reviewed_at);
            self::assertFalse($article->is_indexable);
        }
        $adapter->rollback($this->context, $published['rollback_reference']);
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
        foreach ($before as $id => $attributes) {
            self::assertSame($attributes, Article::query()->findOrFail($id)->getAttributes());
        }
        self::assertSame($workingBefore, $working->fresh()->getAttributes());
        self::assertTrue($adapter->recoverFailedPublication($this->context));
    }

    public function test_source_publication_is_required_before_any_target_is_created(): void
    {
        $this->expectExceptionMessage('eq_english_source_identity_invalid');
        app(ArticleCmsPromotionAuthority::class)->importDraft($this->context);
    }

    public function test_node_and_php_bind_the_same_original_reviews_marker_and_package_digest(): void
    {
        $process = new Process(['node', '--input-type=module', '-e',
            'import {inspectPackage} from "./.github/trunk/eq-new-english-package.mjs";process.stdout.write(JSON.stringify(inspectPackage("backend")));',
        ], dirname(base_path()));
        $process->mustRun();
        $binding = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($this->context->packageSha256, $binding['package_sha256']);
        self::assertSame('W3-ARTICLES', $binding['subscope']);
        self::assertCount(3, app(EqEnglishArticlePackage::class)->read($this->context)['candidates']);
    }

    public function test_deployed_english_driver_rejects_unsigned_input_without_bootstrap_or_writes(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/deploy/run_eq_new_english_article_publish.php')]);
        $process->setInput('{}');
        self::assertSame(1, $process->run());
        $output = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('eq_english_request_invalid', $output['error_code']);
        self::assertNull($output['recovery_completed']);
    }

    public function test_staging_binds_its_own_original_source_publication_commit(): void
    {
        $this->seedSources();
        foreach (Article::query()->where('locale', 'zh-CN')->get() as $article) {
            $revision = $article->publishedRevision;
            $revision->forceFill(['authority_metadata_json' => [...$revision->authority_metadata_json, 'source_commit' => str_repeat('8', 40)]])->saveQuietly();
        }
        $this->app->instance('env', 'staging');
        self::assertSame(str_repeat('8', 40), app(EqEnglishArticlePackage::class)->read($this->context)['source_commit']);
        self::assertCount(3, app(ArticleCmsPromotionAuthority::class)->inspect($this->context)['targets']);
        $this->app->instance('env', 'production');
        $this->expectExceptionMessage('eq_english_source_publication_invalid');
        app(ArticleCmsPromotionAuthority::class)->inspect($this->context);
    }

    public function test_source_commit_drift_rejects_the_whole_batch(): void
    {
        $this->seedSources();
        $revision = Article::query()->where('locale', 'zh-CN')->first()->publishedRevision;
        $revision->forceFill(['authority_metadata_json' => ['source_commit' => str_repeat('9', 40)]])->saveQuietly();
        $this->expectExceptionMessage('eq_english_source_publication_invalid');
        app(ArticleCmsPromotionAuthority::class)->importDraft($this->context);
    }

    public function test_source_revision_identity_and_matching_stale_hash_cannot_authorize_english_import(): void
    {
        $this->seedSources();
        $source = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $revision = $source->publishedRevision;
        $original = $revision->getAttributes();
        foreach (['org_id' => 7, 'locale' => 'en', 'source_locale' => 'en', 'source_article_id' => 999,
            'translation_group_id' => 'foreign-group', 'authority_source_package' => 'foreign-package'] as $field => $value) {
            $revision->forceFill([$field => $value])->saveQuietly();
            try {
                app(ArticleCmsPromotionAuthority::class)->importDraft($this->context);
                self::fail('Changed parent identity must not authorize English import: '.$field);
            } catch (\DomainException $error) {
                self::assertSame('eq_english_source_publication_invalid', $error->getMessage());
                self::assertSame(0, Article::query()->where('locale', 'en')->count());
            } finally {
                $revision->setRawAttributes($original)->saveQuietly();
            }
        }
        $source->forceFill(['source_version_hash' => str_repeat('9', 64)])->saveQuietly();
        $revision->forceFill(['source_version_hash' => str_repeat('9', 64)])->saveQuietly();
        $this->expectExceptionMessage('eq_english_source_publication_invalid');
        app(ArticleCmsPromotionAuthority::class)->importDraft($this->context);
    }

    public function test_an_existing_foreign_english_draft_is_not_overwritten(): void
    {
        $this->seedSources();
        Article::query()->create(['org_id' => 0, 'slug' => 'eq60-score-and-results-guide', 'locale' => 'en', 'title' => 'Foreign draft', 'content_md' => 'Protected original', 'status' => 'draft', 'is_public' => false]);
        $this->expectExceptionMessage('eq_english_foreign_or_held_target');
        app(ArticleCmsPromotionAuthority::class)->importDraft($this->context);
    }

    public function test_preflight_source_or_seo_drift_is_rejected_before_any_target_write(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        Article::query()->where('locale', 'zh-CN')->first()->forceFill(['reviewer_name' => 'Concurrent owner edit'])->saveQuietly();
        $this->expectExceptionMessage('eq_english_prestate_drift');
        $adapter->draftImport($this->context);
    }

    public function test_publish_refuses_a_changed_imported_seo_row(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        Article::query()->where('locale', 'en')->first()->seoMeta->forceFill(['seo_title' => 'Concurrent SEO'])->saveQuietly();
        $this->expectExceptionMessage('eq_english_prestate_drift');
        $adapter->publish($this->context);
    }

    public function test_a_failed_publication_receipt_restores_only_english_targets(): void
    {
        $this->seedSources();
        $before = Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        try {
            app(ExactPackagePromotionService::class)->execute($this->context, 'publish', '');
            self::fail('Receipt failure must fail publication.');
        } catch (\DomainException $error) {
            self::assertSame('eq_english_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
        self::assertSame($before, Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all());
    }

    public function test_signed_driver_executes_real_child_phases_and_recovery_against_isolated_sqlite(): void
    {
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->seedSources();
        $database = null;
        $revisionPath = dirname(base_path()).'/REVISION';
        self::assertFalse(is_link($revisionPath));
        $originalRevision = is_file($revisionPath) ? file_get_contents($revisionPath) : null;
        $runId = (string) random_int(1000000000, 1999999999);
        $execution = $this->context->sourceCommit.'-'.$runId.'-1';
        $receiptRoot = base_path('storage/app/content-promotion/eq-new-english');
        $directory = $receiptRoot.'/'.$execution;
        $target = null;
        try {
            $database = tempnam(sys_get_temp_dir(), 'eq-english-driver-db-');
            if ($database === false) {
                throw new \RuntimeException('eq_english_test_database_unavailable');
            }
            // Copy only the in-memory test database; child processes cannot share it.
            $source = DB::connection()->getPdo();
            $target = new \PDO('sqlite:'.$database);
            $target->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $tables = $source->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_ASSOC);
            $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
            foreach ($tables as $table) {
                $target->exec($table['sql']);
                foreach ($source->query('SELECT * FROM '.$quote($table['name']))->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $statement = $target->prepare('INSERT INTO '.$quote($table['name']).' ('.implode(',', array_map($quote, array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                    $statement->execute(array_values($row));
                }
            }
            foreach ($source->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN) as $sql) {
                $target->exec($sql);
            }
            $sourcesBefore = $target->query("SELECT * FROM articles WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
            $revisionsBefore = $target->query("SELECT * FROM article_translation_revisions WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
            file_put_contents($revisionPath, $this->context->sourceCommit."\n");
            $paths = [
                'app/Console/Commands/ContentPromoteExactPackage.php',
                'app/Services/ContentPromotion/Adapters/ArticleCmsPromotionAdapter.php',
                'app/Services/ContentPromotion/ArticleCmsPromotionAuthority.php',
                'app/Services/ContentPromotion/EqEnglishArticlePackage.php',
                'app/Services/ContentPromotion/PromotionAdapterRegistry.php',
                'app/Services/ContentPromotion/EqPublicArticlePackage.php',
                'app/Services/ContentPromotion/EqSourceExecutionMutex.php',
                'app/Services/ContentPromotion/ExactPackagePromotionService.php',
                'app/Services/ContentPromotion/PromotionContextFactory.php',
                'scripts/deploy/run_eq_new_english_article_publish.php',
            ];
            sort($paths, SORT_STRING);
            $material = '';
            foreach ($paths as $path) {
                $material .= $path."\n".hash_file('sha256', base_path($path))."\n";
            }
            $key = str_repeat('driver-test-key', 4);
            $policy = hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
            $request = [
                'source_commit' => $this->context->sourceCommit, 'workflow_run_id' => $runId,
                'workflow_run_attempt' => 1, 'package_sha256' => $this->context->packageSha256,
                'executor_release_sha256' => hash('sha256', $material), 'release_policy_sha256' => $policy,
                'workflow_signature' => hash_hmac('sha256', implode('|', [
                    'content-promotion-v2', $this->context->sourceCommit, $runId, '1',
                    'W3', ArticleCmsPromotionAuthority::CONTROL_SUBSCOPE, $this->context->packageSha256, $policy, '3',
                ]), $key), 'mode' => 'publish',
            ];
            $environment = [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
                'DB_URL' => false, 'DATABASE_URL' => false, 'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                'APP_CONFIG_CACHE' => $database.'.nonexistent-config.php',
                'CONTENT_PROMOTION_AUTOMATION_KEY' => $key,
            ];
            $execute = static function (array $payload) use ($environment): array {
                $process = new Process([PHP_BINARY, base_path('scripts/deploy/run_eq_new_english_article_publish.php')], base_path(), $environment);
                $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
                $process->setTimeout(60);
                $process->run();
                $output = json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
                self::assertSame(0, $process->getExitCode(), json_encode($output));

                return $output;
            };
            $published = $execute($request);
            self::assertTrue($published['ok']);
            self::assertTrue($published['sanitized']);
            self::assertSame(3, $published['published_count']);
            self::assertSame(['preflight', 'draft-import', 'publish', 'live-qa'], array_column($published['phases'], 'phase'));
            foreach ($published['phases'] as $phase) {
                self::assertSame(3, $phase['readback_count']);
                self::assertSame(hash_file('sha256', $directory.'/'.$phase['phase'].'.json'), $phase['receipt_sha256']);
            }
            self::assertSame(3, (int) $target->query("SELECT COUNT(*) FROM articles WHERE locale = 'en' AND is_public = 1")->fetchColumn());
            self::assertSame($sourcesBefore, $target->query("SELECT * FROM articles WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($revisionsBefore, $target->query("SELECT * FROM article_translation_revisions WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC));
            $recovered = $execute([...$request, 'mode' => 'recover']);
            self::assertTrue($recovered['ok']);
            self::assertTrue($recovered['restored']);
            self::assertSame(0, (int) $target->query("SELECT COUNT(*) FROM articles WHERE locale = 'en' AND is_public = 1")->fetchColumn());
            self::assertSame($sourcesBefore, $target->query("SELECT * FROM articles WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($revisionsBefore, $target->query("SELECT * FROM article_translation_revisions WHERE locale = 'zh-CN' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC));
        } finally {
            $target = null;
            if (is_string($database) && is_file($database)) {
                unlink($database);
            }
            if ($originalRevision === null) {
                if (is_file($revisionPath)) {
                    unlink($revisionPath);
                }
            } else {
                file_put_contents($revisionPath, $originalRevision);
            }
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                if (is_file($directory.'/'.$phase.'.json')) {
                    unlink($directory.'/'.$phase.'.json');
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if (is_file($receiptRoot.'/'.$execution.'.lock')) {
                unlink($receiptRoot.'/'.$execution.'.lock');
            }
        }
    }

    public function test_signed_registered_command_runs_all_four_english_phases(): void
    {
        $this->seedSources();
        $sourcesBefore = Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all();
        $context = $this->context;
        $releasePolicy = hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
        $key = str_repeat('test-key', 8);
        config(['content_promotion.workflow_identity_key' => $key]);
        foreach ([
            'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
            'workflow_run_attempt' => 1, 'expected_row_count' => 3,
            'executor_release_sha256' => $context->executorReleaseSha256,
            'release_policy_sha256' => $releasePolicy,
            'workflow_signature' => hash_hmac('sha256', implode('|', [
                'content-promotion-v2', $context->sourceCommit, $context->workflowRunId, '1',
                'W3', ArticleCmsPromotionAuthority::CONTROL_SUBSCOPE, $context->packageSha256,
                $releasePolicy, '3',
            ]), $key),
        ] as $field => $value) {
            config(['content_promotion.execution.'.$field => $value]);
        }
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'eq-english-signed-command-');
            unlink($path);
            $output = new BufferedOutput;
            $exit = Artisan::call('content:promote-exact-package', [
                '--package' => EqPublicArticlePackage::PACKAGE,
                '--expected-package-sha256' => $context->packageSha256,
                '--lane' => 'W3', '--subscope' => ArticleCmsPromotionAuthority::CONTROL_SUBSCOPE,
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
        self::assertSame(3, Article::query()->where('locale', 'en')->where('is_public', true)->count());
        self::assertSame($sourcesBefore, Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all());
    }

    public function test_failed_live_qa_receipt_restores_english_publication_and_preserves_sources(): void
    {
        $this->seedSources();
        $sourcesBefore = Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all();
        $service = app(ExactPackagePromotionService::class);
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = tempnam(sys_get_temp_dir(), 'eq-english-live-qa-');
            unlink($path);
            $this->receiptFiles[] = $path;
            $service->execute($this->context, $phase, $path);
            $previous = $path;
        }
        self::assertSame(3, Article::query()->where('locale', 'en')->where('is_public', true)->count());
        config(['content_promotion.execution.previous_receipt' => $previous]);
        try {
            $service->execute($this->context, 'live-qa', '');
            self::fail('Live-QA receipt failure must restore the publication.');
        } catch (\DomainException $failure) {
            self::assertSame('eq_english_receipt_failed_rollback_succeeded', $failure->getMessage());
        }
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
        self::assertSame($sourcesBefore, Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all());
    }

    public function test_recovery_refuses_a_concurrent_protected_target_change(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        $published = $adapter->publish($this->context);
        $target = Article::query()->where('locale', 'en')->firstOrFail();
        $target->forceFill(['reviewer_name' => 'Concurrent owner edit'])->saveQuietly();
        $changed = $target->fresh()->getAttributes();
        $sources = Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all();
        try {
            $adapter->rollback($this->context, $published['rollback_reference']);
            self::fail('Recovery must leave concurrent protected data intact.');
        } catch (\DomainException $error) {
            self::assertSame('eq_english_rollback_concurrent_change', $error->getMessage());
        }
        self::assertSame($changed, $target->fresh()->getAttributes());
        self::assertSame($sources, Article::query()->where('locale', 'zh-CN')->get()->map->getAttributes()->all());
        self::assertSame(3, Article::query()->where('locale', 'en')->where('is_public', true)->count());
    }

    public function test_english_cache_invalidation_runs_after_the_publication_transaction_and_failure_restores_targets(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        $level = DB::transactionLevel();
        $manager = Cache::getFacadeRoot();
        $calls = 0;
        $mock = \Mockery::mock($manager)->makePartial();
        Cache::swap($mock);
        $mock->shouldReceive('lock')
            ->with(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':invalidation-lock', 10)
            ->andReturnUsing(function ($key, $seconds) use ($manager, $level, &$calls) {
                self::assertSame($level, DB::transactionLevel());
                $calls++;
                self::assertSame($calls === 1 ? 3 : 0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
                if ($calls === 1) {
                    throw new \RuntimeException('Cache unavailable');
                }

                return $manager->lock($key, $seconds);
            });
        try {
            $adapter->publish($this->context);
            self::fail('Strict cache failure must reject publication.');
        } catch (\DomainException $failure) {
            self::assertSame('eq_english_cache_failed_rollback_succeeded', $failure->getMessage());
        }
        self::assertSame(2, $calls);
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
    }

    public function test_failed_publication_transaction_never_rotates_the_list_generation(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        $mock = \Mockery::mock(Cache::getFacadeRoot())->makePartial();
        Cache::swap($mock);
        $mock->shouldNotReceive('lock')
            ->with(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':invalidation-lock', 10);
        Event::listen('eloquent.saved: '.Article::class, static function (Article $article): void {
            if ($article->locale === 'en' && $article->is_public) {
                throw new \RuntimeException('Publication transaction failed');
            }
        });
        try {
            $adapter->publish($this->context);
            self::fail('Database failure must reject publication.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Publication transaction failed', $failure->getMessage());
        }
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
    }

    public function test_a_false_generation_write_rejects_publication_and_restores_english_targets(): void
    {
        $this->seedSources();
        $adapter = app(ArticleCmsPromotionAdapter::class);
        $this->previous('content_promotion_preflight_receipt', $adapter->preflight($this->context));
        $this->previous('cms_draft_import_receipt', $adapter->draftImport($this->context));
        $manager = Cache::getFacadeRoot();
        $mock = \Mockery::mock($manager)->makePartial();
        Cache::swap($mock);
        $writes = 0;
        $mock->shouldReceive('forever')->with(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation', \Mockery::type('string'))
            ->andReturnUsing(function ($key, $value) use ($manager, &$writes) {
                return ++$writes === 1 ? false : $manager->forever($key, $value);
            });
        try {
            $adapter->publish($this->context);
            self::fail('A failed generation write must reject publication.');
        } catch (\DomainException $failure) {
            self::assertSame('eq_english_cache_failed_rollback_succeeded', $failure->getMessage());
        }
        self::assertSame(2, $writes);
        self::assertSame(0, Article::query()->where('locale', 'en')->where('is_public', true)->count());
    }

    private function previous(string $kind, array $result): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eq-english-receipt-');
        $this->receiptFiles[] = $file;
        file_put_contents($file, json_encode([
            'receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $this->context->lane,
            'subscope' => $this->context->subscope, 'package_sha256' => $this->context->packageSha256,
            'source_commit' => $this->context->sourceCommit, 'release_policy_sha256' => $this->context->releasePolicySha256,
            'expected_count' => 3, 'target_state_sha256' => $result['target_state_sha256'],
            'workflow_run_id' => $this->context->workflowRunId, 'workflow_run_attempt' => $this->context->workflowRunAttempt,
            'executor_release_sha256' => $this->context->executorReleaseSha256,
        ], JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $file]);
    }

    private function seedSources(): void
    {
        $package = app(EqPublicArticlePackage::class)->read(base_path(), self::SOURCE_PACKAGE);
        foreach ($package['candidates'] as $row) {
            if ($row['identity']['locale'] !== 'zh-CN') {
                continue;
            }
            $source = Article::query()->create([
                ...$row['identity'], 'source_locale' => 'zh-CN', 'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'],
                'content_md' => $row['snapshot']['content_md'], 'status' => 'published', 'is_public' => true,
            ]);
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id,
                'translation_group_id' => $source->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
                'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => $source->source_version_hash, 'authority_package_sha256' => self::SOURCE_PACKAGE,
                'authority_source_package' => EqPublicArticlePackage::PACKAGE, 'authority_asset_key' => '0:zh-CN:'.$source->slug,
                'authority_metadata_json' => ['source_commit' => str_repeat('a', 40), 'independent_review_input' => $row['independent_review_input'], 'independent_review_output' => $row['independent_review_output']], ...$row['snapshot'],
            ]);
            $source->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        }
    }
}
