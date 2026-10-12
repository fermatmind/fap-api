<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
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

    private function translationContext(int $count = 1): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->translationDigest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), $count, str_repeat('e', 64));
    }

    #[DataProvider('environments')]
    public function test_about_translation_roundtrip_preserves_chinese_other_pages_and_divergent_drafts(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->translationDigest())['candidates'];
        $row = $rows[0];
        $english = ContentPage::query()->where('slug', 'about')->where('locale', 'en')->firstOrFail();
        $english->forceFill(PublicContent53Package::before($row))->save();
        ContentPage::query()->create(['org_id' => 0, 'slug' => 'about', 'locale' => 'zh-CN', 'path' => '/zh/about'] + $row['source_snapshot'] + [
            'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'review_state' => 'approved',
            'publish_allowed' => true, 'operator_approval_required' => false, 'claim_gate_status' => 'passed',
        ]);
        $otherPages = DB::table('content_pages')->where('id', '!=', $english->id)->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all();
        $draft = CmsTranslationRevision::query()->create([
            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $english->id,
            'translation_group_id' => $english->translation_group_id, 'locale' => 'en', 'source_locale' => $english->source_locale,
            'revision_number' => 1, 'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
            'source_version_hash' => $english->source_version_hash, 'payload_json' => ['title' => 'Unpublished operator draft'],
        ]);
        $english->forceFill(['working_revision_id' => $draft->id])->saveQuietly();
        $draftBefore = $draft->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->translationDigest());
        $states->assertBaseline($rows);
        $writer = app(PublicContent53Writer::class);
        $after = DB::transaction(fn () => $writer->publish($this->translationContext(), $before));
        self::assertSame(1, $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        self::assertSame($otherPages, DB::table('content_pages')->where('id', '!=', $english->id)->orderBy('id')->get()->map(static fn ($record): array => (array) $record)->all());
        self::assertSame($draftBefore, $draft->fresh()->toArray());
        self::assertSame($draft->id, $english->fresh()->working_revision_id);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->translationContext(), $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($this->translationContext(), $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->translationDigest()));
        ContentPage::query()->where('slug', 'about')->where('locale', 'zh-CN')->update(['summary' => 'Changed after review']);
        $this->expectExceptionMessage('public_content_53_translation_source_drift');
        $states->read($this->translationDigest());
    }

    public function test_translation_count_mismatch_is_rejected_before_native_writes(): void
    {
        $this->expectExceptionMessage('public_content_53_writer_context_invalid');
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->translationContext(50), []));
    }

    public static function invalidTranslationPackages(): array
    {
        return [['excluded'], ['missing-paragraph'], ['unbound-review'], ['empty-title']];
    }

    #[DataProvider('invalidTranslationPackages')]
    public function test_translation_package_rejects_excluded_scope_omission_and_unbound_review(string $case): void
    {
        $temporary = sys_get_temp_dir().'/fm-translation-'.bin2hex(random_bytes(8));
        $directory = $temporary.'/'.PublicContent53Package::PACKAGE;
        mkdir($directory, 0700, true);
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-batch.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/translation-review.json')), true, 64, JSON_THROW_ON_ERROR);
        if ($case === 'excluded') {
            $package['candidates'][0]['identity']['slug'] = 'personality';
        } elseif ($case === 'missing-paragraph') {
            $package['candidates'][0]['snapshot']['content_md'] = preg_replace('/\n\n[^#\n][^\n]+(?=\n\n)/u', '', $package['candidates'][0]['snapshot']['content_md'], 1);
        } elseif ($case === 'empty-title') {
            $package['candidates'][0]['snapshot']['title'] = '';
        }
        $bytes = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);
        $proof['pages']['PAGE-02']['snapshot_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($package['candidates'][0]['snapshot']));
        if ($case === 'unbound-review') {
            $proof['pages']['PAGE-02']['source_sha256'] = str_repeat('0', 64);
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
}
