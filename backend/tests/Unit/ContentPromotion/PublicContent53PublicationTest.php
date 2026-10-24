<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\ContentPromotion\Adapters\PublicContent53PromotionAdapter;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use Database\Seeders\ScaleRegistrySeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PublicContent53PublicationTest extends TestCase
{
    use RefreshDatabase;

    private function digest(): string
    {
        $chain = "fermatmind.public_content_53.v1\n";
        foreach (['assets.json', 'independent-review.json', 'enneagram_metrics.en.json', 'enneagram_public_copy.en.json', 'riasec_technical_note.en.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->digest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 50, str_repeat('e', 64));
    }

    public static function environments(): array
    {
        return [['staging'], ['production']];
    }

    public static function receiptFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('environments')]
    public function test_fixed_native_transaction_roundtrip_preserves_drafts_and_qualification(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        $states->assertBaseline($rows);
        $writer = app(PublicContent53Writer::class);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        self::assertSame(50, $published['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $published['state']);
        $topic = $this->getJson('/api/v0.5/topics/big-five?locale=en')->assertOk()->json();
        self::assertSame('/en/tests/big-five-personality-test-ocean-model', $topic['landing_surface_v1']['start_test_target']);
        foreach ($before as $id => $record) {
            self::assertSame($record['values']['is_indexable'], $published['state'][$id]['values']['is_indexable']);
            if ($record['table'] === 'articles') {
                self::assertSame($record['working'], $published['state'][$id]['working']);
            }
        }
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $published['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->digest()));
        $states->assertBaseline($rows);
        // Generated revisions remain immutable history after pointer restoration.
        self::assertGreaterThan(33, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_late_sql_failure_is_atomic_and_concurrent_copy_drift_is_not_overwritten(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/^update [`"]topic_profiles[`"]/i', $query->sql) === 1) {
                $armed = false;
                throw new DomainException('injected_late_failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            self::fail('Must fail');
        } catch (DomainException $e) {
            self::assertSame('injected_late_failure', $e->getMessage());
        }
        self::assertFalse($armed);
        self::assertSame($before, $states->read($this->digest()));
        DB::table('content_pages')->where('slug', 'about')->update(['content_md' => 'Concurrent operator edit']);
        $changed = $states->read($this->digest());
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            self::fail('Must reject concurrent edit');
        } catch (DomainException $e) {
            self::assertSame('public_content_53_native_prestate_drift', $e->getMessage());
        }
        self::assertSame($changed, $states->read($this->digest()));
    }

    public function test_invalid_digest_and_wrong_context_fail_before_any_write(): void
    {
        $this->expectExceptionMessage('public_content_53_digest_mismatch');
        app(PublicContent53Package::class)->read(base_path(), str_repeat('0', 64));
    }

    public function test_cache_revalidation_is_signed_bounded_and_rejects_failed_receipts(): void
    {
        $this->seedTargets();
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-revalidation-secret']);
        Http::preventStrayRequests();
        $reject = 0;
        Http::fake(function ($request) use (&$reject) {
            if ($reject) {
                return Http::response(['ok' => false], $reject);
            }
            $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
            $nonce = $request->header('X-FM-Content-Release-Nonce')[0];
            self::assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), 'test-only-revalidation-secret'),
                $request->header('X-FM-Content-Release-Signature')[0]);
            $payload = $request->data();
            self::assertCount(1, $payload['cache_signal']['paths']);
            $content = $payload['content'];
            $tags = ($content['type'] === 'article') ? ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']] : [];

            return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => $tags]);
        });
        app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
        Http::assertSentCount(42);
        $before = app(PublicContent53State::class)->read($this->digest());
        foreach ([503, 429, 200] as $status) {
            $reject = $status;
            try {
                app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
                self::fail('Must reject failed frontend receipt');
            } catch (DomainException $e) {
                self::assertSame($status === 200 ? 'public_content_53_revalidation_failed' : 'public_content_53_revalidation_http_'.$status, $e->getMessage());
            }
        }
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('private transport details'));
        try {
            app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
            self::fail('Must reject a failed transport');
        } catch (DomainException $e) {
            self::assertSame('public_content_53_revalidation_transport_failed', $e->getMessage());
        }
        Sleep::fake();
        try {
            $limited = true;
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(function ($request) use (&$limited) {
                $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
                $nonce = $request->header('X-FM-Content-Release-Nonce')[0];
                self::assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), 'test-only-revalidation-secret'),
                    $request->header('X-FM-Content-Release-Signature')[0]);
                if ($limited) {
                    $limited = false;

                    return Http::response(['ok' => false, 'error_code' => 'RATE_LIMITED'], 429, ['Retry-After' => '1']);
                }
                $content = $request->data()['content'];
                $tags = $content['type'] === 'article' ? ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']] : [];

                return Http::response(['ok' => true, 'revalidated_paths' => $request->data()['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => $tags]);
            });
            app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
            Http::assertSentCount(43);
            Sleep::assertSleptTimes(1);
            Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 1.0);
            $requests = Http::recorded();
            self::assertNotSame($requests[0][0]->header('X-FM-Content-Release-Nonce'), $requests[1][0]->header('X-FM-Content-Release-Nonce'));
            Sleep::fake();
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response(['ok' => false, 'error_code' => 'RATE_LIMITED'], 429, ['Retry-After' => '600']));
            try {
                app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
                self::fail('A second rate limit must fail closed');
            } catch (DomainException $error) {
                self::assertSame('public_content_53_revalidation_http_429', $error->getMessage());
            }
            Http::assertSentCount(2);
            Sleep::assertSleptTimes(1);
            Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 300.0);
        } finally {
            Sleep::fake(false);
        }
        self::assertSame($before, app(PublicContent53State::class)->read($this->digest()));
    }

    public function test_actual_factory_binds_the_environment_and_executor_to_the_signed_package(): void
    {
        $key = str_repeat('test-key', 8);
        $policy = hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
        $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/public-53-no-cache-'.bin2hex(random_bytes(8)).'.php',
            'CONTENT_PROMOTION_AUTOMATION_KEY' => $key, 'CONTENT_PROMOTION_SOURCE_COMMIT' => str_repeat('a', 40),
            'CONTENT_PROMOTION_WORKFLOW_RUN_ID' => '123456', 'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT' => '1',
            'CONTENT_PROMOTION_EXPECTED_ROW_COUNT' => '50', 'CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256' => str_repeat('b', 64),
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256' => $policy, 'PUBLIC_CONTENT_53_ENVIRONMENT' => 'production'];
        $environment['CONTENT_PROMOTION_WORKFLOW_SIGNATURE'] = hash_hmac('sha256', implode('|', ['content-promotion-v2', str_repeat('a', 40), '123456', '1',
            'W3', 'PUBLIC-CONTENT-53', $this->digest(), $policy, '50', str_repeat('b', 64), 'production']), $key);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (isset($argv[2])) {
    config(['content_promotion.public_content_53_environment' => $argv[2]]);
}
try {
    $context = $app->make(App\Services\ContentPromotion\PromotionContextFactory::class)
        ->make(App\Services\ContentPromotion\PublicContent53Package::PACKAGE, $argv[1], 'W3', 'PUBLIC-CONTENT-53');
    echo $context->executorReleaseSha256;
} catch (DomainException $exception) {
    echo $exception->getMessage();
}
PHP;
        foreach ([[], ['PUBLIC_CONTENT_53_ENVIRONMENT' => 'staging'], ['CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256' => str_repeat('c', 64)]] as $override) {
            $process = new Process([PHP_BINARY, '-r', $script, $this->digest()], base_path(), array_replace($environment, $override));
            $process->mustRun();
            self::assertSame($override === [] ? str_repeat('b', 64) : 'workflow_identity_signature_invalid', $process->getOutput());
        }
        $cached = config()->all();
        $cached['content_promotion']['workflow_identity_key'] = $key;
        $cached['content_promotion']['public_content_53_environment'] = 'production';
        $cachePath = tempnam(sys_get_temp_dir(), 'public53-config-');
        file_put_contents($cachePath, '<?php return '.var_export($cached, true).';');
        chmod($cachePath, 0600);
        $stage = array_replace($environment, ['APP_CONFIG_CACHE' => $cachePath, 'PUBLIC_CONTENT_53_ENVIRONMENT' => 'staging']);
        $stage['CONTENT_PROMOTION_WORKFLOW_SIGNATURE'] = hash_hmac('sha256', implode('|', ['content-promotion-v2', str_repeat('a', 40), '123456', '1',
            'W3', 'PUBLIC-CONTENT-53', $this->digest(), $policy, '50', str_repeat('b', 64), 'staging']), $key);
        try {
            foreach ([[], ['staging']] as $override) {
                $process = new Process([PHP_BINARY, '-r', $script, $this->digest(), ...$override], base_path(), $stage);
                $process->mustRun();
                self::assertSame($override === [] ? 'workflow_identity_signature_invalid' : str_repeat('b', 64), $process->getOutput());
            }
        } finally {
            unlink($cachePath);
        }
    }

    #[DataProvider('receiptFailures')]
    public function test_real_four_phase_service_preserves_state_binding_and_restores_on_receipt_failure(bool $receiptFailure): void
    {
        $this->seedTargets();
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $payload = $request->data();
            $content = $payload['content'];
            $tags = ($content['type'] === 'article') ? ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']] : [];

            return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => $tags]);
        });
        $directory = sys_get_temp_dir().'/public-53-service-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $service = app(ExactPackagePromotionService::class);
        $states = app(PublicContent53State::class);
        $context = $this->context();
        $before = $states->read($this->digest());
        $previous = '';
        $publicationReference = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                if ($receiptFailure && $phase === 'publish') {
                    file_put_contents($path, 'Immutable existing receipt');
                    try {
                        $service->execute($context, $phase, $path);
                        self::fail('Must restore a publication whose receipt cannot be written');
                    } catch (DomainException $error) {
                        self::assertSame('public_content_53_receipt_failed_rollback_succeeded', $error->getMessage());
                    }
                    self::assertSame('Immutable existing receipt', file_get_contents($path));
                    break;
                }
                $result = $service->execute($context, $phase, $path);
                self::assertSame('SUCCEEDED', $result['receipt']['result']);
                self::assertSame(50, $result['receipt']['readback_count']);
                self::assertSame($states->hash($states->read($this->digest())), $result['receipt']['target_state_sha256']);
                self::assertSame(hash_file('sha256', $path), $result['receipt_sha256']);
                if ($phase === 'publish') {
                    $publicationReference = $result['receipt']['rollback_reference'];
                }
                $previous = $path;
            }
            if (! $receiptFailure) {
                app(PublicContent53PromotionAdapter::class)->rollback($context, $publicationReference);
            }
            self::assertSame($before, $states->read($this->digest()));
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function article(array $identity, array $copy, string $group, string $sourceLocale): Article
    {
        $article = Article::query()->create($identity + $copy + ['status' => 'published', 'is_public' => true, 'is_indexable' => false]);
        $article->forceFill(['translation_group_id' => $group, 'source_locale' => $sourceLocale])->saveQuietly();
        $source = $article;
        if ($sourceLocale !== $identity['locale']) {
            $source = Article::query()->create(['org_id' => 0, 'slug' => 'source-'.$article->id, 'locale' => $sourceLocale,
                'title' => 'Source', 'excerpt' => 'Source', 'content_md' => 'Source', 'status' => 'published', 'is_public' => true]);
            $source->forceFill(['translation_group_id' => $group])->saveQuietly();
            $article->forceFill(['source_article_id' => $source->id, 'translated_from_article_id' => $source->id])->saveQuietly();
        }
        $revision = ArticleTranslationRevision::query()->create($copy + ['org_id' => 0, 'article_id' => $article->id,
            'source_article_id' => $source->id, 'translation_group_id' => $group, 'locale' => $article->locale,
            'source_locale' => $sourceLocale, 'revision_number' => 1, 'revision_status' => 'published',
            'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash]);
        $draft = $revision->replicate();
        $draft->forceFill(['revision_number' => 2, 'revision_status' => 'machine_draft', 'content_md' => 'Private operator draft'])->save();
        $article->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $draft->id])->saveQuietly();
        ArticleSeoMeta::query()->create(['article_id' => $article->id, 'org_id' => 0, 'locale' => $article->locale, 'seo_title' => mb_substr($article->title, 0, 60), 'seo_description' => mb_substr($article->excerpt, 0, 160)]);

        return $article;
    }

    private function translationDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_translation.v1\n";
        foreach (['translation-batch.json', 'translation-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    private function translationContext(?int $count = null): PromotionContext
    {
        $count ??= count(app(PublicContent53Package::class)->read(base_path(), $this->translationDigest())['candidates']);

        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->translationDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), $count, str_repeat('e', 64));
    }

    #[DataProvider('environments')]
    public function test_about_translation_roundtrip_preserves_chinese_other_pages_and_divergent_drafts(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->translationDigest())['candidates'];
        $englishIds = [];
        foreach ($rows as $candidate) {
            if ($candidate['kind'] === 'surface') {
                foreach (['en' => PublicContent53Package::before($candidate), 'zh-CN' => PublicContent53Package::source($candidate)] as $locale => $copy) {
                    LandingSurface::query()->updateOrCreate(['org_id' => 0, 'surface_key' => $candidate['identity']['slug'], 'locale' => $locale], $copy + [
                        'title' => 'Existing tests directory', 'schema_version' => '1.0', 'status' => 'published', 'is_public' => true, 'is_indexable' => false,
                    ]);
                }

                continue;
            }
            $target = ContentPage::query()->updateOrCreate($candidate['identity'],
                ['path' => '/en/'.$candidate['identity']['slug'], 'content_html' => ''] + PublicContent53Package::before($candidate) + [
                    'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'review_state' => 'approved',
                    'publish_allowed' => true, 'operator_approval_required' => false, 'claim_gate_status' => 'passed',
                ]);
            $englishIds[] = $target->id;
            ContentPage::query()->updateOrCreate(array_replace($candidate['identity'], ['locale' => 'zh-CN']),
                ['path' => '/zh/'.$candidate['identity']['slug'], 'content_html' => ''] + PublicContent53Package::source($candidate) + [
                    'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'review_state' => 'approved',
                    'publish_allowed' => true, 'operator_approval_required' => false, 'claim_gate_status' => 'passed',
                ]);
        }
        $english = ContentPage::query()->where($rows[0]['identity'])->firstOrFail();
        $otherPages = DB::table('content_pages')->whereNotIn('id', $englishIds)->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all();
        $draft = CmsTranslationRevision::query()->create([
            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $english->id,
            'translation_group_id' => $english->translation_group_id, 'locale' => 'en', 'source_locale' => $english->source_locale,
            'revision_number' => 1, 'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
            'source_version_hash' => $english->source_version_hash, 'payload_json' => ['title' => 'Unpublished operator draft'],
        ]);
        $english->forceFill(['working_revision_id' => $draft->id])->saveQuietly();
        $draftBefore = $draft->fresh()->toArray();
        $chineseSurfaces = DB::table('landing_surfaces')->where('locale', 'zh-CN')->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all();
        $blocks = DB::table('page_blocks')->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->translationDigest());
        $states->assertBaseline($rows);
        $writer = app(PublicContent53Writer::class);
        $after = DB::transaction(fn () => $writer->publish($this->translationContext(), $before));
        self::assertSame(count($rows), $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        self::assertSame($chineseSurfaces, DB::table('landing_surfaces')->where('locale', 'zh-CN')->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all());
        self::assertSame($blocks, DB::table('page_blocks')->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all());
        self::assertSame($otherPages, DB::table('content_pages')->whereNotIn('id', $englishIds)->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all());
        self::assertSame($draftBefore, $draft->fresh()->toArray());
        self::assertSame($draft->id, $english->fresh()->working_revision_id);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->translationContext(), $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->translationContext(), $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->translationDigest()));
        $surface = LandingSurface::query()->where(['org_id' => 0, 'surface_key' => 'tests', 'locale' => 'zh-CN'])->firstOrFail();
        $sourcePayload = $surface->payload_json;
        $driftedPayload = $sourcePayload;
        $driftedPayload['quickStart']['items'][1]['description'] = 'Changed after source review';
        $surface->forceFill(['payload_json' => $driftedPayload])->saveQuietly();
        try {
            $states->read($this->translationDigest());
            self::fail('Concurrent Chinese surface copy must not be overwritten');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_translation_source_drift', $error->getMessage());
        }
        $surface->forceFill(['payload_json' => $sourcePayload])->saveQuietly();
        ContentPage::query()->where(array_replace($rows[0]['identity'], ['locale' => 'zh-CN']))->update(['summary' => 'Changed after review']);
        $this->expectExceptionMessage('public_content_53_translation_source_drift');
        $states->read($this->translationDigest());
    }

    public function test_translation_count_mismatch_is_rejected_before_native_writes(): void
    {
        $this->expectExceptionMessage('public_content_53_writer_context_invalid');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->translationContext(50), []));
    }

    public static function translatedSurfaces(): array
    {
        return [['PAGE-32'], ['PAGE-33'], ['PAGE-34']];
    }

    #[DataProvider('translatedSurfaces')]
    public function test_tests_translation_rejects_machine_changes_even_with_a_rebound_review(string $pageId): void
    {
        $temporary = sys_get_temp_dir().'/fm-tests-translation-'.bin2hex(random_bytes(8));
        $directory = $temporary.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-batch.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-review.json')), true, 64, JSON_THROW_ON_ERROR);
        $index = array_search($pageId, array_column($package['candidates'], 'page_id'), true);
        self::assertIsInt($index);
        if ($pageId === 'PAGE-32') {
            $package['candidates'][$index]['snapshot']['payload_json']['quickStart']['items'][1]['href'] = '/en/unrelated';
        } else {
            $package['candidates'][$index]['snapshot']['payload_json']['allTests']['items'][0]['key'] = 'unrelated-test';
        }
        $bytes = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);
        $proof['pages'][$pageId]['snapshot_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($package['candidates'][$index]['snapshot']));
        file_put_contents($directory.'/translation-batch.json', $bytes);
        file_put_contents($directory.'/translation-review.json', json_encode($proof, JSON_THROW_ON_ERROR));
        try {
            $this->expectExceptionMessage('public_content_53_translation_machine_fields_changed');
            app(PublicContent53Package::class)->read($temporary, $this->translationDigest($temporary));
        } finally {
            unlink($directory.'/translation-batch.json');
            unlink($directory.'/translation-review.json');
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir($temporary);
        }
    }

    public static function invalidTranslationPackages(): array
    {
        return [['excluded'], ['missing-paragraph'], ['unbound-review'], ['empty-title'], ['null-invented'], ['staging-source-unbound'], ['brand-extra-chinese']];
    }

    #[DataProvider('invalidTranslationPackages')]
    public function test_translation_package_rejects_excluded_scope_omission_and_unbound_review(string $case): void
    {
        $temporary = sys_get_temp_dir().'/fm-translation-'.bin2hex(random_bytes(8));
        $directory = $temporary.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-batch.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-review.json')), true, 64, JSON_THROW_ON_ERROR);
        if ($case === 'null-invented') {
            foreach (['source_snapshot', 'before', 'staging_before', 'snapshot'] as $version) {
                $package['candidates'][0][$version]['kicker'] = null;
            }
            $package['candidates'][0]['snapshot']['kicker'] = 'Invented';
        } elseif ($case === 'staging-source-unbound') {
            $package['candidates'][0]['staging_source_snapshot'] = $package['candidates'][0]['source_snapshot'];
        } elseif ($case === 'brand-extra-chinese') {
            $package['candidates'][0]['snapshot']['content_md'] .= '费马测试';
        } elseif ($case === 'excluded') {
            $package['candidates'][0]['identity']['slug'] = 'personality';
        } elseif ($case === 'missing-paragraph') {
            $package['candidates'][0]['snapshot']['content_md'] = preg_replace('/\n\n[^#\n][^\n]+(?=\n\n)/u', '', $package['candidates'][0]['snapshot']['content_md'], 1);
        } elseif ($case === 'empty-title') {
            $package['candidates'][0]['snapshot']['title'] = '';
        }
        $bytes = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);
        $proof['pages'][$package['candidates'][0]['page_id']]['snapshot_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($package['candidates'][0]['snapshot']));
        if ($case === 'unbound-review') {
            $proof['pages'][$package['candidates'][0]['page_id']]['source_sha256'] = str_repeat('0', 64);
        }
        file_put_contents($directory.'/translation-batch.json', $bytes);
        file_put_contents($directory.'/translation-review.json', json_encode($proof, JSON_THROW_ON_ERROR));
        try {
            $this->expectException(DomainException::class);
            app(PublicContent53Package::class)->read($temporary, $this->translationDigest($temporary));
        } finally {
            unlink($directory.'/translation-batch.json');
            unlink($directory.'/translation-review.json');
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir($temporary);
        }
    }

    private function riasecAmendmentDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_article_amendment.v1\n";
        foreach (['riasec-amendment.json', 'riasec-amendment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    private function seedRiasecAmendment(array $rows, string $environment, bool $candidateBody = false): void
    {
        $sources = [];
        foreach ($rows as $row) {
            $identity = $environment === 'staging' ? $row['staging_source_identity'] : $row['source_identity'];
            $article = $this->article($row['identity'], $candidateBody ? $row['snapshot'] : PublicContent53Package::before($row), $identity['translation_group_id'], $identity['source_locale']);
            if ($row['enable_hreflang']) {
                $article->forceFill(['is_indexable' => true])->saveQuietly();
            }
            if ($article->locale === 'zh-CN') {
                $sources[$article->translation_group_id] = $article;
            } elseif ($identity['source_locale'] !== $article->locale) {
                $source = $sources[$article->translation_group_id];
                $article->forceFill(['source_article_id' => $source->id, 'translated_from_article_id' => $source->id])->saveQuietly();
                ArticleTranslationRevision::query()->where('article_id', $article->id)->update(['source_article_id' => $source->id]);
            }
        }
    }

    #[DataProvider('environments')]
    public function test_riasec_amendment_preserves_drafts_flags_and_seo_through_restore_and_new_owner(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->riasecAmendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        self::assertCount(4, $rows);
        $this->seedRiasecAmendment($rows, $environment);
        $other = $this->article(['org_id' => 0, 'slug' => 'unselected-article', 'locale' => 'en'],
            ['title' => 'Unselected', 'excerpt' => 'Keep me', 'content_md' => 'Original'], 'other-group', 'en');
        $untouched = $other->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 4, str_repeat('e', 64));
        $writer = app(PublicContent53Writer::class);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(4, $after['written_count']);
        $states->assertPublished($rows, $after['state']);
        foreach ($rows as $row) {
            $id = $row['page_id'];
            self::assertSame($before[$id]['working'], $after['state'][$id]['working']);
            if ($row['enable_hreflang']) {
                $schema = json_decode($after['state'][$id]['relations']['article_seo_meta'][0]['schema_json'], true);
                self::assertTrue(data_get($schema, 'editorial_package_v1.hreflang_gate_v1.enabled'));
                $oldSeo = $before[$id]['relations']['article_seo_meta'][0];
                $newSeo = $after['state'][$id]['relations']['article_seo_meta'][0];
                unset($oldSeo['schema_json'], $oldSeo['updated_at'], $newSeo['schema_json'], $newSeo['updated_at']);
                self::assertSame($oldSeo, $newSeo);
            } else {
                self::assertSame($before[$id]['relations'], $after['state'][$id]['relations']);
            }
            self::assertSame($before[$id]['values']['is_indexable'], $after['state'][$id]['values']['is_indexable']);
        }
        self::assertSame($untouched, $other->fresh()->toArray());
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        $newOwner = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), 4, str_repeat('e', 64));
        self::assertSame(4, DB::transaction(fn () => $writer->publish($newOwner, $before))['written_count']);
    }

    public static function legacyArticleBodies(): array
    {
        return [['staging', 'headings'], ['staging', 'newlines'], ['production', 'headings'], ['production', 'newlines']];
    }

    #[DataProvider('legacyArticleBodies')]
    public function test_riasec_legacy_native_markdown_matches_reader_but_real_copy_drift_is_rejected(string $environment, string $format): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->riasecAmendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $this->seedRiasecAmendment($rows, $environment);
        $article = Article::query()->where($rows[0]['identity'])->firstOrFail();
        $body = PublicContent53Package::before($rows[0])['content_md'];
        $native = $format === 'headings' ? preg_replace('/^## /m', '# ', $body) : str_replace("\n", "\r\n", $body);
        self::assertNotSame($body, $native);
        DB::table('article_translation_revisions')->where('id', $article->published_revision_id)->update(['content_md' => $native]);
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 4, str_repeat('e', 64));
        $writer = app(PublicContent53Writer::class);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(4, $after['written_count']);
        $states->assertPublished($rows, $after['state']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        self::assertSame($native, DB::table('article_translation_revisions')->where('id', $article->published_revision_id)->value('content_md'));
        DB::table('article_translation_revisions')->where('id', $article->published_revision_id)->update(['content_md' => $native."\nUnreviewed copy change"]);
        $drifted = $states->read($sha);
        try {
            DB::transaction(fn () => $writer->publish($context, $drifted));
            self::fail('Visible copy drift must remain rejected');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_locked_copy_drift', $error->getMessage());
        }
        self::assertSame($drifted, $states->read($sha));
    }

    public static function riasecHreflangOnlyOutcomes(): array
    {
        return [['success', 'riasec'], ['cache', 'riasec'], ['receipt', 'riasec'], ['success', 'ai'], ['cache', 'ai'], ['receipt', 'ai']];
    }

    #[DataProvider('riasecHreflangOnlyOutcomes')]
    public function test_reviewed_hreflang_only_write_is_counted_and_restored_on_closeout_failure(string $outcome, string $package): void
    {
        config(['content_promotion.public_content_53_environment' => 'production',
            'ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        $sha = $package === 'ai' ? $this->amendmentDigest() : $this->riasecAmendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $this->seedRiasecAmendment($rows, 'production', true);
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $revisions = ArticleTranslationRevision::query()->count();
        $failed = false;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($outcome, &$failed) {
            if ($outcome === 'cache' && ! $failed) {
                $failed = true;

                return Http::response(['ok' => false], 500);
            }
            $payload = $request->data();
            $content = $payload['content'];
            $tags = ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']];

            return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'],
                'rejected_paths' => [], 'invalidated_tags' => $tags]);
        });
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        $directory = sys_get_temp_dir().'/riasec-hreflang-only-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $service = app(ExactPackagePromotionService::class);
        $previous = '';
        try {
            foreach (['preflight', 'draft-import'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $previous = $service->execute($context, $phase, $directory.'/'.$phase.'.json')['path'];
            }
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = $directory.'/publish.json';
            if ($outcome === 'receipt') {
                file_put_contents($path, 'Immutable existing receipt');
            }
            try {
                $result = $service->execute($context, 'publish', $path);
                self::assertSame('success', $outcome);
                self::assertSame(2, $result['receipt']['written_count']);
                self::assertSame(count($rows), $result['receipt']['readback_count']);
                app(PublicContent53PromotionAdapter::class)->rollback($context, $result['receipt']['rollback_reference']);
            } catch (DomainException $error) {
                self::assertSame($outcome === 'cache' ? 'public_content_53_cache_closeout_failed'
                    : 'public_content_53_receipt_failed_rollback_succeeded', $error->getMessage());
                self::assertNotSame('success', $outcome);
            }
            if ($outcome === 'cache') {
                self::assertTrue($failed);
            }
            self::assertSame($before, $states->read($sha));
            self::assertSame($revisions, ArticleTranslationRevision::query()->count());
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function riasecPublicationFailures(): array
    {
        return [['hold'], ['noindex'], ['drift']];
    }

    #[DataProvider('riasecPublicationFailures')]
    public function test_riasec_amendment_failure_preserves_the_complete_batch(string $case): void
    {
        config(['content_promotion.public_content_53_environment' => 'production']);
        $sha = $this->riasecAmendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $this->seedRiasecAmendment($rows, 'production');
        $target = Article::query()->where('slug', 'mbti-vs-holland-career-choice')->firstOrFail();
        if ($case === 'hold') {
            $target->seoMeta->forceFill(['schema_json' => ['editorial_package_v1' => ['hreflang_hold' => true]]])->saveQuietly();
        } elseif ($case === 'noindex') {
            $target->forceFill(['is_indexable' => false])->saveQuietly();
        }
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        if ($case === 'drift') {
            $target->forceFill(['content_md' => 'Concurrent operator edit'])->saveQuietly();
        }
        $expected = $states->read($sha);
        $revisionCount = ArticleTranslationRevision::query()->count();
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 4, str_repeat('e', 64));
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
            self::fail('Held, non-indexable or concurrently changed pairs must fail atomically');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_'.match ($case) {
                'hold' => 'hreflang_operator_hold', 'noindex' => 'hreflang_pair_invalid', 'drift' => 'native_prestate_drift',
            }, $error->getMessage());
        }
        self::assertSame($expected, $states->read($sha));
        self::assertSame($revisionCount, ArticleTranslationRevision::query()->count());
    }

    public function test_riasec_amendment_rejects_locale_scope_nonbody_and_review_drift(): void
    {
        $root = sys_get_temp_dir().'/riasec-amendment-'.bin2hex(random_bytes(6));
        $directory = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/riasec-amendment.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/riasec-amendment-review.json')), true);
        try {
            foreach (['locale', 'slug', 'missing-locale', 'nonbody', 'extra-field', 'review'] as $case) {
                $candidate = $package;
                $review = $proof;
                if ($case === 'locale') {
                    $candidate['candidates'][0]['identity']['locale'] = 'en';
                } elseif ($case === 'slug') {
                    $candidate['candidates'][1]['identity']['slug'] = 'riasec-holland-career-interest-test-explained';
                } elseif ($case === 'missing-locale') {
                    array_pop($candidate['candidates']);
                    array_pop($review['pages']);
                } elseif ($case === 'nonbody') {
                    $candidate['candidates'][0]['snapshot']['excerpt'] = 'Changed excerpt';
                } elseif ($case === 'extra-field') {
                    $candidate['candidates'][0]['snapshot']['is_indexable'] = true;
                }
                $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE);
                $review['candidate_sha256'] = $case === 'review' ? str_repeat('0', 64) : hash('sha256', $bytes);
                file_put_contents($directory.'/riasec-amendment.json', $bytes);
                file_put_contents($directory.'/riasec-amendment-review.json', json_encode($review));
                try {
                    app(PublicContent53Package::class)->read($root, $this->riasecAmendmentDigest($root));
                    self::fail('Invalid bilingual amendment must be rejected');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_amendment_', $error->getMessage());
                }
            }
        } finally {
            unlink($directory.'/riasec-amendment.json');
            unlink($directory.'/riasec-amendment-review.json');
            rmdir($directory);
            rmdir($root.'/content_assets');
            rmdir($root);
        }
    }

    private function amendmentDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_article_amendment.v1\n";
        foreach (['article-amendment.json', 'article-amendment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    #[DataProvider('environments')]
    public function test_article_amendment_preserves_other_pages_drafts_and_flags_and_can_restore(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->amendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        self::assertCount(2, $rows);
        $this->seedRiasecAmendment($rows, $environment);
        $articles = [];
        foreach ($rows as $row) {
            $articles[$row['page_id']] = Article::query()->where($row['identity'])->firstOrFail();
            $articles[$row['page_id']]->seoMeta->forceFill(['schema_json' => [
                'editorial_package_v1' => ['faq_schema_enabled' => false],
                'faq' => [['question' => 'Keep FAQ?', 'answer' => 'Keep this answer.']],
            ]])->saveQuietly();
        }
        $other = $this->article(['org_id' => 0, 'slug' => 'unselected-article', 'locale' => 'en'],
            ['title' => 'Unselected', 'excerpt' => 'Keep me', 'content_md' => 'Keep original body'], 'other-group', 'en');
        $otherBefore = $other->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        $writer = app(PublicContent53Writer::class);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(2, $after['written_count']);
        foreach ($rows as $row) {
            $id = $row['page_id'];
            self::assertSame($row['snapshot']['content_md'], $articles[$id]->fresh()->content_md);
            self::assertSame($before[$id]['working'], $after['state'][$id]['working']);
            self::assertSame($before[$id]['published'], $after['state'][$id]['published']);
            self::assertSame($before[$id]['values'], $after['state'][$id]['values']);
            $oldSeo = $before[$id]['relations']['article_seo_meta'][0];
            $newSeo = $after['state'][$id]['relations']['article_seo_meta'][0];
            $schema = json_decode($newSeo['schema_json'], true);
            self::assertTrue(data_get($schema, 'editorial_package_v1.hreflang_gate_v1.enabled'));
            unset($schema['editorial_package_v1']['hreflang_gate_v1']);
            self::assertSame(json_decode($oldSeo['schema_json'], true), $schema);
            unset($oldSeo['schema_json'], $oldSeo['updated_at'], $newSeo['schema_json'], $newSeo['updated_at']);
            self::assertSame($oldSeo, $newSeo);
        }
        $states->assertPublished($rows, $after['state']);
        self::assertSame($otherBefore, $other->fresh()->toArray());
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        $newOwner = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        self::assertSame(2, DB::transaction(fn () => $writer->publish($newOwner, $before))['written_count']);
    }

    public function test_article_amendment_rolls_back_second_gate_failure_and_rejects_concurrent_edits(): void
    {
        $sha = $this->amendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $this->seedRiasecAmendment($rows, 'production');
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        $updated = 0;
        DB::listen(function ($query) use (&$updated): void {
            if (preg_match('/^update [`"]article_seo_meta[`"]/i', $query->sql) === 1 && ++$updated === 2) {
                throw new DomainException('injected_second_gate_failure');
            }
        });
        $writer = app(PublicContent53Writer::class);
        try {
            DB::transaction(fn () => $writer->publish($context, $before));
            self::fail('Second locale failure must roll back the first locale');
        } catch (DomainException $error) {
            self::assertSame('injected_second_gate_failure', $error->getMessage());
        }
        self::assertSame(2, $updated);
        self::assertSame($before, $states->read($sha));
        DB::table('article_translation_revisions')->where('id', $before[$rows[0]['page_id']]['published']['id'])->update(['content_md' => 'Concurrent operator edit']);
        $changed = $states->read($sha);
        try {
            DB::transaction(fn () => $writer->publish($context, $before));
            self::fail('Concurrent content must be preserved');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        self::assertSame($changed, $states->read($sha));
    }

    public static function aiGateFailures(): array
    {
        return [['hold'], ['noindex'], ['unpublished'], ['missing-revision']];
    }

    #[DataProvider('aiGateFailures')]
    public function test_article_amendment_gate_rejects_ineligible_pair_without_writes(string $case): void
    {
        config(['content_promotion.public_content_53_environment' => 'production']);
        $sha = $this->amendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $this->seedRiasecAmendment($rows, 'production');
        $target = Article::query()->where($rows[1]['identity'])->firstOrFail();
        if ($case === 'hold') {
            $target->seoMeta->forceFill(['schema_json' => ['editorial_package_v1' => ['hreflang_hold' => true]]])->saveQuietly();
        } elseif ($case === 'noindex') {
            $target->forceFill(['is_indexable' => false])->saveQuietly();
        } elseif ($case === 'unpublished') {
            $target->forceFill(['status' => 'draft'])->saveQuietly();
        } else {
            $target->forceFill(['published_revision_id' => null])->saveQuietly();
        }
        $articles = Article::query()->orderBy('id')->get()->toArray();
        $seo = ArticleSeoMeta::query()->orderBy('id')->get()->toArray();
        $revisions = ArticleTranslationRevision::query()->orderBy('id')->get()->toArray();
        try {
            $states = app(PublicContent53State::class);
            $before = $states->read($sha);
            $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
                str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 2, str_repeat('e', 64));
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
            self::fail('Ineligible pair must be rejected');
        } catch (DomainException $error) {
            self::assertStringStartsWith('public_content_53_', $error->getMessage());
        }
        self::assertSame($articles, Article::query()->orderBy('id')->get()->toArray());
        self::assertSame($seo, ArticleSeoMeta::query()->orderBy('id')->get()->toArray());
        self::assertSame($revisions, ArticleTranslationRevision::query()->orderBy('id')->get()->toArray());
    }

    public function test_article_amendment_rejects_nonbody_changes_and_unreviewed_bytes(): void
    {
        $root = sys_get_temp_dir().'/public-content-amendment-'.bin2hex(random_bytes(6));
        $directory = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/article-amendment.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/article-amendment-review.json')), true);
        try {
            foreach (['nonbody', 'identity', 'extra-field', 'review', 'wrong-locale', 'unselected-chinese', 'missing-language', 'missing-gate', 'body-change', 'old-baseline', 'group'] as $case) {
                $candidate = $package;
                $review = $proof;
                if ($case === 'missing-language') {
                    array_pop($candidate['candidates']);
                    unset($review['pages']['PAGE-17']);
                } elseif ($case === 'missing-gate') {
                    unset($candidate['candidates'][1]['enable_hreflang']);
                } elseif ($case === 'body-change') {
                    $candidate['candidates'][0]['snapshot']['content_md'] .= 'Changed body';
                } elseif ($case === 'old-baseline') {
                    $candidate['candidates'][0]['before']['content_md'] = 'Old body';
                } elseif ($case === 'group') {
                    $candidate['candidates'][0]['source_identity']['translation_group_id'] = 'other-group';
                } elseif ($case === 'nonbody') {
                    $candidate['candidates'][0]['snapshot']['title'] = 'Changed title';
                } elseif ($case === 'home_link') {
                    $candidate['candidates'][4]['snapshot']['payload_json']['header']['groups'][0]['links'][2]['href'] = '/en/unselected';
                } elseif ($case === 'home_block') {
                    $candidate['candidates'][4]['snapshot']['block_copy']['header']['groups'][0]['links'][2]['title'] = 'Unreviewed title';
                } elseif ($case === 'home_block_missing') {
                    unset($candidate['candidates'][4]['snapshot']['block_copy']['footer']);
                } elseif ($case === 'wrong-locale') {
                    $candidate['candidates'][0]['identity']['locale'] = 'en';
                } elseif ($case === 'unselected-chinese') {
                    $candidate['candidates'][0]['page_id'] = 'PAGE-45';
                    $candidate['candidates'][0]['identity']['slug'] = 'mbti-full-report-career-relationship-communication';
                    unset($review['pages']['PAGE-AI-ZH']);
                    $review['pages']['PAGE-45'] = 'PASS';
                } elseif ($case === 'identity') {
                    $candidate['candidates'][0]['identity']['slug'] = 'unselected-article';
                } elseif ($case === 'extra-field') {
                    $candidate['candidates'][0]['snapshot']['is_indexable'] = true;
                }
                $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE);
                $review['candidate_sha256'] = $case === 'review' ? str_repeat('0', 64) : hash('sha256', $bytes);
                file_put_contents($directory.'/article-amendment.json', $bytes);
                file_put_contents($directory.'/article-amendment-review.json', json_encode($review));
                try {
                    app(PublicContent53Package::class)->read($root, $this->amendmentDigest($root));
                    self::fail('Invalid amendment must be rejected');
                } catch (DomainException $e) {
                    self::assertStringStartsWith('public_content_53_amendment_', $e->getMessage());
                }
            }
        } finally {
            unlink($directory.'/article-amendment.json');
            unlink($directory.'/article-amendment-review.json');
            rmdir($directory);
            rmdir($root.'/content_assets');
            rmdir($root);
        }
    }

    private function copyAmendmentDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_copy_amendment.v1\n";
        foreach (['copy-amendment.json', 'copy-amendment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    #[DataProvider('environments')]
    public function test_copy_amendment_changes_only_reviewed_metadata_and_cards_and_restores(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->copyAmendmentDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $article = null;
        foreach ($rows as $row) {
            $copy = PublicContent53Package::before($row);
            if ($row['kind'] === 'article') {
                $created = $this->article($row['identity'], ['title' => $copy['title'] ?? 'Visible title', 'excerpt' => 'Original summary', 'content_md' => 'Original body'],
                    $row['source_identity']['translation_group_id'], $row['source_identity']['source_locale']);
                $article ??= $created;
                $published = ArticleTranslationRevision::query()->findOrFail($created->published_revision_id);
                $published->forceFill(array_intersect_key($copy['seo_text'], array_flip(['seo_title', 'seo_description'])))->saveQuietly();
                $created->seoMeta()->update($copy['seo_text']);
            } else {
                $surface = LandingSurface::query()->create(['org_id' => 0, 'surface_key' => $row['identity']['slug'], 'locale' => 'en',
                    'title' => 'Tests', 'description' => 'Tests', 'schema_version' => 'v1', 'status' => 'published', 'is_public' => true,
                    'is_indexable' => true, 'payload_json' => $copy['payload_json']]);
                foreach ($copy['block_copy'] ?? [] as $key => $payload) {
                    PageBlock::query()->create(['landing_surface_id' => $surface->id, 'block_key' => $key,
                        'block_type' => 'homepage_section', 'payload_json' => $payload, 'sort_order' => $key === 'header' ? 7 : 8, 'is_enabled' => true]);
                }
            }
        }
        self::assertInstanceOf(Article::class, $article);
        $other = $this->article(['org_id' => 0, 'slug' => 'unselected-copy-control', 'locale' => 'en'],
            ['title' => 'Control', 'excerpt' => 'Keep', 'content_md' => 'Keep control body'], 'control-group', 'en');
        $otherBefore = $other->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        $remainingSurfaces = 2;
        DB::listen(function ($query) use (&$remainingSurfaces): void {
            if ($remainingSurfaces > 0 && preg_match('/^update [`"]landing_surfaces[`"]/i', $query->sql) === 1 && --$remainingSurfaces === 0) {
                throw new DomainException('copy_amendment_late_failure');
            }
        });
        try {
            DB::transaction(fn () => $writer->publish($context, $before));
            self::fail('A late surface failure must roll back the earlier article revision and SEO update');
        } catch (DomainException $error) {
            self::assertSame('copy_amendment_late_failure', $error->getMessage());
        }
        self::assertSame(0, $remainingSurfaces);
        self::assertSame($before, $states->read($sha));
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(count($rows), $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        self::assertSame($otherBefore, $other->fresh()->toArray());
        $articleRow = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === 'article'))[0];
        $saved = $before[$articleRow['page_id']];
        self::assertSame($saved['working'], $after['state'][$articleRow['page_id']]['working']);
        self::assertSame($saved['published'], (array) DB::table('article_translation_revisions')->where('id', $saved['published']['id'])->first());
        self::assertSame('Original body', $article->fresh()->publishedRevision->content_md);
        self::assertSame($articleRow['snapshot']['seo_text']['seo_description'], $article->fresh()->publishedRevision->seo_description);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($sha));
        $newOwner = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), count($rows), str_repeat('e', 64));
        self::assertSame(count($rows), DB::transaction(fn () => $writer->publish($newOwner, $before))['written_count']);
        $stale = $states->read($sha);
        DB::table('articles')->where('id', $article->id)->update(['excerpt' => 'Concurrent operator change']);
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => $writer->publish($newOwner, $stale));
    }

    public function test_copy_amendment_rejects_links_flags_body_and_unreviewed_bytes(): void
    {
        $root = sys_get_temp_dir().'/fm-copy-amendment-'.bin2hex(random_bytes(6));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/copy-amendment.json')), true);
        try {
            foreach (['link', 'flag', 'body', 'identity', 'review', 'home_link', 'home_block', 'home_block_missing'] as $case) {
                $candidate = $package;
                if ($case === 'link') {
                    $candidate['candidates'][1]['snapshot']['payload_json']['families']['items'][0]['tests'][0]['href'] = '/en/unselected';
                } elseif ($case === 'flag') {
                    $candidate['candidates'][1]['snapshot']['payload_json']['seo']['is_indexable'] = true;
                } elseif ($case === 'body') {
                    $candidate['candidates'][0]['snapshot']['content_md'] = 'New body';
                } elseif ($case === 'home_link') {
                    $candidate['candidates'][4]['snapshot']['payload_json']['header']['groups'][0]['links'][2]['href'] = '/en/unselected';
                } elseif ($case === 'home_block') {
                    $candidate['candidates'][4]['snapshot']['block_copy']['header']['groups'][0]['links'][2]['title'] = 'Unreviewed title';
                } elseif ($case === 'home_block_missing') {
                    unset($candidate['candidates'][4]['snapshot']['block_copy']['footer']);
                } elseif ($case === 'identity') {
                    $candidate['candidates'][0]['identity']['slug'] = 'unselected';
                }
                $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/copy-amendment.json', $bytes);
                file_put_contents($dir.'/copy-amendment-review.json', json_encode([
                    'schema' => 'fermatmind.public_content_copy_amendment.review.v1',
                    'candidate_sha256' => $case === 'review' ? str_repeat('0', 64) : hash('sha256', $bytes),
                    'verdict' => 'PASS', 'pages' => array_fill_keys(array_column($candidate['candidates'], 'page_id'), 'PASS'),
                ], JSON_THROW_ON_ERROR));
                try {
                    app(PublicContent53Package::class)->read($root, $this->copyAmendmentDigest($root));
                    self::fail('Protected or unreviewed mutation must fail');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_copy_', $error->getMessage());
                }
            }
        } finally {
            unlink($dir.'/copy-amendment.json');
            unlink($dir.'/copy-amendment-review.json');
            rmdir($dir);
            rmdir($root.'/content_assets');
            rmdir($root);
        }
    }

    private function seedTargets(): void
    {
        (new ScaleRegistrySeeder)->run();
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        foreach ($rows as $row) {
            $copy = PublicContent53Package::before($row);
            $identity = $row['identity'];
            $slug = $identity['slug'];
            if ($row['kind'] === 'article') {
                $sourceIdentity = PublicContent53Package::environment() === 'staging' ? $row['staging_source_identity'] : $row['source_identity'];
                $this->article($identity, $copy, $sourceIdentity['translation_group_id'], $sourceIdentity['source_locale']);
            } elseif ($row['kind'] === 'page') {
                ContentPage::query()->create($identity + $copy + ['path' => '/'.($identity['locale'] === 'en' ? 'en' : 'zh').'/'.$slug,
                    'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'review_state' => 'approved',
                    'publish_allowed' => true, 'operator_approval_required' => false, 'claim_gate_status' => 'passed']);
            } elseif ($row['kind'] === 'surface') {
                unset($identity['slug']);
                LandingSurface::query()->create($identity + $copy + ['surface_key' => $slug, 'status' => 'published', 'is_public' => true]);
            } else {
                $profile = TopicProfile::query()->create($identity + array_intersect_key($copy, array_flip(['title', 'subtitle', 'excerpt']))
                    + ['topic_code' => $slug, 'status' => 'published', 'is_public' => true, 'is_indexable' => false]);
                TopicProfileSeoMeta::query()->create(['profile_id' => $profile->id, 'seo_title' => $copy['seo_text']['title'], 'seo_description' => $copy['seo_text']['description']]);
                foreach ($copy['sections'] as $section) {
                    TopicProfileSection::query()->create($section + ['profile_id' => $profile->id]);
                }
                foreach ($copy['entry_groups'] as $entries) {
                    foreach ($entries as $index => $entry) {
                        if ($entry['entry_type'] === 'article') {
                            $extra = $this->article(['org_id' => 0, 'locale' => 'en', 'slug' => $entry['target_key']], ['title' => $entry['title'], 'excerpt' => $entry['excerpt'], 'content_md' => 'Extra public article'], 'extra-article', 'en');
                            $extra->forceFill(['cover_image_url' => $entry['image_url']])->saveQuietly();
                        }
                        if ($entry['entry_type'] === 'personality_profile') {
                            PersonalityProfile::query()->create(['org_id' => 0, 'scale_code' => 'MBTI',
                                'type_code' => 'INTJ', 'slug' => 'intj', 'locale' => 'en', 'title' => $entry['title'], 'excerpt' => $entry['excerpt'], 'status' => 'published', 'is_public' => true]);
                        }
                        TopicProfileEntry::query()->create(['profile_id' => $profile->id, 'entry_type' => $entry['entry_type'], 'group_key' => $entry['group_key'],
                            'target_key' => $entry['target_key'], 'title_override' => $entry['title'], 'excerpt_override' => $entry['excerpt'],
                            'target_url_override' => $entry['url'], 'badge_label' => $entry['badge_label'], 'cta_label' => $entry['cta_label'],
                            'is_featured' => $entry['is_featured'], 'is_enabled' => true, 'sort_order' => $index * 10]);
                    }
                }
            }
        }
    }

    private function alignmentDigest(): string
    {
        $chain = "fermatmind.public_content_seo_alignment.v1\n";
        foreach (['seo-alignment.json', 'seo-alignment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    public function test_alignment_can_be_counted_after_composer_autoload_before_laravel_bootstrap(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
if (! function_exists('config') || app()->bound('config')) {
    throw new RuntimeException('Expected Composer helpers without bootstrapped config.');
}
$package = (new App\Services\ContentPromotion\PublicContent53Package)->read(getcwd(), $argv[1]);
echo json_encode(['count' => count($package['candidates']), 'snapshot' => $package['candidates'][6]['snapshot']], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $this->alignmentDigest()], base_path(), ['PUBLIC_CONTENT_53_ENVIRONMENT' => 'staging']);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(10, $result['count']);
        config(['content_promotion.public_content_53_environment' => 'production']);
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->alignmentDigest())['candidates'];
        self::assertSame($rows[6]['snapshot'], $result['snapshot']);
        config(['content_promotion.public_content_53_environment' => 'staging']);
        $staging = app(PublicContent53Package::class)->read(base_path(), $this->alignmentDigest())['candidates'];
        self::assertSame($rows[6]['staging_snapshot'], $staging[6]['snapshot']);
    }

    private function alignmentContext(string $owner = 'a'): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->alignmentDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat($owner, 40), str_repeat('b', 64), str_repeat('c', 64), $owner === 'a' ? '12345' : '12346', 1, str_repeat('d', 64), 10, str_repeat('e', 64));
    }

    private function seedAlignment(bool $missingStagingCounterpart = false): array
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->alignmentDigest())['candidates'];
        foreach ($rows as $row) {
            if ($missingStagingCounterpart && $row['page_id'] === 'PAGE-60') {
                continue;
            }
            $copy = PublicContent53Package::before($row);
            if ($row['kind'] === 'article') {
                $source = PublicContent53Package::environment() === 'staging' ? $row['staging_source_identity'] : $row['source_identity'];
                $created = $this->article($row['identity'], array_intersect_key($copy, array_flip(['title', 'excerpt', 'content_md'])) + array_intersect_key($copy['seo_text'], array_flip(['seo_title', 'seo_description'])),
                    $source['translation_group_id'], $source['source_locale']);
                $created->seoMeta()->update($copy['seo_text']);
                $created->forceFill(['is_indexable' => true])->saveQuietly();
            } elseif ($row['kind'] === 'surface') {
                $surface = LandingSurface::query()->create(['org_id' => 0, 'surface_key' => $row['identity']['slug'], 'locale' => $row['identity']['locale'],
                    'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'schema_version' => 'v1'] + array_diff_key($copy, ['block_copy' => true]));
                foreach (PublicContent53Package::nativeBlocks($row, false) ?? $copy['block_copy'] as $key => $payload) {
                    PageBlock::query()->create(['landing_surface_id' => $surface->id, 'block_key' => $key,
                        'block_type' => 'tests_section', 'payload_json' => $payload, 'sort_order' => 1, 'is_enabled' => true]);
                }
            } else {
                ContentPage::query()->create($row['identity'] + $copy + ['path' => '/'.($row['identity']['locale'] === 'en' ? 'en' : 'zh').'/'.$row['identity']['slug'],
                    'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'review_state' => 'approved',
                    'publish_allowed' => true, 'operator_approval_required' => false, 'claim_gate_status' => 'passed']);
            }
        }

        return $rows;
    }

    #[DataProvider('environments')]
    public function test_bilingual_alignment_is_atomic_preserves_drafts_and_republishes_after_rollback(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $rows = $this->seedAlignment();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->alignmentDigest());
        $states->assertBaseline($rows);
        $control = $this->article(['org_id' => 0, 'slug' => 'unselected-alignment-control', 'locale' => 'en'],
            ['title' => 'Keep', 'excerpt' => 'Keep', 'content_md' => 'Keep this unrelated article'], 'control', 'en')->fresh()->toArray();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/^update [`"]content_pages[`"]/i', $query->sql) === 1) {
                $armed = false;
                throw new DomainException('alignment_late_failure');
            }
        });
        try {
            DB::transaction(fn () => $writer->publish($this->alignmentContext(), $before));
            self::fail('A later content-page failure must roll back earlier native writes');
        } catch (DomainException $error) {
            self::assertSame('alignment_late_failure', $error->getMessage());
        }
        self::assertFalse($armed);
        self::assertSame($before, $states->read($this->alignmentDigest()));
        $after = DB::transaction(fn () => $writer->publish($this->alignmentContext(), $before));
        self::assertSame(10, $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        foreach (array_filter($rows, fn ($row) => $row['kind'] === 'surface') as $row) {
            $surfaceId = $after['state'][$row['page_id']]['values']['id'];
            $native = json_decode(DB::table('page_blocks')->where('landing_surface_id', $surfaceId)->where('block_key', 'families')->value('payload_json'), true);
            self::assertSame(PublicContent53Package::nativeBlocks($row, true)['families'], $native);
            self::assertCount(12, array_merge(...array_column($native['items'], 'tests')));
            $projection = $states->projection($row);
            self::assertSame([], $projection['block_copy']['families']['items']);
        }
        foreach ($before as $id => $saved) {
            if ($saved['table'] === 'articles') {
                self::assertSame($saved['working'], $after['state'][$id]['working']);
                self::assertSame($saved['published'], (array) DB::table('article_translation_revisions')->where('id', $saved['published']['id'])->first());
            }
        }
        $drifted = $after['state'];
        foreach ($drifted['PAGE-61']['relations']['page_blocks'] as &$block) {
            if ($block['block_key'] === 'families') {
                $block['payload_json'] = json_encode(['items' => []]);
            }
        }
        unset($block);
        try {
            $states->assertPublished($rows, $drifted);
            self::fail('Public projection equality must not conceal raw native readback drift');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_native_block_readback_drift', $error->getMessage());
        }
        self::assertSame($control, Article::query()->findOrFail($control['id'])->toArray());
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->alignmentContext(), $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->alignmentContext(), $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->alignmentDigest()));
        $again = DB::transaction(fn () => $writer->publish($this->alignmentContext('f'), $before));
        Cache::flush();
        $states->assertPublished($rows, $again['state']);
        DB::table('article_seo_meta')->where('article_id', $again['state']['PAGE-55']['values']['id'])->update(['seo_title' => 'Concurrent operator edit']);
        $this->expectExceptionMessage('public_content_53_rollback_concurrent_state');
        DB::transaction(fn () => $writer->restore($this->alignmentContext('f'), $before, $again['state']));
    }

    public function test_alignment_rejects_raw_compatibility_drift_hidden_by_public_projection(): void
    {
        $rows = $this->seedAlignment();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->alignmentDigest());
        $states->assertLockedBaseline($rows, $before);
        $surfaceId = $before['PAGE-61']['values']['id'];
        $block = DB::table('page_blocks')->where('landing_surface_id', $surfaceId)->where('block_key', 'families')->first();
        $copy = json_decode($block->payload_json, true);
        $copy['items'][0]['tests'][0]['href'] = '/zh/concurrent-operator-change';
        DB::table('page_blocks')->where('id', $block->id)->update(['payload_json' => json_encode($copy)]);
        Cache::flush();
        $surface = array_values(array_filter($rows, fn ($row) => $row['page_id'] === 'PAGE-61'))[0];
        self::assertSame([], $states->projection($surface)['block_copy']['families']['items']);
        $this->expectExceptionMessage('public_content_53_locked_copy_drift');
        $states->assertLockedBaseline($rows, $states->read($this->alignmentDigest()));
    }

    public function test_staging_only_initialization_keeps_a_real_baseline_and_never_overwrites_existing_holds(): void
    {
        config(['content_promotion.public_content_53_environment' => 'staging']);
        $rows = $this->seedAlignment(true);
        $writer = app(PublicContent53Writer::class);
        self::assertSame(1, DB::transaction(fn () => $writer->initializeStagingBaseline($this->alignmentContext())));
        self::assertSame(0, DB::transaction(fn () => $writer->initializeStagingBaseline($this->alignmentContext())));
        $states = app(PublicContent53State::class);
        $before = $states->read($this->alignmentDigest());
        $states->assertBaseline($rows);
        $after = DB::transaction(fn () => $writer->publish($this->alignmentContext(), $before));
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        DB::transaction(fn () => $writer->restore($this->alignmentContext(), $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->alignmentDigest()));
        DB::table('articles')->where('id', $before['PAGE-60']['values']['id'])->update(['status' => 'draft']);
        self::assertSame(0, DB::transaction(fn () => $writer->initializeStagingBaseline($this->alignmentContext())));
        $this->expectExceptionMessage('public_content_53_identity_or_hold');
        $states->read($this->alignmentDigest());
    }

    public function test_production_never_initializes_a_missing_counterpart(): void
    {
        config(['content_promotion.public_content_53_environment' => 'production']);
        $this->seedAlignment(true);
        self::assertSame(0, DB::transaction(fn () => app(PublicContent53Writer::class)->initializeStagingBaseline($this->alignmentContext())));
        self::assertFalse(Article::query()->where('locale', 'en')->where('slug', 'ocean-traits-what-the-five-letters-mean')->exists());
        $this->expectExceptionMessage('public_content_53_identity_or_hold');
        app(PublicContent53State::class)->read($this->alignmentDigest());
    }

    public function test_alignment_rejects_unreviewed_routes_empty_body_and_foreign_initialization(): void
    {
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/seo-alignment.json')), true);
        $mutations = [
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['content_md'] = '';
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['org_id'] = 1;
            },
            static function (&$p): void {
                $p['candidates'][0]['staging_bootstrap'] = $p['candidates'][5]['staging_bootstrap'];
            },
            static function (&$p): void {
                $p['candidates'][6]['snapshot']['payload_json']['families']['items'][0]['tests'][0]['href'] = '/en/foreign';
            },
            static function (&$p): void {
                $p['candidates'][6]['staging_snapshot']['payload_json']['families']['items'][0]['tests'][0]['href'] = '/en/foreign';
            },
        ];
        $mutations[] = static function (&$p): void {
            unset($p['candidates'][6]['native_blocks']);
        };
        $mutations[] = static function (&$p): void {
            $p['candidates'][6]['native_blocks']['snapshot']['families']['items'][0]['tests'][0]['href'] = '/zh/foreign';
        };
        $mutations[] = static function (&$p): void {
            array_pop($p['candidates'][6]['native_blocks']['staging_snapshot']['families']['items']);
        };
        $mutations[] = static function (&$p): void {
            $p['candidates'][6]['native_blocks']['snapshot']['hero']['title'] = 'Unmatched title';
        };
        $mutations[] = static function (&$p): void {
            foreach ($p['candidates'][6]['native_blocks']['snapshot']['families']['items'] as &$family) {
                foreach ($family['tests'] as &$card) {
                    if ($card['key'] === 'eq-test-emotional-intelligence-assessment') {
                        $card['description'] = 'Copy differs from the primary surface';
                    }
                }
                unset($card);
            }
            unset($family);
        };
        foreach (['seo_title' => 60, 'seo_description' => 160, 'og_title' => 90, 'og_description' => 200] as $field => $limit) {
            $mutations[] = static function (&$p) use ($field, $limit): void {
                $p['candidates'][5]['snapshot']['seo_text'][$field] = str_repeat('界', $limit + 1);
            };
        }
        $root = sys_get_temp_dir().'/public-alignment-'.bin2hex(random_bytes(8));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        try {
            foreach ($mutations as $mutate) {
                $package = $source;
                $mutate($package);
                $bytes = json_encode($package, JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/seo-alignment.json', $bytes);
                file_put_contents($dir.'/seo-alignment-review.json', json_encode(['schema' => 'fermatmind.public_content_seo_alignment.review.v1', 'candidate_sha256' => hash('sha256', $bytes),
                    'verdict' => 'PASS', 'pages' => array_fill_keys(array_column($source['candidates'], 'page_id'), 'PASS')]));
                $chain = "fermatmind.public_content_seo_alignment.v1\n";
                foreach (['seo-alignment.json', 'seo-alignment-review.json'] as $name) {
                    $chain .= $name."\n".hash_file('sha256', $dir.'/'.$name)."\n";
                }
                try {
                    (new PublicContent53Package)->read($root, hash('sha256', $chain));
                    self::fail('Out-of-scope alignment must fail even with a rebound test-only review');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_alignment_', $error->getMessage());
                }
            }
        } finally {
            unlink($dir.'/seo-alignment.json');
            unlink($dir.'/seo-alignment-review.json');
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }

    private function guideDigest(): string
    {
        $chain = "fermatmind.public_content_guide_amendment.v1\n";
        foreach (['guide-amendment.json', 'guide-amendment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    private function guideContext(): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->guideDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 1, str_repeat('e', 64));
    }

    private function seedGuide(): array
    {
        $row = app(PublicContent53Package::class)->read(base_path(), $this->guideDigest())['candidates'][0];
        foreach (['en', 'zh-CN'] as $locale) {
            $copy = $locale === $row['identity']['locale'] ? $row['before'] : ['title' => 'Other language', 'excerpt' => 'Keep this summary', 'body_md' => 'Keep this body'];
            $guide = CareerGuide::query()->withoutGlobalScopes()->create(array_replace($row['identity'], ['locale' => $locale,
                'guide_code' => 'from-mbti-to-job-fit', 'status' => 'published', 'is_public' => true, 'is_indexable' => true,
                'published_at' => now()->subDay(), 'lifecycle_state' => 'active'], $copy));
            DB::table('career_guide_seo_meta')->insert(['career_guide_id' => $guide->id, 'seo_title' => 'Protected SEO',
                'canonical_url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/career/guides/'.$guide->slug]);
        }

        return $row;
    }

    public function test_native_body_roundtrip_preserves_other_locale_seo_maps_and_immutable_history(): void
    {
        $row = $this->seedGuide();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->guideDigest());
        $states->assertBaseline([$row]);
        $published = DB::transaction(fn () => $writer->publish($this->guideContext(), $before));
        self::assertSame(1, $published['written_count']);
        $states->assertPublished([$row], $published['state']);
        self::assertSame($before['MBTI-GUIDE']['counterpart'], $published['state']['MBTI-GUIDE']['counterpart']);
        self::assertSame($before['MBTI-GUIDE']['controls'], $published['state']['MBTI-GUIDE']['controls']);
        self::assertSame(1, CareerGuideRevision::query()->count());
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->guideContext(), $published['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->guideContext(), $before, $published['state']));
        $restored = $states->read($this->guideDigest());
        self::assertSame($before['MBTI-GUIDE']['values'], $restored['MBTI-GUIDE']['values']);
        self::assertSame($before['MBTI-GUIDE']['controls'], $restored['MBTI-GUIDE']['controls']);
        self::assertSame($published['state']['MBTI-GUIDE']['revisions'], $restored['MBTI-GUIDE']['revisions']);
        DB::transaction(fn () => $writer->restore($this->guideContext(), $before, $published['state']));
        self::assertSame(1, CareerGuideRevision::query()->count());
    }

    public function test_concurrent_other_language_edit_prevents_publish_and_is_not_overwritten(): void
    {
        $row = $this->seedGuide();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->guideDigest());
        DB::table('career_guides')->where('locale', $row['identity']['locale'] === 'en' ? 'zh-CN' : 'en')->update(['body_md' => 'Concurrent edit']);
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->guideContext(), $before));
    }

    public function test_concurrent_seo_edit_prevents_rollback(): void
    {
        $this->seedGuide();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->guideDigest());
        $after = DB::transaction(fn () => $writer->publish($this->guideContext(), $before))['state'];
        DB::table('career_guide_seo_meta')->update(['seo_title' => 'Concurrent edit']);
        $this->expectExceptionMessage('public_content_53_rollback_concurrent_state');
        DB::transaction(fn () => $writer->restore($this->guideContext(), $before, $after));
    }

    public function test_invalid_review_identity_body_and_title_changes_fail_closed(): void
    {
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/guide-amendment.json')), true);
        $mutations = [
            static function (&$p): void {
                $p['candidates'][] = $p['candidates'][0];
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['slug'] = 'other-guide';
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['locale'] = 'fr';
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['org_id'] = 1;
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['title'] = 'Changed';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['body_md'] = '';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['is_indexable'] = false;
            },
        ];
        $root = sys_get_temp_dir().'/public-guide-'.bin2hex(random_bytes(8));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        try {
            foreach ($mutations as $mutate) {
                $package = $source;
                $mutate($package);
                $bytes = json_encode($package, JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/guide-amendment.json', $bytes);
                file_put_contents($dir.'/guide-amendment-review.json', json_encode(['schema' => 'fermatmind.public_content_guide_amendment.review.v1', 'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS', 'pages' => ['MBTI-GUIDE' => 'PASS']]));
                $chain = "fermatmind.public_content_guide_amendment.v1\n";
                foreach (['guide-amendment.json', 'guide-amendment-review.json'] as $name) {
                    $chain .= $name."\n".hash_file('sha256', $dir.'/'.$name)."\n";
                }
                try {
                    (new PublicContent53Package)->read($root, hash('sha256', $chain));
                    self::fail('Invalid amendment must fail');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_guide_', $error->getMessage());
                }
            }
        } finally {
            unlink($dir.'/guide-amendment.json');
            unlink($dir.'/guide-amendment-review.json');
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }

    public function test_guide_scheduling_lifecycle_and_mapping_drift_fail_closed(): void
    {
        $row = $this->seedGuide();
        $states = app(PublicContent53State::class);
        $where = $row['identity'];
        foreach ([['scheduled_at' => now()->addDay()], ['published_at' => now()->addDay()], ['lifecycle_state' => 'archived']] as $change) {
            $old = (array) DB::table('career_guides')->where($where)->first();
            DB::table('career_guides')->where($where)->update($change);
            try {
                $states->read($this->guideDigest());
                self::fail('Guide hold must reject publication');
            } catch (DomainException $error) {
                self::assertContains($error->getMessage(), ['public_content_53_future_publication', 'public_content_53_guide_lifecycle_hold']);
            }
            DB::table('career_guides')->where($where)->update($old);
        }
        $article = Article::query()->create(['org_id' => 0, 'slug' => 'guide-control', 'locale' => $where['locale'], 'title' => 'Map control', 'content_md' => 'Map fixture body', 'status' => 'draft']);
        DB::table('career_guide_article_map')->insert(['career_guide_id' => DB::table('career_guides')->where($where)->value('id'), 'article_id' => $article->id, 'sort_order' => 1]);
        $before = $states->read($this->guideDigest());
        DB::table('career_guide_article_map')->update(['sort_order' => 2]);
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->guideContext(), $before));
    }

    public function test_late_guide_revision_failure_rolls_back_body_atomically(): void
    {
        $this->seedGuide();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->guideDigest());
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/^insert into [`"]career_guide_revisions[`"]/i', $query->sql) === 1) {
                $armed = false;
                throw new DomainException('injected_guide_revision_failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->guideContext(), $before));
            self::fail('Late failure must abort');
        } catch (DomainException $error) {
            self::assertSame('injected_guide_revision_failure', $error->getMessage());
        }
        self::assertFalse($armed);
        self::assertSame($before, $states->read($this->guideDigest()));
        self::assertSame(0, CareerGuideRevision::query()->count());
    }

    private function planningDigest(): string
    {
        $chain = "fermatmind.public_content_career_planning.v1\n";
        foreach (['career-planning.json', 'career-planning-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    private function planningContext(): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->planningDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count(app(PublicContent53Package::class)->read(base_path(), $this->planningDigest())['candidates']), str_repeat('e', 64));
    }

    private function seedPlanningGuides(): array
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->planningDigest())['candidates'];
        foreach ($rows as $row) {
            foreach (['en', 'zh-CN'] as $locale) {
                $copy = $locale === $row['identity']['locale'] ? array_diff_key($row['before'], ['seo_text' => true])
                    : ['title' => 'Protected counterpart title', 'excerpt' => 'Protected summary', 'body_md' => 'Protected counterpart body'];
                $guide = CareerGuide::query()->withoutGlobalScopes()->create(array_replace($row['identity'], ['locale' => $locale,
                    'guide_code' => $row['identity']['slug'], 'status' => 'published', 'is_public' => true, 'is_indexable' => true,
                    'published_at' => now()->subDay(), 'lifecycle_state' => 'active'], $copy));
                DB::table('career_guide_seo_meta')->insert(['career_guide_id' => $guide->id,
                    'canonical_url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/career/guides/'.$guide->slug,
                    'robots' => 'index,follow'] + $row['before']['seo_text']);
                CareerGuideRevision::query()->create(['career_guide_id' => $guide->id, 'revision_no' => 1,
                    'snapshot_json' => ['guide' => ['body_md' => 'Saved operator draft or historical revision']],
                    'note' => 'Protected operator revision', 'created_at' => now()->subDay()]);
            }
        }

        return $rows;
    }

    public function test_planning_guides_publish_body_summary_and_seo_restore_and_republish_without_rewriting_history(): void
    {
        $rows = $this->seedPlanningGuides();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->planningDigest());
        $states->assertBaseline($rows);
        $published = DB::transaction(fn () => $writer->publish($this->planningContext(), $before));
        self::assertSame(count($rows), $published['written_count']);
        $states->assertPublished($rows, $published['state']);
        foreach ($before as $id => $old) {
            self::assertSame($old['counterpart'], $published['state'][$id]['counterpart']);
            self::assertSame($old['revisions'][0], $published['state'][$id]['revisions'][0]);
            self::assertCount(2, $published['state'][$id]['revisions']);
            self::assertSame($old['controls']['seo'][0]['canonical_url'], $published['state'][$id]['controls']['seo'][0]['canonical_url']);
        }
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->planningContext(), $published['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->planningContext(), $before, $published['state']));
        $restored = $states->read($this->planningDigest());
        foreach ($before as $id => $old) {
            self::assertSame($old['values'], $restored[$id]['values']);
            self::assertSame($old['controls'], $restored[$id]['controls']);
            self::assertCount(2, $restored[$id]['revisions']);
        }
        $republished = DB::transaction(fn () => $writer->publish($this->planningContext(), $restored));
        self::assertSame(count($rows), $republished['written_count']);
        $states->assertPublished($rows, $republished['state']);
        foreach ($republished['state'] as $saved) {
            self::assertCount(3, $saved['revisions']);
        }
    }

    public function test_planning_guides_reject_concurrent_seo_edits(): void
    {
        $this->seedPlanningGuides();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->planningDigest());
        DB::table('career_guide_seo_meta')->where('career_guide_id', $before[array_key_first($before)]['values']['id'])->update(['seo_description' => 'Concurrent draft edit']);
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->planningContext(), $before));
    }

    public function test_planning_guides_late_revision_failure_rolls_back_selected_bodies_and_seo(): void
    {
        $this->seedPlanningGuides();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->planningDigest());
        $inserted = 0;
        DB::listen(function ($query) use (&$inserted, $before): void {
            if (preg_match('/^insert into [`"]career_guide_revisions[`"]/i', $query->sql) === 1 && ++$inserted === count($before)) {
                throw new DomainException('injected_second_planning_revision_failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->planningContext(), $before));
            self::fail('Late revision failure must roll back every selected guide');
        } catch (DomainException $error) {
            self::assertSame('injected_second_planning_revision_failure', $error->getMessage());
        }
        self::assertSame($before, $states->read($this->planningDigest()));
    }

    private function englishGuidesDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_english_career_guides.v1\n";
        foreach (['english-career-guides.json', 'english-career-guides-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    private function englishGuidesContext(string $owner = 'a'): PromotionContext
    {
        $count = count(app(PublicContent53Package::class)->read(base_path(), $this->englishGuidesDigest())['candidates']);

        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->englishGuidesDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat($owner, 40), str_repeat('b', 64), str_repeat('c', 64), $owner === 'a' ? '12345' : '12346', 1, str_repeat('d', 64), $count, str_repeat('e', 64));
    }

    private function seedEnglishGuides(): array
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->englishGuidesDigest())['candidates'];
        foreach ($rows as $row) {
            foreach (['en', 'zh-CN'] as $locale) {
                $copy = $locale === 'en' ? array_diff_key($row['before'], ['seo_text' => true])
                    : ['title' => 'Protected Chinese title', 'excerpt' => 'Protected summary', 'body_md' => 'Protected Chinese body'];
                $guide = CareerGuide::query()->withoutGlobalScopes()->create(array_replace($row['identity'], ['locale' => $locale,
                    'guide_code' => $row['identity']['slug'], 'status' => 'published', 'is_public' => true, 'is_indexable' => true,
                    'published_at' => now()->subDay(), 'lifecycle_state' => 'active'], $copy));
                DB::table('career_guide_seo_meta')->insert(['career_guide_id' => $guide->id,
                    'canonical_url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/career/guides/'.$guide->slug,
                    'robots' => 'index,follow'] + $row['before']['seo_text']);
                CareerGuideRevision::query()->create(['career_guide_id' => $guide->id, 'revision_no' => 1,
                    'snapshot_json' => ['guide' => ['body_md' => 'Protected saved history']],
                    'note' => 'Protected operator revision', 'created_at' => now()->subDay()]);
            }
        }

        return $rows;
    }

    #[DataProvider('environments')]
    public function test_english_guides_publish_restore_and_new_owner_republish_preserves_native_controls(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $rows = $this->seedEnglishGuides();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->englishGuidesDigest());
        $states->assertBaseline($rows);
        $published = DB::transaction(fn () => $writer->publish($this->englishGuidesContext(), $before));
        self::assertSame(count($rows), $published['written_count']);
        $states->assertPublished($rows, $published['state']);
        foreach ($before as $id => $saved) {
            self::assertSame($saved['counterpart'], $published['state'][$id]['counterpart']);
            self::assertSame($saved['revisions'][0], $published['state'][$id]['revisions'][0]);
            self::assertCount(2, $published['state'][$id]['revisions']);
            self::assertSame($saved['controls']['seo'][0]['canonical_url'], $published['state'][$id]['controls']['seo'][0]['canonical_url']);
        }
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->englishGuidesContext(), $published['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->englishGuidesContext(), $before, $published['state']));
        $restored = $states->read($this->englishGuidesDigest());
        foreach ($before as $id => $saved) {
            self::assertSame($saved['values'], $restored[$id]['values']);
            self::assertSame($saved['controls'], $restored[$id]['controls']);
            self::assertCount(2, $restored[$id]['revisions']);
        }
        $republished = DB::transaction(fn () => $writer->publish($this->englishGuidesContext('f'), $restored));
        self::assertSame(count($rows), $republished['written_count']);
        $states->assertPublished($rows, $republished['state']);
        foreach ($republished['state'] as $saved) {
            self::assertCount(3, $saved['revisions']);
        }
    }

    public function test_english_guides_late_revision_failure_rolls_back_the_entire_batch(): void
    {
        $rows = $this->seedEnglishGuides();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->englishGuidesDigest());
        $inserted = 0;
        DB::listen(function ($query) use (&$inserted, $rows): void {
            if (preg_match('/^insert into [`"]career_guide_revisions[`"]/i', $query->sql) === 1 && ++$inserted === count($rows)) {
                throw new DomainException('injected_last_english_guide_revision_failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->englishGuidesContext(), $before));
            self::fail('The whole batch must roll back');
        } catch (DomainException $error) {
            self::assertSame('injected_last_english_guide_revision_failure', $error->getMessage());
        }
        self::assertSame($before, $states->read($this->englishGuidesDigest()));
    }

    public function test_english_guides_reject_concurrent_native_history_changes(): void
    {
        $this->seedEnglishGuides();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->englishGuidesDigest());
        $saved = reset($before);
        DB::table('career_guide_revisions')->where('id', $saved['revisions'][0]['id'])->update(['note' => 'Concurrent history change']);
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->englishGuidesContext(), $before));
    }

    public function test_english_guides_rebound_review_cannot_broaden_scope_or_rewrite_mbti_body(): void
    {
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/english-career-guides.json')), true);
        $mutations = [
            static function (&$p): void {
                $p['candidates'][0]['identity']['slug'] = 'how-to-find-right-career-direction';
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['locale'] = 'zh-CN';
            },
            static function (&$p): void {
                $p['candidates'][1] = $p['candidates'][0];
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['title'] = 'Another title';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['seo_title'] = 'Another SEO title';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['canonical_url'] = '/en/other';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['seo_description'] = str_repeat('x', 161);
            },
            static function (&$p): void {
                $row = &$p['candidates'][0];
                $row['identity']['slug'] = 'from-mbti-to-job-fit';
                $row['page_id'] = 'EN-GUIDE-from-mbti-to-job-fit';
                $row['source_identity'] = $row['staging_source_identity'] = ['guide_code' => 'from-mbti-to-job-fit'];
                $row['snapshot']['body_md'] = $row['before']['body_md'].'Unexpected body rewrite';
            },
        ];
        $root = sys_get_temp_dir().'/english-guides-'.bin2hex(random_bytes(8));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        try {
            foreach ($mutations as $mutate) {
                $package = $source;
                $mutate($package);
                $bytes = json_encode($package, JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/english-career-guides.json', $bytes);
                $proof = ['schema' => 'fermatmind.public_content_english_career_guides.review.v1',
                    'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS',
                    'pages' => array_fill_keys(array_column($package['candidates'], 'page_id'), 'PASS')];
                file_put_contents($dir.'/english-career-guides-review.json', json_encode($proof));
                try {
                    (new PublicContent53Package)->read($root, $this->englishGuidesDigest($root));
                    self::fail('A rebound review must not change the fixed scope');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_english_guides_', $error->getMessage());
                }
            }
        } finally {
            unlink($dir.'/english-career-guides.json');
            unlink($dir.'/english-career-guides-review.json');
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }

    public function test_planning_reader_retains_legacy_two_english_guides_compatibility(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/career-planning.json')), true);
        $template = $package['candidates'][0];
        $package['candidates'] = [];
        foreach (['CAREER-DIRECTION' => 'how-to-find-right-career-direction', 'CAREER-TRANSITION' => 'career-transition-playbook'] as $id => $slug) {
            $row = $template;
            $row['page_id'] = $id;
            $row['identity'] = ['org_id' => 0, 'slug' => $slug, 'locale' => 'en'];
            foreach (['source_identity', 'staging_source_identity'] as $field) {
                $row[$field] = ['guide_code' => $slug];
            }
            $package['candidates'][] = $row;
        }
        $root = sys_get_temp_dir().'/legacy-planning-'.bin2hex(random_bytes(8));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        try {
            $bytes = json_encode($package, JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/career-planning.json', $bytes);
            file_put_contents($dir.'/career-planning-review.json', json_encode([
                'schema' => 'fermatmind.public_content_career_planning.review.v1',
                'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS',
                'pages' => ['CAREER-DIRECTION' => 'PASS', 'CAREER-TRANSITION' => 'PASS'],
            ]));
            $chain = "fermatmind.public_content_career_planning.v1\n";
            foreach (['career-planning.json', 'career-planning-review.json'] as $name) {
                $chain .= $name."\n".hash_file('sha256', $dir.'/'.$name)."\n";
            }
            $read = (new PublicContent53Package)->read($root, hash('sha256', $chain));
            self::assertSame($package['candidates'], $read['candidates']);
        } finally {
            unlink($dir.'/career-planning.json');
            unlink($dir.'/career-planning-review.json');
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }

    public function test_planning_package_rejects_rebound_out_of_scope_identity_and_fields(): void
    {
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/career-planning.json')), true);
        $mutations = [
            static function (&$p): void {
                $p['candidates'][0]['identity']['slug'] = 'other-guide';
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['locale'] = 'fr';
            },
            static function (&$p): void {
                $p['candidates'][1] = $p['candidates'][0];
            },
            static function (&$p): void {
                $row = &$p['candidates'][0];
                $row['page_id'] = 'CAREER-TRANSITION';
                $row['identity']['slug'] = 'career-transition-playbook';
                foreach (['source_identity', 'staging_source_identity'] as $field) {
                    $row[$field] = ['guide_code' => 'career-transition-playbook'];
                }
                $row['snapshot']['excerpt'] = 'Unreviewed legacy guide summary';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['title'] = 'Changed identity promise';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['canonical_url'] = '/en/other';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['seo_description'] = str_repeat('x', 161);
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['excerpt'] = [];
            },
            static function (&$p): void {
                $p['candidates'][0]['identity']['locale'] = 'en';
            },
            static function (&$p): void {
                $p['candidates'][0]['snapshot']['seo_text']['seo_description'] = 'Unreviewed short SEO';
            },
        ];
        $root = sys_get_temp_dir().'/career-planning-'.bin2hex(random_bytes(8));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        try {
            foreach ($mutations as $mutate) {
                $package = $source;
                $mutate($package);
                $bytes = json_encode($package, JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/career-planning.json', $bytes);
                file_put_contents($dir.'/career-planning-review.json', json_encode(['schema' => 'fermatmind.public_content_career_planning.review.v1',
                    'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS', 'pages' => array_fill_keys(array_column($package['candidates'], 'page_id'), 'PASS')]));
                $chain = "fermatmind.public_content_career_planning.v1\n";
                foreach (['career-planning.json', 'career-planning-review.json'] as $name) {
                    $chain .= $name."\n".hash_file('sha256', $dir.'/'.$name)."\n";
                }
                try {
                    (new PublicContent53Package)->read($root, hash('sha256', $chain));
                    self::fail('Rebound test review must not broaden the fixed scope');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_career_', $error->getMessage());
                }
            }
        } finally {
            unlink($dir.'/career-planning.json');
            unlink($dir.'/career-planning-review.json');
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }

    private function iarDigest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_article_amendment.v1\n";
        foreach (['iar-amendment.json', 'iar-amendment-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    #[DataProvider('environments')]
    public function test_iar_pair_native_publish_preserves_drafts_seo_and_restore_new_owner(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->iarDigest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        self::assertCount(2, $rows);
        $this->seedRiasecAmendment($rows, $environment);
        $states = app(PublicContent53State::class);
        $before = $states->read($sha);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 2, str_repeat('e', 64));
        $writer = app(PublicContent53Writer::class);
        $revisions = ArticleTranslationRevision::query()->count();
        try {
            DB::transaction(function () use ($writer, $context, $before): void {
                $writer->publish($context, $before);
                throw new DomainException('synthetic_transaction_failure');
            });
            self::fail('Injected transaction failure must roll back both locale writes');
        } catch (DomainException $error) {
            self::assertSame('synthetic_transaction_failure', $error->getMessage());
        }
        self::assertSame($before, $states->read($sha));
        self::assertSame($revisions, ArticleTranslationRevision::query()->count());
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(2, $after['written_count']);
        $states->assertPublished($rows, $after['state']);
        foreach ($rows as $row) {
            $id = $row['page_id'];
            self::assertSame($before[$id]['working'], $after['state'][$id]['working']);
            self::assertSame($before[$id]['relations'], $after['state'][$id]['relations']);
            self::assertSame($before[$id]['values']['is_indexable'], $after['state'][$id]['values']['is_indexable']);
        }
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        $newOwner = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), 2, str_repeat('e', 64));
        self::assertSame(2, DB::transaction(fn () => $writer->publish($newOwner, $before))['written_count']);
        $baseline = $states->read($sha);
        Article::query()->where('locale', 'en')->where('slug', 'iar-holland-code-career-exploration')->firstOrFail()
            ->forceFill(['content_md' => 'Concurrent operator edit'])->saveQuietly();
        $drifted = $states->read($sha);
        try {
            DB::transaction(fn () => $writer->publish($newOwner, $baseline));
            self::fail('Concurrent copy drift must fail closed');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        self::assertSame($drifted, $states->read($sha));
    }

    public function test_iar_pair_rejects_missing_language_foreign_identity_and_nonbody_changes(): void
    {
        $root = sys_get_temp_dir().'/iar-pair-'.bin2hex(random_bytes(6));
        $directory = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/iar-amendment.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/iar-amendment-review.json')), true);
        try {
            foreach (['missing-language', 'foreign-slug', 'locale', 'title', 'hreflang', 'staging-body', 'review'] as $case) {
                $candidate = $package;
                $review = $proof;
                match ($case) {
                    'missing-language' => array_pop($candidate['candidates']),
                    'foreign-slug' => $candidate['candidates'][0]['identity']['slug'] = 'graphic-designers',
                    'locale' => $candidate['candidates'][0]['identity']['locale'] = 'en',
                    'title' => $candidate['candidates'][0]['snapshot']['title'] = 'Unreviewed title',
                    'hreflang' => $candidate['candidates'][0]['enable_hreflang'] = true,
                    'staging-body' => $candidate['candidates'][0]['staging_snapshot']['content_md'] = 'Unreviewed staging copy',
                    default => null,
                };
                $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE);
                $review['candidate_sha256'] = $case === 'review' ? str_repeat('0', 64) : hash('sha256', $bytes);
                file_put_contents($directory.'/iar-amendment.json', $bytes);
                file_put_contents($directory.'/iar-amendment-review.json', json_encode($review));
                try {
                    app(PublicContent53Package::class)->read($root, $this->iarDigest($root));
                    self::fail('Unselected content or fields must be rejected');
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_amendment_', $error->getMessage());
                }
            }
        } finally {
            unlink($directory.'/iar-amendment.json');
            unlink($directory.'/iar-amendment-review.json');
            rmdir($directory);
            rmdir($root.'/content_assets');
            rmdir($root);
        }
    }
}
