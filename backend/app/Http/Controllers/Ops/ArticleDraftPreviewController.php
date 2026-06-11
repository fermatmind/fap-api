<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

final class ArticleDraftPreviewController extends Controller
{
    public function __invoke(Request $request, string $article): Response
    {
        $articleId = (int) $article;
        abort_unless($articleId > 0, 404);

        $orgId = $this->trustedOrgId($request);
        $record = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'category' => static fn ($query) => $query->withoutGlobalScopes(),
                'tags' => static fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->whereKey($articleId)
            ->whereIn('org_id', $this->allowedOrgIds($orgId))
            ->firstOrFail();

        $revision = $record->workingRevision instanceof ArticleTranslationRevision
            ? $record->workingRevision
            : null;
        $seoMeta = $record->seoMeta instanceof ArticleSeoMeta ? $record->seoMeta : null;

        $title = $this->firstNonEmpty($revision?->title, $record->title, 'Untitled article draft');
        $excerpt = $this->firstNonEmpty($revision?->excerpt, $record->excerpt, '');
        $contentMd = $this->firstNonEmpty($revision?->content_md, $record->content_md, '');
        $seoTitle = $this->firstNonEmpty($revision?->seo_title, $seoMeta?->seo_title, $title);
        $seoDescription = $this->firstNonEmpty($revision?->seo_description, $seoMeta?->seo_description, $excerpt);
        $redacted = $this->redactSensitivePreviewText($contentMd);
        $bodyHtml = (string) Str::markdown($redacted['text'], [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = View::make('ops.article-draft-preview', [
            'article' => $record,
            'revision' => $revision,
            'title' => $title,
            'excerpt' => $excerpt,
            'bodyHtml' => $bodyHtml,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $this->safeCanonicalUrl($seoMeta?->canonical_url),
            'publicUrl' => $this->publicUrl($record),
            'coverImageUrl' => PublicMediaUrlGuard::sanitizeNullableUrl($record->cover_image_url),
            'redactionCount' => $redacted['count'],
            'previewContext' => [
                'is_preview' => true,
                'article_id' => (int) $record->id,
                'working_revision_id' => $revision?->id !== null ? (int) $revision->id : null,
                'published_revision_id' => $record->published_revision_id !== null ? (int) $record->published_revision_id : null,
                'status' => (string) $record->status,
                'is_public' => (bool) $record->is_public,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_allowed' => false,
                'schema_enabled' => false,
                'hreflang_enabled' => false,
                'revalidation_allowed' => false,
            ],
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, noarchive, nosnippet',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * @return array{text:string,count:int}
     */
    private function redactSensitivePreviewText(string $value): array
    {
        $count = 0;
        $patterns = [
            '#https?://[^\s)>\"]*/(?:result|results|orders?|payment|pay|share|history|take)(?:/[^\s)>\"]*)?#i',
            '#(?<![A-Za-z0-9_-])/(?:result|results|orders?|payment|pay|share|history)(?:/[^\s)>\"]*)?#i',
            '#(?<![A-Za-z0-9_-])/(?:en|zh)/tests/[A-Za-z0-9_-]+/take(?:/[^\s)>\"]*)?#i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '[redacted-private-url]', $value, -1, $replacements) ?? $value;
            $count += $replacements;
        }

        $value = preg_replace(
            '/([?&](?:token|access_token|result_access_token|order_id|payment_intent|payment_recovery_token|session_id|checkout_id)=)[^&#\s)>\"]+/i',
            '$1[redacted]',
            $value,
            -1,
            $replacements
        ) ?? $value;
        $count += $replacements;

        return ['text' => $value, 'count' => $count];
    }

    private function trustedOrgId(Request $request): int
    {
        foreach ([
            $request->attributes->get('fm_org_id'),
            $request->attributes->get('org_id'),
            $request->hasSession() ? $request->session()->get('ops_org_id') : null,
        ] as $candidate) {
            if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $raw = trim((string) $candidate);
            if ($raw !== '' && preg_match('/^\d+$/', $raw) === 1) {
                return max(0, (int) $raw);
            }
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    private function allowedOrgIds(int $trustedOrgId): array
    {
        return $trustedOrgId > 0 ? [0, $trustedOrgId] : [0];
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) ($value ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function safeCanonicalUrl(?string $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function publicUrl(Article $article): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = trim((string) $article->slug);
        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        $segment = str_starts_with(strtolower(trim((string) $article->locale)), 'zh') ? 'zh' : 'en';

        return $baseUrl.'/'.$segment.'/articles/'.rawurlencode($slug);
    }
}
