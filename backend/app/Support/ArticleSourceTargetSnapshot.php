<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ArticleSourceTargetSnapshot
{
    /** Selected article state, shared by the editor and native transaction guards. */
    public static function publicationHash(Article $selected, bool $lock = false): string
    {
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->working_revision_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $seo = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $article->id)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $import = ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', $article->id)->latest('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return CanonicalTranslationPayloadHash::hash([
            'article' => $article->getAttributes(),
            'revision' => $revision?->getAttributes(),
            'seo' => $seo->map(fn ($row) => $row->getAttributes())->all(),
            'import' => $import?->getAttributes(),
            'tags' => self::tagsHash($article, $lock),
        ]);
    }

    public static function assertPublicationHash(Article $article, string $expected, bool $lock = false): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || ! hash_equals($expected, self::publicationHash($article, $lock))) {
            throw new RuntimeException('article_publication_state_changed');
        }
    }

    public static function tagsHash(Article $target, bool $lock = false): string
    {
        $query = DB::table('article_tag_map')->where('article_id', $target->id)->orderBy('org_id')->orderBy('tag_id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(function ($row): array {
            $attributes = (array) $row;
            ksort($attributes);

            return $attributes;
        })->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<int> $excludedIds
     * @return list<array{revision_id:int,sha256:string}>
     */
    public static function sourceRevisions(Article $source, array $excludedIds = [], bool $lock = false): array
    {
        $query = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $source->id)
            ->whereNotIn('id', $excludedIds)->orderBy('id');
        $revisions = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($revisions->contains(fn ($revision): bool => (int) $revision->org_id !== 0)) {
            throw new RuntimeException('source_revision_tenant_mismatch');
        }

        return $revisions->map(fn ($revision): array => [
            'revision_id' => (int) $revision->id,
            'sha256' => hash('sha256', json_encode(self::attributes($revision->getAttributes()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ])->all();
    }

    /** @return list<array{article_id:int,sha256:string}> */
    public static function capture(Article $source, bool $lock = false): array
    {
        if (! in_array($source->locale, ['en', 'zh-CN'], true)) {
            throw new RuntimeException('source_locale_unsupported');
        }
        $targetLocale = $source->locale === 'en' ? 'zh-CN' : 'en';
        $collision = $targetLocale === 'en' ? 'english_identity_collision' : 'target_identity_collision';
        $query = Article::query()->withoutGlobalScopes()->withTrashed()->where('org_id', 0)
            ->where('locale', $targetLocale)->where(function ($query) use ($source): void {
                $query->where('source_article_id', $source->id)
                    ->orWhere('translation_group_id', $source->translation_group_id)
                    ->orWhere('slug', $source->slug);
            })->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $targets = $query->get();
        if ($targets->count() > 1) {
            throw new RuntimeException($collision);
        }
        $out = [];
        foreach ($targets as $target) {
            if ($target->trashed() || (int) $target->source_article_id !== (int) $source->id
                || ! in_array((int) $target->translated_from_article_id, [0, (int) $source->id], true)
                || (string) $target->source_locale !== (string) $source->locale
                || (string) $target->translation_group_id !== (string) $source->translation_group_id
                || (string) $target->translation_status === Article::TRANSLATION_STATUS_SOURCE) {
                throw new RuntimeException($collision);
            }
            $revisions = ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('article_id', $target->id)->orderBy('id');
            $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $target->id)->orderBy('id');
            if ($lock) {
                $revisions->lockForUpdate();
                $seo->lockForUpdate();
            }
            $raw = ['article' => self::attributes($target->getAttributes()),
                'revisions' => $revisions->get()->map(fn ($row): array => self::attributes($row->getAttributes()))->all(),
                'seo' => $seo->get()->map(fn ($row): array => self::attributes($row->getAttributes()))->all()];
            $out[] = ['article_id' => (int) $target->id,
                'sha256' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
        }

        return $out;
    }

    /** @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private static function attributes(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }
}
