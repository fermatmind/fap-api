<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleEditorialCompletenessGate;
use App\Services\Ops\SeoContentScopeViewModel;
use Throwable;

/** @review-surface article */
final class ArticleContentWorkspace
{
    public function read(int $articleId = 0, string $locale = 'all'): array
    {
        if (! ContentAccess::canRead()) {
            return ['state' => 'permission_denied', 'articles' => [], 'selected' => null];
        }

        try {
            $query = app(SeoContentScopeViewModel::class)->articles($locale);
            $articles = (clone $query)->latest('updated_at')->orderByDesc('id')->limit(25)
                ->get(['id', 'title', 'locale']);
            $record = (clone $query)->with(['workingRevision', 'publishedRevision', 'seoMeta', 'sourceCanonical.workingRevision'])
                ->find($articleId > 0 ? $articleId : $articles->first()?->id);
            if ($record && ! $articles->contains('id', $record->id)) {
                $articles->push($record);
            }

            return [
                'state' => $record ? 'available' : 'empty',
                'articles' => $articles->map(fn (Article $article): array => [
                    'id' => (int) $article->id, 'title' => (string) $article->title, 'locale' => (string) $article->locale,
                ])->all(),
                'selected' => $record ? $this->project($record) : null,
            ];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'articles' => [], 'selected' => null];
        }
    }

    private function project(Article $article): array
    {
        $revision = $article->workingRevision;
        if ($revision && ((int) $revision->article_id !== (int) $article->id || (int) $revision->org_id !== 0
            || $revision->locale !== $article->locale)) {
            return ['id' => (int) $article->id, 'state' => 'revision_mismatch'];
        }
        $published = $article->publishedRevision;
        $validPublished = $published && (int) $published->article_id === (int) $article->id
            && (int) $published->org_id === 0 && $published->locale === $article->locale;
        $isPublic = Article::query()->withoutGlobalScopes()->where('org_id', 0)->whereKey($article->id)->publiclyReadable()->exists();
        $changed = [];
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $field) {
            if ($revision && (! $validPublished || $revision->{$field} !== $published->{$field})) {
                $changed[] = $field;
            }
        }
        $body = (string) ($revision?->content_md ?? '');
        $fields = $revision ? $revision->only(['title', 'excerpt', 'content_md', 'seo_title', 'seo_description']) : [];
        $completeness = app(ArticleEditorialCompletenessGate::class)->inspect((string) $article->locale, $body, $fields);
        $metadata = $revision && filled($revision->title) && filled($revision->excerpt) && filled($body)
            && filled($revision->seo_title) && filled($revision->seo_description);
        $source = $article->sourceCanonical;
        $sourceHash = $source?->workingRevision?->source_version_hash ?: $source?->source_version_hash;
        $sourceCurrent = $revision && ($article->isSourceArticle()
            || (filled($sourceHash) && filled($revision->translated_from_version_hash)
                && hash_equals((string) $sourceHash, (string) $revision->translated_from_version_hash)));

        return [
            'id' => (int) $article->id, 'state' => 'available', 'title' => (string) ($revision?->title ?? $article->title),
            'locale' => (string) $article->locale, 'slug' => (string) $article->slug,
            'working_revision_id' => $revision?->id, 'preview_fingerprint' => $revision ? ArticleWorkspace::previewFingerprint($revision) : null, 'published_revision_id' => $validPublished ? $published->id : null,
            'revision_status' => $revision?->revision_status, 'saved_at' => $revision?->updated_at?->toIso8601String(),
            'changed_fields' => $changed,
            'edit_url' => ArticleResource::canEdit($article) ? ArticleResource::getUrl('edit', ['record' => $article->id], panel: 'ops') : null,
            'preview_url' => $revision ? ArticleWorkspace::previewUrl($article, (int) $revision->id) : null,
            'public_url' => $isPublic ? ArticleWorkspace::publicUrl($article->slug, $article->locale) : null,
            'checks' => [
                'metadata' => (bool) $metadata,
                'body_headings' => $revision && app(ArticleBodyHeadingGuard::class)->violations($body) === [],
                'editorial_completeness' => $revision && $completeness['ok'],
                'revision_review' => $revision && ($revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED
                    || ($revision->revision_status === ArticleTranslationRevision::STATUS_APPROVED
                        && $revision->reviewed_by > 0 && $revision->reviewed_at && $revision->approved_at)),
                'source_current' => (bool) $sourceCurrent,
            ],
            'publication_state' => $isPublic ? 'published' : 'not_public',
            'has_unpublished_changes' => $revision && $changed !== [],
        ];
    }
}
