<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoAgentReplaceArticleCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://api.fermatmind.test',
            'app.frontend_url' => 'https://fermatmind.test',
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        Storage::fake('public');
        Storage::fake('s3');
    }

    public function test_valid_manifest_dry_run_is_strictly_zero_write(): void
    {
        [$manifest] = $this->fixture();

        $exit = Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exit, json_encode($payload, JSON_PRETTY_PRINT));
        $this->assertTrue($payload['ok']);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame('passed', $payload['overall_status']);
        $this->assertSame([], $payload['baseline_records']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MediaVariant::query()->count());
        $this->assertSame('中文旧图', Article::query()->withoutGlobalScopes()->findOrFail(101)->cover_image_alt);
    }

    public function test_duplicate_id_or_slug_and_wrong_locale_pair_fail_entire_batch_without_writes(): void
    {
        [$manifest] = $this->fixture(function (array &$payload): void {
            $payload['groups'][] = $payload['groups'][0];
            $payload['groups'][1]['locales']['en']['article_id'] = 101;
            unset($payload['groups'][1]['locales']['zh-CN']);
        });

        $exit = Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertContains('duplicate_slug', $this->errorCodes($payload));
        $this->assertContains('duplicate_article_id', $this->errorCodes($payload));
        $this->assertContains('locale_pair_missing', $this->errorCodes($payload));
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_missing_or_noncompliant_image_fails_before_any_write(): void
    {
        [$missing] = $this->fixture(function (array &$payload): void {
            $payload['groups'][0]['source_image'] = '/definitely/missing.png';
        });
        $this->assertSame(1, Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $missing]));
        $this->assertContains('source_file_missing', $this->errorCodes($this->jsonOutput()));

        ArticleSeoMeta::query()->withoutGlobalScopes()->delete();
        ArticleTranslationRevision::query()->withoutGlobalScopes()->delete();
        Article::query()->withoutGlobalScopes()->forceDelete();
        [$small] = $this->fixture(null, 800, 450);
        $this->assertSame(1, Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $small]));
        $this->assertContains('image_dimensions_too_small', $this->errorCodes($this->jsonOutput()));
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_requires_exact_authorization_and_all_holds(): void
    {
        [$manifest] = $this->fixture();

        $exit = Artisan::call('seo-agent:replace-article-covers', [
            '--manifest' => $manifest,
            '--execute' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertContains('required_safety_hold_missing', $this->errorCodes($payload));
        $this->assertContains('manifest_sha256_confirmation_mismatch', $this->errorCodes($payload));
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_media_runtime_failure_is_caught_before_first_write(): void
    {
        [$manifest] = $this->fixture();
        config(['fap.media.oss_sync_enabled' => false]);

        $exit = Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertContains('media_runtime_not_ready', $this->errorCodes($payload));
        $this->assertFalse($payload['writes_attempted']);
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_any_article_preflight_failure_blocks_the_entire_cohort(): void
    {
        [$manifest] = $this->fixture(function (array &$payload): void {
            $payload['groups'][0]['locales']['en']['article_id'] = 999999;
        });

        $exit = Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertContains('article_not_found', $this->errorCodes($payload));
        $this->assertFalse($payload['writes_attempted']);
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame('中文旧图', Article::query()->withoutGlobalScopes()->findOrFail(101)->cover_image_alt);
    }

    public function test_missing_seo_baseline_requires_manifest_and_command_authorization(): void
    {
        [$manifest] = $this->fixture(null, 1600, 900, missingEnglishSeo: true);

        $this->assertSame(1, Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]));
        $this->assertContains('seo_meta_baseline_not_authorized', $this->errorCodes($this->jsonOutput()));

        $payload = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $payload['groups'][0]['allow_ensure_seo_meta_baseline'] = true;
        file_put_contents($manifest, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame(1, Artisan::call('seo-agent:replace-article-covers', ['--manifest' => $manifest]));
        $this->assertContains('seo_meta_baseline_not_authorized', $this->errorCodes($this->jsonOutput()));

        $this->assertSame(0, Artisan::call('seo-agent:replace-article-covers', [
            '--manifest' => $manifest,
            '--allow-ensure-seo-meta-baseline' => true,
        ]));
        $this->assertTrue($this->jsonOutput()['ok']);
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 202)->count());
    }

    public function test_execute_updates_localized_alt_preserves_noindex_and_verifies_all_surfaces(): void
    {
        [$manifest] = $this->fixture(null, 1600, 900, missingEnglishSeo: true, allowBaseline: true);
        $sha = hash_file('sha256', $manifest);
        $this->fakeSuccessfulRuntime();
        Storage::fake('s3');
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);

        $receipt = sys_get_temp_dir().'/cover-receipt-'.Str::random(8).'.json';
        $exit = Artisan::call('seo-agent:replace-article-covers', $this->executeOptions($manifest, $sha, [
            '--allow-ensure-seo-meta-baseline' => true,
            '--receipt' => $receipt,
            '--verify-attempts' => '1',
            '--verify-delay-ms' => '0',
        ]));
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('passed', $payload['overall_status']);
        $this->assertSame('ensured_seo_meta_baseline', $payload['baseline_records'][0]['action']);
        $this->assertFileExists($receipt);
        $this->assertSame('中文新封面', Article::query()->withoutGlobalScopes()->findOrFail(101)->cover_image_alt);
        $this->assertSame('English new cover', Article::query()->withoutGlobalScopes()->findOrFail(202)->cover_image_alt);
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 202)->firstOrFail();
        $this->assertSame('https://fermatmind.test/en/articles/example-cover', $seo->canonical_url);
        $this->assertSame('noindex,nofollow', $seo->robots);
        $this->assertFalse((bool) $seo->is_indexable);
        $english = Article::query()->withoutGlobalScopes()->findOrFail(202);
        $this->assertFalse((bool) $english->is_indexable);
        $this->assertFalse((bool) $english->sitemap_eligible);
        $this->assertFalse((bool) $english->llms_eligible);
    }

    public function test_post_write_cache_timeout_is_reported_as_partial(): void
    {
        [$manifest] = $this->fixture();
        $sha = hash_file('sha256', $manifest);
        Storage::fake('s3');
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        Http::fake([
            'https://assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response([], 503),
        ]);

        $exit = Artisan::call('seo-agent:replace-article-covers', $this->executeOptions($manifest, $sha, [
            '--verify-attempts' => '1',
            '--verify-delay-ms' => '0',
        ]));
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertSame('partial', $payload['overall_status']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['verification']['converged']);
    }

    public function test_media_failure_after_execute_starts_is_reported_as_partial(): void
    {
        [$manifest] = $this->fixture();
        $sha = hash_file('sha256', $manifest);
        Http::fake(['*' => Http::response('', 503)]);

        $exit = Artisan::call('seo-agent:replace-article-covers', $this->executeOptions($manifest, $sha));
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertSame('partial', $payload['overall_status']);
        $this->assertTrue($payload['writes_attempted']);
        $this->assertSame('failed', $payload['groups'][0]['status']);
    }

    public function test_article_update_failure_after_media_and_first_locale_is_reported_as_partial(): void
    {
        [$manifest] = $this->fixture();
        $sha = hash_file('sha256', $manifest);
        $this->fakeSuccessfulRuntime();
        DB::statement("CREATE TRIGGER fail_english_cover_update BEFORE UPDATE OF cover_image_url ON articles WHEN NEW.id = 202 BEGIN SELECT RAISE(FAIL, 'forced article update failure'); END");

        $exit = Artisan::call('seo-agent:replace-article-covers', $this->executeOptions($manifest, $sha));
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertSame('partial', $payload['overall_status']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame('中文新封面', Article::query()->withoutGlobalScopes()->findOrFail(101)->cover_image_alt);
        $this->assertSame('English old cover', Article::query()->withoutGlobalScopes()->findOrFail(202)->cover_image_alt);
    }

    public function test_execute_is_idempotent_for_the_same_asset_key_and_articles(): void
    {
        [$manifest] = $this->fixture();
        Storage::fake('s3');
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        $this->fakeSuccessfulRuntime();
        $sha = hash_file('sha256', $manifest);
        $options = $this->executeOptions($manifest, $sha, [
            '--verify-attempts' => '1',
            '--verify-delay-ms' => '0',
        ]);

        $this->assertSame(0, Artisan::call('seo-agent:replace-article-covers', $options), Artisan::output());
        $assetId = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.example-cover.cover.v1')->value('id');
        $this->assertSame(0, Artisan::call('seo-agent:replace-article-covers', $options), Artisan::output());

        $this->assertSame(1, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame($assetId, MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.example-cover.cover.v1')->value('id'));
        $this->assertSame(6, MediaVariant::query()->count());
        $this->assertSame('中文新封面', Article::query()->withoutGlobalScopes()->findOrFail(101)->cover_image_alt);
        $this->assertSame('English new cover', Article::query()->withoutGlobalScopes()->findOrFail(202)->cover_image_alt);
    }

    /** @return array{0:string,1:string} */
    private function fixture(?callable $mutate = null, int $width = 1600, int $height = 900, bool $missingEnglishSeo = false, bool $allowBaseline = false): array
    {
        $root = sys_get_temp_dir().'/cover-batch-'.Str::random(10);
        mkdir($root, 0777, true);
        $image = $root.'/example-cover.png';
        $this->writePng($image, $width, $height);
        $this->createArticle(101, 'zh-CN', '中文旧图', true);
        $this->createArticle(202, 'en', 'English old cover', ! $missingEnglishSeo);
        $payload = [
            'schema_version' => 'article-cover-replacement.v1',
            'batch_id' => 'test-cover-batch',
            'groups' => [[
                'translation_group_id' => 'article-example-cover',
                'slug' => 'example-cover',
                'source_image' => $image,
                'asset_key' => 'article.example-cover.cover.v1',
                'allow_ensure_seo_meta_baseline' => $allowBaseline,
                'locales' => [
                    'zh-CN' => $this->localePayload(101, 'zh-CN', '中文新封面'),
                    'en' => $this->localePayload(202, 'en', 'English new cover'),
                ],
            ]],
        ];
        if ($mutate !== null) {
            $mutate($payload);
        }
        $manifest = $root.'/manifest.json';
        file_put_contents($manifest, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$manifest, $image];
    }

    /** @return array<string,mixed> */
    private function localePayload(int $id, string $locale, string $alt): array
    {
        $path = $locale === 'en' ? 'en' : 'zh';

        return [
            'article_id' => $id,
            'alt' => $alt,
            'canonical' => 'https://fermatmind.test/'.$path.'/articles/example-cover',
            'robots' => 'noindex,nofollow',
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ];
    }

    private function createArticle(int $id, string $locale, string $alt, bool $withSeo): void
    {
        $article = new Article([
            'org_id' => 0,
            'slug' => 'example-cover',
            'locale' => $locale,
            'translation_group_id' => 'article-example-cover',
            'source_locale' => $locale,
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => $locale === 'en' ? 'Example cover' : '示例封面',
            'excerpt' => 'Example excerpt.',
            'content_md' => 'Example body.',
            'cover_image_alt' => $alt,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => now(),
        ]);
        $article->id = $id;
        $article->save();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => $id,
            'source_article_id' => $id,
            'translation_group_id' => 'article-example-cover',
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->source_version_hash,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
            'published_at' => now(),
        ]);
        $article->forceFill(['published_revision_id' => $revision->id])->save();
        if ($withSeo) {
            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => $id,
                'locale' => $locale,
                'seo_title' => $article->title,
                'seo_description' => $article->excerpt,
                'canonical_url' => $this->localePayload($id, $locale, $alt)['canonical'],
                'robots' => 'noindex,nofollow',
                'is_indexable' => false,
            ]);
        }
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function executeOptions(string $manifest, string $sha, array $extra = []): array
    {
        return array_merge([
            '--manifest' => $manifest,
            '--execute' => true,
            '--actor' => 'test-operator',
            '--reason' => 'test-cover-batch',
            '--confirm-manifest-sha256' => $sha,
            '--confirm-execution' => 'EXECUTE ARTICLE COVER BATCH '.$sha,
            '--no-publish' => true,
            '--no-schema' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-sitemap-llms-change' => true,
            '--no-revalidation' => true,
        ], $extra);
    }

    private function fakeSuccessfulRuntime(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://assets.fermatmind.com/')) {
                return Http::response('', 200, ['Content-Type' => 'image/jpeg']);
            }
            $asset = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.example-cover.cover.v1')->with('variants')->first();
            $cover = (string) data_get($asset?->variants?->keyBy('variant_key'), 'hero.url', '');
            $og = (string) data_get($asset?->variants?->keyBy('variant_key'), 'og.url', '');
            if (str_contains($url, '/api/v0.5/articles/example-cover/seo')) {
                return Http::response(['meta' => ['og' => ['image' => $og], 'twitter' => ['image' => $og]]], 200);
            }
            if (str_contains($url, '/api/v0.5/articles/example-cover')) {
                return Http::response(['article' => ['cover_image_url' => $cover]], 200);
            }
            if (str_contains($url, '/api/v0.5/articles')) {
                $locale = (string) $request->data()['locale'];
                $id = $locale === 'en' ? 202 : 101;

                return Http::response(['items' => [['id' => $id, 'cover_image_url' => $cover]]], 200);
            }

            return Http::response('<meta property="og:image" content="'.$og.'">', 200, ['Content-Type' => 'text/html']);
        });
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function errorCodes(array $payload): array
    {
        return array_values(array_filter(array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            (array) ($payload['errors'] ?? [])
        )));
    }

    private function writePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 247, 244));
        imagepng($image, $path);
        imagedestroy($image);
    }
}
