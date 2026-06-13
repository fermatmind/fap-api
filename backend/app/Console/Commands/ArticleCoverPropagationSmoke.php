<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ArticleCoverPropagationSmoke extends Command
{
    private const REQUIRED_VARIANTS = ['hero', 'card', 'thumbnail', 'og', 'preload'];

    protected $signature = 'articles:cover-smoke
        {--article=* : Article id to verify}
        {--slug=* : Article slug to verify}
        {--locale= : Optional locale used when resolving slugs}
        {--org-id=0 : Public org id}
        {--max-pages=5 : Maximum list pages to scan for the article card}
        {--json : Emit a JSON summary}';

    protected $description = 'Verify published article cover propagation through public detail, list, SEO, and JSON-LD payloads.';

    public function handle(HttpKernel $kernel): int
    {
        $articles = $this->resolveArticles();
        $errors = [];

        if ($articles === []) {
            $errors[] = $this->issue('target', 'target_missing', 'At least one --article or --slug target is required.');
        }

        $results = [];
        foreach ($articles as $article) {
            $results[] = $this->verifyArticle($kernel, $article);
        }

        $ok = $errors === [] && collect($results)->every(static fn (array $result): bool => (bool) ($result['ok'] ?? false));
        $summary = [
            'ok' => $ok,
            'org_id' => $this->orgId(),
            'max_pages' => $this->maxPages(),
            'article_ids' => array_map(static fn (Article $article): int => (int) $article->id, $articles),
            'articles' => $results,
            'errors' => $errors,
        ];

        $this->emitSummary($summary);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<Article>
     */
    private function resolveArticles(): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            (array) $this->option('article')
        ), static fn (int $id): bool => $id > 0)));

        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->option('slug')
        ), static fn (string $slug): bool => $slug !== '')));

        if ($ids === [] && $slugs === []) {
            return [];
        }

        $query = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'seoMeta',
            ])
            ->where('org_id', $this->orgId())
            ->where(static function ($targetQuery) use ($ids, $slugs): void {
                if ($ids !== []) {
                    $targetQuery->orWhereIn('id', $ids);
                }

                if ($slugs !== []) {
                    $targetQuery->orWhereIn('slug', $slugs);
                }
            });

        $locale = trim((string) $this->option('locale'));
        if ($locale !== '') {
            $query->where('locale', $locale);
        }

        return $query
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyArticle(HttpKernel $kernel, Article $article): array
    {
        $errors = [];
        $expectedCover = PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url);
        $expectedSocialImage = $this->expectedSocialImageUrl($article, $expectedCover);

        if (! $article->published_revision_id || (string) $article->status !== 'published' || ! (bool) $article->is_public) {
            $errors[] = $this->issue('article', 'article_not_publicly_readable', 'Article is not published and public.');
        }

        if ($expectedCover === null) {
            $errors[] = $this->issue('article.cover_image_url', 'cover_url_not_public_safe', 'Article cover image URL is missing or not public-safe.');
        }

        if (trim((string) $article->cover_image_alt) === '') {
            $errors[] = $this->issue('article.cover_image_alt', 'cover_alt_missing', 'Article cover alt text is missing.');
        }

        if ((int) ($article->cover_image_width ?? 0) <= 0 || (int) ($article->cover_image_height ?? 0) <= 0) {
            $errors[] = $this->issue('article.cover_dimensions', 'cover_dimensions_missing', 'Article cover dimensions are missing.');
        }

        $this->assertVariantReadiness(
            is_array($article->cover_image_variants) ? $article->cover_image_variants : [],
            'article.cover_image_variants',
            $errors
        );

        $detail = $this->publicJson($kernel, sprintf(
            '/api/v0.5/articles/%s?locale=%s&org_id=%d',
            rawurlencode((string) $article->slug),
            rawurlencode((string) $article->locale),
            $this->orgId()
        ));
        $this->assertSuccessfulPayload($detail, 'detail', $errors);
        $this->assertCoverPayload($detail['json']['article'] ?? null, $expectedCover, 'detail.article', $errors);

        $seo = $this->publicJson($kernel, sprintf(
            '/api/v0.5/articles/%s/seo?locale=%s&org_id=%d',
            rawurlencode((string) $article->slug),
            rawurlencode((string) $article->locale),
            $this->orgId()
        ));
        $this->assertSuccessfulPayload($seo, 'seo', $errors);
        $this->assertSameImageUrl(data_get($seo, 'json.meta.og.image'), $expectedSocialImage, 'seo.meta.og.image', 'social_image_mismatch', $errors);
        $this->assertSameImageUrl(data_get($seo, 'json.meta.twitter.image'), $expectedSocialImage, 'seo.meta.twitter.image', 'social_image_mismatch', $errors);
        $jsonLdImageStatus = $this->articleSchemaHeld($article)
            ? 'schema_hold_skipped'
            : $this->assertJsonLdImage(
                data_get($seo, 'json.jsonld.image'),
                array_values(array_filter([$expectedCover, $expectedSocialImage])),
                $errors
            );

        $listResult = $this->findArticleInList($kernel, $article);
        if (! (bool) ($listResult['found'] ?? false)) {
            $errors[] = $this->issue('list.article', 'article_missing_from_list', 'Article was not found in public article list scan.');
        } else {
            $this->assertCoverPayload($listResult['item'] ?? null, $expectedCover, 'list.article', $errors);
        }

        return [
            'article_id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'cover_image_url' => $expectedCover,
            'social_image_url' => $expectedSocialImage,
            'jsonld_image_status' => $jsonLdImageStatus,
            'list_found' => (bool) ($listResult['found'] ?? false),
            'list_page' => $listResult['page'] ?? null,
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{status:int,json:array<string,mixed>}
     */
    private function publicJson(HttpKernel $kernel, string $path): array
    {
        $request = Request::create($path, 'GET');
        $response = $kernel->handle($request);
        $payload = json_decode((string) $response->getContent(), true);
        $kernel->terminate($request, $response);

        return [
            'status' => (int) $response->getStatusCode(),
            'json' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @return array{found:bool,page:int|null,item:array<string,mixed>|null}
     */
    private function findArticleInList(HttpKernel $kernel, Article $article): array
    {
        for ($page = 1; $page <= $this->maxPages(); $page++) {
            $list = $this->publicJson($kernel, sprintf(
                '/api/v0.5/articles?locale=%s&org_id=%d&page=%d',
                rawurlencode((string) $article->locale),
                $this->orgId(),
                $page
            ));

            if ($list['status'] !== Response::HTTP_OK) {
                continue;
            }

            foreach ((array) data_get($list, 'json.items', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if ((int) ($item['id'] ?? 0) === (int) $article->id) {
                    return ['found' => true, 'page' => $page, 'item' => $item];
                }
            }
        }

        return ['found' => false, 'page' => null, 'item' => null];
    }

    /**
     * @param  array{status:int,json:array<string,mixed>}  $payload
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertSuccessfulPayload(array $payload, string $surface, array &$errors): void
    {
        if ((int) ($payload['status'] ?? 0) !== Response::HTTP_OK) {
            $errors[] = $this->issue($surface, $surface.'_http_not_ok', 'Public '.$surface.' endpoint did not return 200.', [
                'status' => (int) ($payload['status'] ?? 0),
            ]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertCoverPayload(mixed $payload, ?string $expectedCover, string $field, array &$errors): void
    {
        if (! is_array($payload)) {
            $errors[] = $this->issue($field, 'cover_payload_missing', 'Article cover payload is missing.');

            return;
        }

        $this->assertSameCover($payload['cover_image_url'] ?? null, $expectedCover, $field.'.cover_image_url', $errors);

        if (trim((string) ($payload['cover_image_alt'] ?? '')) === '') {
            $errors[] = $this->issue($field.'.cover_image_alt', 'cover_alt_missing', 'Cover alt text is missing from public payload.');
        }

        if ((int) ($payload['cover_image_width'] ?? 0) <= 0 || (int) ($payload['cover_image_height'] ?? 0) <= 0) {
            $errors[] = $this->issue($field.'.cover_dimensions', 'cover_dimensions_missing', 'Cover dimensions are missing from public payload.');
        }

        $this->assertVariantReadiness(
            is_array($payload['cover_image_variants'] ?? null) ? (array) $payload['cover_image_variants'] : [],
            $field.'.cover_image_variants',
            $errors
        );
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertSameCover(mixed $actual, ?string $expectedCover, string $field, array &$errors): void
    {
        $this->assertSameImageUrl($actual, $expectedCover, $field, 'cover_url_mismatch', $errors);
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertSameImageUrl(mixed $actual, ?string $expectedUrl, string $field, string $code, array &$errors): void
    {
        if ($expectedUrl === null || trim((string) $actual) !== $expectedUrl) {
            $errors[] = $this->issue($field, 'cover_url_mismatch', 'Cover URL did not propagate to expected public payload.', [
                'code' => $code,
                'expected' => $expectedUrl,
                'actual' => is_scalar($actual) ? (string) $actual : gettype($actual),
            ]);
        }
    }

    /**
     * @param  list<string>  $acceptedImageUrls
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertJsonLdImage(mixed $image, array $acceptedImageUrls, array &$errors): string
    {
        if ($image === null || $image === [] || $image === '') {
            return 'not_emitted_schema_hold';
        }

        if ($acceptedImageUrls === []) {
            $errors[] = $this->issue('seo.jsonld.image', 'jsonld_cover_missing', 'JSON-LD image cannot be verified without a public-safe cover URL.');

            return 'unverifiable';
        }

        $images = is_array($image) ? array_values($image) : [$image];
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $images
        )));

        foreach ($acceptedImageUrls as $acceptedImageUrl) {
            if (in_array($acceptedImageUrl, $normalized, true)) {
                return 'matched';
            }
        }

        $errors[] = $this->issue('seo.jsonld.image', 'jsonld_cover_mismatch', 'JSON-LD image does not include an accepted public article image URL.');

        return 'mismatch';
    }

    /**
     * @param  array<string,mixed>  $variants
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertVariantReadiness(array $variants, string $field, array &$errors): void
    {
        foreach (self::REQUIRED_VARIANTS as $variantKey) {
            $variant = $variants[$variantKey] ?? null;
            if (! is_array($variant)) {
                $errors[] = $this->issue($field.'.'.$variantKey, 'cover_variant_missing', 'Required article cover variant is missing.');

                continue;
            }

            $url = PublicMediaUrlGuard::sanitizeNullableUrl($variant['url'] ?? null);
            if ($url === null) {
                $errors[] = $this->issue($field.'.'.$variantKey.'.url', 'cover_variant_url_not_public_safe', 'Variant URL is missing or not public-safe.');
            }

            if ((int) ($variant['width'] ?? 0) <= 0 || (int) ($variant['height'] ?? 0) <= 0) {
                $errors[] = $this->issue($field.'.'.$variantKey.'.dimensions', 'cover_variant_dimensions_missing', 'Variant dimensions are missing.');
            }
        }
    }

    private function expectedSocialImageUrl(Article $article, ?string $expectedCover): ?string
    {
        $seoImage = PublicMediaUrlGuard::sanitizeNullableUrl($article->seoMeta?->og_image_url ?? null);
        if ($seoImage !== null) {
            return $seoImage;
        }

        $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
        $ogVariant = $variants['og'] ?? null;
        if (is_array($ogVariant)) {
            $variantUrl = PublicMediaUrlGuard::sanitizeNullableUrl($ogVariant['url'] ?? null);
            if ($variantUrl !== null) {
                return $variantUrl;
            }
        }

        return $expectedCover;
    }

    private function articleSchemaHeld(Article $article): bool
    {
        $schemaJson = $article->relationLoaded('seoMeta') ? $article->seoMeta?->schema_json : null;
        if (! is_array($schemaJson)) {
            return false;
        }

        return data_get($schemaJson, 'editorial_package_v1.article_schema_enabled') === false;
    }

    private function orgId(): int
    {
        $raw = $this->option('org-id');

        return is_numeric($raw) ? max(0, (int) $raw) : 0;
    }

    private function maxPages(): int
    {
        $raw = $this->option('max-pages');

        return is_numeric($raw) ? max(1, min(50, (int) $raw)) : 5;
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('articles='.implode(',', (array) ($summary['article_ids'] ?? [])));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));

        foreach ((array) ($summary['articles'] ?? []) as $article) {
            if (! is_array($article)) {
                continue;
            }

            $this->line(sprintf(
                'article=%s locale=%s slug=%s ok=%s list_found=%s list_page=%s cover=%s',
                (string) ($article['article_id'] ?? ''),
                (string) ($article['locale'] ?? ''),
                (string) ($article['slug'] ?? ''),
                ($article['ok'] ?? false) ? '1' : '0',
                ($article['list_found'] ?? false) ? '1' : '0',
                (string) ($article['list_page'] ?? ''),
                (string) ($article['cover_image_url'] ?? '')
            ));

            foreach ((array) ($article['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $this->line('article_error='.$this->issueLine($error));
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode('|', array_filter([
            'field='.(string) ($issue['field'] ?? ''),
            'code='.(string) ($issue['code'] ?? ''),
            'message='.(string) ($issue['message'] ?? ''),
        ]));
    }
}
