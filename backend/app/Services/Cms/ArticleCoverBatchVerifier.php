<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ArticleCoverBatchVerifier
{
    private const REQUIRED_VARIANTS = ['hero', 'card', 'thumbnail', 'og', 'preload'];

    /**
     * @param  list<array<string,mixed>>  $targets
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function verify(array $targets, array $options): array
    {
        $attempts = max(1, min(20, (int) ($options['verify_attempts'] ?? 6)));
        $delayMs = max(0, min(300000, (int) ($options['verify_delay_ms'] ?? 60000)));
        $last = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = array_map(function (array $target) use ($options): array {
                try {
                    return $this->verifyTarget($target, $options);
                } catch (\Throwable $exception) {
                    return [
                        'article_id' => (int) ($target['article_id'] ?? 0),
                        'slug' => (string) ($target['slug'] ?? ''),
                        'locale' => (string) ($target['locale'] ?? ''),
                        'ok' => false,
                        'variants' => [],
                        'errors' => [[
                            'field' => 'transport',
                            'code' => 'verification_transport_failed',
                        ]],
                    ];
                }
            }, $targets);
            if (collect($last)->every(static fn (array $row): bool => (bool) ($row['ok'] ?? false))) {
                return ['ok' => true, 'converged' => true, 'attempts' => $attempt, 'targets' => $last];
            }
            if ($attempt < $attempts && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['ok' => false, 'converged' => false, 'attempts' => $attempts, 'targets' => $last];
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $options @return array<string,mixed> */
    private function verifyTarget(array $target, array $options): array
    {
        $api = rtrim((string) ($options['api_base_url'] ?? config('app.url')), '/');
        $frontend = rtrim((string) ($options['frontend_base_url'] ?? config('app.frontend_url')), '/');
        $slug = (string) $target['slug'];
        $locale = (string) $target['locale'];
        $cover = (string) $target['cover_image_url'];
        $og = (string) $target['og_image_url'];
        $errors = [];

        $detail = $this->get($api.'/api/v0.5/articles/'.rawurlencode($slug), ['locale' => $locale, 'org_id' => 0]);
        $this->assertJsonImage($detail, 'article.cover_image_url', $cover, 'detail', $errors);

        $listMatched = false;
        for ($page = 1; $page <= 5; $page++) {
            $list = $this->get($api.'/api/v0.5/articles', ['locale' => $locale, 'org_id' => 0, 'page' => $page, 'per_page' => 20]);
            if (! $list->successful()) {
                continue;
            }
            foreach ((array) $list->json('items', []) as $item) {
                if ((int) ($item['id'] ?? 0) === (int) $target['article_id']) {
                    $listMatched = hash_equals($cover, (string) ($item['cover_image_url'] ?? ''));
                    break 2;
                }
            }
        }
        if (! $listMatched) {
            $errors[] = $this->issue('list', 'list_cover_not_converged');
        }

        $seo = $this->get($api.'/api/v0.5/articles/'.rawurlencode($slug).'/seo', ['locale' => $locale, 'org_id' => 0]);
        if (! $seo->successful() || ! hash_equals($og, (string) $seo->json('meta.og.image', '')) || ! hash_equals($og, (string) $seo->json('meta.twitter.image', ''))) {
            $errors[] = $this->issue('seo', 'social_image_not_converged');
        }

        $localePath = $locale === 'en' ? 'en' : 'zh';
        $html = $this->get($frontend.'/'.$localePath.'/articles/'.rawurlencode($slug));
        if (! $html->successful() || ! str_contains((string) $html->body(), $og)) {
            $errors[] = $this->issue('html', 'html_social_image_not_converged');
        }

        $variants = [];
        foreach (self::REQUIRED_VARIANTS as $key) {
            $url = (string) data_get($target, 'cover_image_variants.'.$key.'.url', '');
            $response = $url !== '' ? $this->get($url) : null;
            $ok = $response instanceof Response && $response->successful();
            $variants[$key] = ['url' => $url, 'ok' => $ok, 'status' => $response?->status()];
            if (! $ok) {
                $errors[] = $this->issue('variants.'.$key, 'variant_unavailable');
            }
        }

        return [
            'article_id' => (int) $target['article_id'],
            'slug' => $slug,
            'locale' => $locale,
            'ok' => $errors === [],
            'variants' => $variants,
            'errors' => $errors,
        ];
    }

    /** @param array<string,mixed> $query */
    private function get(string $url, array $query = []): Response
    {
        return Http::accept('*/*')->withoutRedirecting()->timeout(20)->get($url, $query);
    }

    /** @param list<array<string,string>> $errors */
    private function assertJsonImage(Response $response, string $path, string $expected, string $field, array &$errors): void
    {
        if (! $response->successful() || ! hash_equals($expected, (string) $response->json($path, ''))) {
            $errors[] = $this->issue($field, 'cover_not_converged');
        }
    }

    /** @return array{field:string,code:string} */
    private function issue(string $field, string $code): array
    {
        return ['field' => $field, 'code' => $code];
    }
}
