<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** Uses the existing signed, bounded frontend endpoint; never submits URLs. */
final class PublicContent53FrontendRevalidator
{
    public function revalidate(array $rows): void
    {
        [$endpoint, $secret] = $this->configuration();
        $rateLimitWaited = false;
        $requests = $rows;
        if (array_filter($rows, static fn ($row): bool => ($row['new_article'] ?? false) === true)) {
            $requests[] = ['kind' => 'discovery', 'identity' => ['locale' => 'en', 'slug' => 'articles']];
        }
        foreach ($requests as $row) {
            $segment = $row['identity']['locale'] === 'en' ? 'en' : 'zh';
            $slug = $row['identity']['slug'];
            $tags = [];
            if ($row['kind'] === 'discovery') {
                $paths = ['/en/articles', '/zh/articles', '/sitemap.xml', '/llms.txt', '/llms-full.txt'];
                $content = ['type' => 'public_content_53'];
            } elseif ($row['kind'] === 'article') {
                $article = Article::query()->withoutGlobalScopes()->where($row['identity'])->firstOrFail();
                $revision = $article->publishedRevision;
                if (! $revision && ($row['new_article'] ?? false) && RiasecCodeSampleArticles::isRestored($article->getRawOriginal(), (string) $article->workingRevision?->authority_package_sha256)) {
                    $revision = $article->workingRevision;
                }
                if (! $revision) {
                    throw new DomainException('public_content_53_revalidation_revision_missing');
                }
                $paths = ["/$segment/articles/$slug"];
                $content = ['type' => 'article', 'slug' => $slug, 'locale' => $row['identity']['locale'],
                    'path_scope' => 'article_detail_only', 'published_revision_id' => $revision->id,
                    'content_sha256' => hash('sha256', (string) $revision->content_md)];
                $tags = ['article-detail:'.$row['identity']['locale'].':'.$slug, 'article-seo:'.$row['identity']['locale'].':'.$slug];
            } elseif ($row['kind'] === 'guide' && $slug === 'from-mbti-to-job-fit') {
                $paths = ["/$segment/career/guides/$slug"];
                $content = ['type' => 'public_content_53'];
            } elseif ($row['kind'] === 'profile') {
                $paths = ["/$segment/topics/$slug"];
                $content = ['type' => 'public_content_53'];
            } elseif ($row['kind'] === 'page' && in_array($slug, ['about', 'careers', 'charter', 'help-faq', 'help-for-business-and-research'], true)) {
                $path = str_starts_with($slug, 'help-') ? 'help/'.substr($slug, 5) : $slug;
                $paths = ["/$segment/$path"];
                $content = ['type' => 'public_content_53'];
            } else {
                // Policy, science and directory paths are outside the existing
                // endpoint allowlist. Online acceptance waits for their existing
                // 300-second public fetch cache; it never broadens that allowlist.
                continue;
            }
            $body = json_encode(['content' => $content, 'cache_signal' => ['paths' => $paths]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            do {
                $timestamp = (string) time();
                $nonce = bin2hex(random_bytes(16));
                try {
                    $response = Http::acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
                        ->withHeaders(['X-FM-Content-Release-Timestamp' => $timestamp, 'X-FM-Content-Release-Nonce' => $nonce,
                            'X-FM-Content-Release-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret)])
                        ->withBody($body, 'application/json')->post($endpoint);
                } catch (\Illuminate\Http\Client\ConnectionException $error) {
                    throw new DomainException('public_content_53_revalidation_transport_failed', previous: $error);
                }
                $receipt = $response->json();
                if ($response->status() === 429 && ($receipt['error_code'] ?? null) === 'RATE_LIMITED' && ! $rateLimitWaited) {
                    // The existing endpoint uses a 300-second window. Permit one
                    // bounded wait for derived-cache recovery, with a fresh nonce.
                    $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 300]]);
                    $rateLimitWaited = true;
                    Sleep::for($retryAfter === false ? 300 : $retryAfter)->seconds();

                    continue;
                }
                break;
            } while (true);
            if (! $response->successful()) {
                throw new DomainException('public_content_53_revalidation_http_'.$response->status());
            }
            if (($receipt['ok'] ?? null) !== true || ($receipt['revalidated_paths'] ?? null) !== $paths
                || ($receipt['rejected_paths'] ?? null) !== [] || ($receipt['invalidated_tags'] ?? null) !== $tags) {
                throw new DomainException('public_content_53_revalidation_failed');
            }
        }
    }

    public function assertConfigured(): void
    {
        $this->configuration();
    }

    private function configuration(): array
    {
        $endpoint = trim((string) config('ops.content_release_observability.hmac_revalidation_url', ''));
        $secret = trim((string) config('ops.content_release_observability.hmac_revalidation_secret', ''));
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || ($url['path'] ?? '') !== '/api/content-release/revalidate'
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $secret === '') {
            throw new DomainException('public_content_53_revalidation_not_configured');
        }

        return [$endpoint, $secret];
    }
}
