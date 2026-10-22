<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RiasecCodeSamplePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $commit = 'a'): PromotionContext
    {
        $chain = "fermatmind.riasec_code_samples.v1\n";
        foreach (['riasec-code-samples.json', 'riasec-code-samples-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), hash('sha256', $chain), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat($commit, 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 6, str_repeat('e', 64));
    }

    public static function environments(): array
    {
        return [['staging'], ['production']];
    }

    #[DataProvider('environments')]
    public function test_six_native_articles_publish_rollback_and_new_owner_resume_without_deleting_history(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        Event::fake([PublicAuthorityChanged::class]);
        $context = $this->context();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];
        $before = $states->read($context->packageSha256);
        self::assertCount(6, array_filter($before, fn ($saved) => $saved['missing']));
        $states->assertBaseline($rows);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(6, $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        foreach ($rows as $row) {
            $article = Article::withoutGlobalScopes()->where($row['identity'])->firstOrFail();
            self::assertTrue($article->is_indexable && $article->sitemap_eligible && $article->llms_eligible);
            self::assertSame($row['source_identity']['translation_group_id'], $article->translation_group_id);
            self::assertSame($row['snapshot']['content_md'], $article->publishedRevision->content_md);
            self::assertTrue(data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled'));
        }
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        Cache::flush();
        self::assertSame(6, DB::table('articles')->count());
        self::assertSame(6, DB::table('article_translation_revisions')->count());
        self::assertSame(0, DB::table('articles')->where('is_public', true)->count());
        foreach ($rows as $row) {
            $this->getJson('/api/v0.5/articles/'.$row['identity']['slug'].'?locale='.$row['identity']['locale'])->assertNotFound();
        }
        // A fresh operation/commit may use only the exact retained package draft.
        $private = $states->read($context->packageSha256);
        self::assertCount(6, array_filter($private, fn ($saved) => $saved['retained_draft']));
        $states->assertBaseline($rows);
        $republished = DB::transaction(fn () => $writer->publish($this->context('f'), $private));
        self::assertSame(6, $republished['written_count']);
        self::assertSame(12, DB::table('article_translation_revisions')->count());
        Cache::flush();
        $states->assertPublished($rows, $republished['state']);
    }

    public function test_existing_operator_draft_and_late_failure_cannot_be_overwritten(): void
    {
        Event::fake([PublicAuthorityChanged::class]);
        $context = $this->context();
        $states = app(PublicContent53State::class);
        $before = $states->read($context->packageSha256);
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'insert into "article_translation_revisions"') && DB::table('articles')->count() >= 4) {
                $armed = false;
                throw new DomainException('injected_late_sample_failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
            self::fail('Late write must fail atomically');
        } catch (DomainException $error) {
            self::assertSame('injected_late_sample_failure', $error->getMessage());
        }
        self::assertSame(0, DB::table('articles')->count());
        self::assertSame(0, DB::table('article_translation_revisions')->count());
        $row = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'][0];
        Article::withoutGlobalScopes()->create($row['identity'] + ['title' => 'Operator draft', 'content_md' => 'Keep this draft', 'status' => 'draft', 'is_public' => false]);
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
            self::fail('Operator draft must be rejected');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_identity_or_hold', $error->getMessage());
        }
        self::assertSame('Keep this draft', DB::table('articles')->value('content_md'));
    }

    public static function publicationOutcomes(): array
    {
        return [['success'], ['cache'], ['receipt']];
    }

    #[DataProvider('publicationOutcomes')]
    public function test_real_service_reports_six_new_qualifications_and_recovers_closeout_failures(string $outcome): void
    {
        Event::fake([PublicAuthorityChanged::class]);
        $context = $this->context();
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        $failed = false;
        Http::fake(function ($request) use ($outcome, &$failed) {
            $payload = $request->data();
            if ($outcome === 'cache' && ! $failed) {
                $failed = true;

                return Http::response(['ok' => false], 500);
            }
            $content = $payload['content'];
            $tags = $content['type'] === 'article' ? ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']] : [];

            return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => $tags]);
        });
        $directory = sys_get_temp_dir().'/riasec-sample-service-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $service = app(\App\Services\ContentPromotion\ExactPackagePromotionService::class);
        $previous = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                if ($phase === 'publish' && $outcome === 'receipt') {
                    file_put_contents($path, 'Immutable existing receipt');
                }
                try {
                    $result = $service->execute($context, $phase, $path);
                } catch (DomainException $error) {
                    self::assertSame('publish', $phase);
                    self::assertNotSame('success', $outcome);
                    self::assertSame($outcome === 'cache' ? 'public_content_53_cache_closeout_failed' : 'public_content_53_receipt_failed_rollback_succeeded', $error->getMessage());
                    self::assertSame(0, DB::table('articles')->where('is_public', true)->count());
                    self::assertSame(6, DB::table('article_translation_revisions')->count());

                    return;
                }
                self::assertSame(6, $result['receipt']['readback_count']);
                foreach (['indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count'] as $key) {
                    self::assertSame($phase === 'publish' ? 6 : 0, $result['receipt'][$key]);
                }
                self::assertSame(0, $result['receipt']['search_mutation_count']);
                $previous = $path;
            }
            self::assertSame('success', $outcome);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_private_resume_cas_preserves_concurrent_editor_change(): void
    {
        Event::fake([PublicAuthorityChanged::class]);
        $context = $this->context();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($context->packageSha256);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        $private = $states->read($context->packageSha256);
        DB::table('article_seo_meta')->where('id', DB::table('article_seo_meta')->min('id'))->update(['seo_description' => 'Concurrent private SEO edit']);
        try {
            DB::transaction(fn () => $writer->publish($context, $private));
            self::fail('CAS must preserve concurrent edits');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        self::assertSame(1, DB::table('article_seo_meta')->where('seo_description', 'Concurrent private SEO edit')->count());
    }

    public function test_restored_private_articles_invalidate_only_their_exact_detail_targets(): void
    {
        Event::fake([PublicAuthorityChanged::class]);
        $context = $this->context();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($context->packageSha256);
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-revalidation-secret']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $payload = $request->data();
            if ($payload['content']['type'] === 'public_content_53') {
                self::assertSame(['/en/articles', '/zh/articles', '/sitemap.xml', '/llms.txt', '/llms-full.txt'], $payload['cache_signal']['paths']);

                return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => []]);
            }
            self::assertGreaterThan(0, $payload['content']['published_revision_id']);
            $locale = $payload['content']['locale'];
            $slug = $payload['content']['slug'];

            return Http::response(['ok' => true, 'revalidated_paths' => $payload['cache_signal']['paths'], 'rejected_paths' => [],
                'invalidated_tags' => ['article-detail:'.$locale.':'.$slug, 'article-seo:'.$locale.':'.$slug]]);
        });
        app(PublicContent53FrontendRevalidator::class)->revalidate($rows);
        Http::assertSentCount(7);
    }
}
