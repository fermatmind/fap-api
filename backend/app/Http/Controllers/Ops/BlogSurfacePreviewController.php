<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LandingSurface;
use App\Services\Cms\ArticleBlogService;
use App\Services\Cms\BlogV1RevisionWorkspace;
use App\Support\CanonicalTranslationPayloadHash;
use Illuminate\Http\Response;
use RuntimeException;

final class BlogSurfacePreviewController extends Controller
{
    public function script(): Response
    {
        return response((string) file_get_contents(resource_path('js/ops-blog-preview.js')), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, noarchive, nosnippet',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function __invoke(string $surface, ArticleBlogService $blog, BlogV1RevisionWorkspace $workspace): Response
    {
        // Global blog CMS resources follow LandingSurfaceResource's org=0 boundary.
        // Authentication, TOTP, selected-org and content-read checks stay on the route.
        $record = LandingSurface::withoutGlobalScopes()->whereKey((int) $surface)
            ->where('org_id', 0)->where('surface_key', 'articles_index')
            ->whereIn('locale', ['en', 'zh-CN'])->firstOrFail();
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        abort_unless(in_array($frontend, [
            'https://fermatmind.com', 'https://www.fermatmind.com', 'https://staging.fermatmind.com',
        ], true), 503, 'blog_preview_frontend_unavailable');

        try {
            $binding = $workspace->surfacePreviewBinding($record);
        } catch (RuntimeException) {
            abort(409, 'blog_preview_binding_unavailable');
        }
        $projection = $blog->preview($record, static fn (Article $article): array => [
            'id' => (int) $article->id, 'slug' => (string) $article->slug,
            'locale' => (string) $article->locale, 'published_revision_id' => (int) $article->published_revision_id,
        ]);
        // The transport contains only display configuration and already-public IDs.
        // No article working revision, actor, credential or publication authority crosses it.
        $payload = [
            'type' => 'fermatmind.blog-preview.v1',
            'surface_id' => (int) $record->id,
            'locale' => (string) $record->locale,
            'configuration_sha256' => CanonicalTranslationPayloadHash::hash([
                'id' => (int) $record->id, 'locale' => $record->locale,
                'title' => $record->title, 'description' => $record->description,
                'payload_json' => $record->payload_json,
            ]),
            'binding' => $binding,
            'blog' => array_replace($projection, ['is_indexable' => false]),
        ];

        return response()->view('ops.blog-surface-preview', [
            'record' => $record, 'payload' => $payload,
            'previewUrl' => $frontend.'/'.($record->locale === 'en' ? 'en' : 'zh').'/cms-preview/articles',
        ], 200, [
            'X-Robots-Tag' => 'noindex, noarchive, nosnippet',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
