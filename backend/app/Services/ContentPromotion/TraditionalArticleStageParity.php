<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class TraditionalArticleStageParity
{
    public const FILE = 'traditional-article-stage-parity.json';

    public const SCHEMA = 'fermatmind.traditional_article_stage_parity.v1';

    private const SLUGS = ['internal-transfer-try-one-task', 'eciyuan-character-mbti', 'personality-test-norms-percentiles-and-unknowns'];

    public static function isReviewedProductionMember(string $slug): bool
    {
        return in_array($slug, self::SLUGS, true);
    }

    public static function readPackage(string $bytes): array
    {
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? null;
        if (($package['schema'] ?? null) !== self::SCHEMA || ! is_array($rows) || count($rows) !== 9) {
            throw new DomainException('public_content_53_traditional_stage_parity_package');
        }
        $result = [];
        $roots = [];
        foreach ($rows as $row) {
            $identity = $row['identity'];
            $slug = $identity['slug'];
            $locale = $identity['locale'];
            $proof = $row['reviewed_production'];
            $id = 'TRADITIONAL-STAGE-PARITY-'.$slug.'-'.$locale;
            if (! in_array($slug, self::SLUGS, true) || ! in_array($locale, ['zh-CN', 'en', 'zh-TW'], true)
                || $identity['org_id'] !== 0 || isset($result[$id]) || ! is_string($row['group'])
                || ! is_int($proof['id']) || ! is_int($proof['published_revision'])
                || ($row['article']['content_html'] ?? '') !== '' || ! is_string($row['article']['title'])
                || ! hash_equals($proof['body_sha256'], hash('sha256', $row['article']['content_md']))) {
                throw new DomainException('public_content_53_traditional_stage_parity_identity');
            }
            if ($locale === 'zh-CN') {
                if ($proof['source_article_id'] !== null) {
                    throw new DomainException('public_content_53_traditional_stage_parity_source');
                }
                $roots[$slug] = [$proof['id'], $row['group']];
            } elseif (($roots[$slug] ?? null) !== [$proof['source_article_id'], $row['group']]) {
                throw new DomainException('public_content_53_traditional_stage_parity_source');
            }
            $result[$id] = ['page_id' => $id, 'kind' => 'article', 'identity' => $identity, 'traditional_stage_parity' => $row];
        }

        return array_values($result);
    }

    public function read(array $row, bool $lock): array
    {
        $query = Article::query()->withoutGlobalScopes()->withTrashed()->where($row['identity']);
        $article = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $article) {
            return ['table' => 'articles', 'missing' => true, 'article' => null, 'seo' => null, 'revision' => null];
        }
        if ($article->trashed()) {
            throw new DomainException('public_content_53_traditional_stage_parity_trashed');
        }
        $revision = $lock ? $article->publishedRevision()->lockForUpdate()->first() : $article->publishedRevision;
        $seo = $lock ? $article->seoMeta()->lockForUpdate()->first() : $article->seoMeta;

        $retained = $article->status === 'draft' && ! $article->is_public && $article->published_revision_id === null
            && $article->workingRevision?->authority_asset_key === $row['page_id']
            && $article->workingRevision?->authority_source_hash === $row['traditional_stage_parity']['reviewed_production']['source_version_hash_candidate'];

        return ['table' => 'articles', 'missing' => false, 'retained_draft' => $retained, 'article' => $article->getRawOriginal(), 'seo' => $seo?->getRawOriginal(),
            'revision' => $revision?->getRawOriginal()];
    }

    public function assertBaseline(array $row, array $state): void
    {
        if (PublicContent53Package::environment() === 'production') {
            $this->assertCopy($row, $state, true);

            return;
        }
        $expected = $row['traditional_stage_parity']['stage_before'];
        if ($expected === null && ($state['missing'] || ($state['retained_draft'] ?? false))) {
            return;
        }
        $article = $state['article'];
        $revision = $state['revision'];
        if ($expected === null || $article === null || $revision === null
            || (int) $article['id'] !== $expected['id']
            || (int) $article['published_revision_id'] !== $expected['published_revision']
            || (int) $article['working_revision_id'] !== $expected['published_revision']
            || $article['translation_group_id'] !== $row['traditional_stage_parity']['group']
            || ($state['seo']['robots'] ?? null) !== $expected['seo_robots']
            || ! hash_equals($expected['body_sha256'], hash('sha256', (string) $revision['content_md']))) {
            throw new DomainException('public_content_53_traditional_stage_parity_prestate');
        }
    }

    public function assertCopy(array $row, array $state, bool $production): void
    {
        $copy = $row['traditional_stage_parity'];
        $proof = $copy['reviewed_production'];
        $article = $state['article'];
        $revision = $state['revision'];
        if (! $article || ! $revision || $article['status'] !== 'published' || ! $article['is_public']
            || $article['translation_group_id'] !== $copy['group']
            || $revision['title'] !== $copy['article']['title']
            || ! hash_equals($proof['body_sha256'], hash('sha256', (string) $revision['content_md']))
            || ($production && ((int) $article['id'] !== $proof['id'] || (int) $revision['id'] !== $proof['published_revision']
                || $article['source_version_hash'] !== $proof['source_version_hash_candidate']))) {
            throw new DomainException('public_content_53_traditional_stage_parity_readback');
        }
    }

    public function apply(array $row, array $before, PromotionContext $context): void
    {
        if (PublicContent53Package::environment() !== 'staging' || DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_traditional_stage_parity_write_containment');
        }
        $copy = $row['traditional_stage_parity'];
        $root = $row['identity']['locale'] === 'zh-CN' ? null
            : Article::query()->withoutGlobalScopes()->where([
                'org_id' => 0, 'locale' => 'zh-CN', 'slug' => $row['identity']['slug'],
                'translation_group_id' => $copy['group'],
            ])->lockForUpdate()->sole();
        $model = $before['missing'] ? new Article : Article::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($before['article']['id']);
        $model->forceFill($row['identity'] + $copy['article'] + [
            'translation_group_id' => $copy['group'], 'source_locale' => 'zh-CN',
            'source_article_id' => $root?->id, 'translated_from_article_id' => $root?->id,
            'translated_from_version_hash' => $root?->source_version_hash,
            'translation_status' => $root ? Article::TRANSLATION_STATUS_PUBLISHED : Article::TRANSLATION_STATUS_SOURCE,
            'status' => 'published', 'lifecycle_state' => Article::LIFECYCLE_ACTIVE, 'is_public' => true,
        ]);
        if ($before['missing']) {
            $model->forceFill(['is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false]);
        }
        $model->save();
        $source = $root ?? $model;
        $retained = ($before['retained_draft'] ?? false) ? $model->workingRevision : null;
        $revision = $retained?->authority_package_sha256 === $context->packageSha256 ? $retained
            : ArticleTranslationRevision::query()->withoutGlobalScopes()->create(Arr::only($copy['article'], ['title', 'excerpt', 'content_md']) + [
                'org_id' => 0, 'article_id' => $model->id, 'locale' => $model->locale,
                'source_article_id' => $source->id, 'source_locale' => 'zh-CN', 'translation_group_id' => $copy['group'],
                'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash,
                'seo_title' => $copy['seo']['seo_title'], 'seo_description' => $copy['seo']['seo_description'],
                'revision_number' => ((int) $model->translationRevisions()->max('revision_number')) + 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => now(),
                'authority_source_package' => PublicContent53Package::PACKAGE, 'authority_asset_key' => $row['page_id'],
                'authority_package_sha256' => $context->packageSha256, 'authority_source_hash' => $copy['reviewed_production']['source_version_hash_candidate'],
                'authority_metadata_json' => ['approval_kind' => 'verified_automated_exact_package', 'staging_mirror' => true],
            ]);
        if ((int) $revision->source_article_id !== (int) $source->id
            || ! hash_equals($copy['reviewed_production']['body_sha256'], hash('sha256', (string) $revision->content_md))) {
            throw new DomainException('public_content_53_traditional_stage_parity_retained_revision');
        }
        $model->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $revision->id])->saveQuietly();
        $fields = Arr::except($copy['seo'], ['robots']);
        if ($before['missing']) {
            $fields += ['robots' => 'noindex,follow', 'is_indexable' => false];
        }
        $model->seoMeta()->updateOrCreate(['org_id' => 0, 'locale' => $model->locale], $fields);
    }

    public function restore(array $before, array $after): void
    {
        if (PublicContent53Package::environment() !== 'staging' || DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_traditional_stage_parity_write_containment');
        }
        if ($before['missing']) {
            DB::table('articles')->where('id', $after['article']['id'])->update([
                'status' => 'draft', 'is_public' => false, 'published_revision_id' => null,
            ]);

            return;
        }
        DB::table('articles')->where('id', $before['article']['id'])->update($before['article']);
        if ($before['seo']) {
            DB::table('article_seo_meta')->where('id', $before['seo']['id'])->update($before['seo']);
        }
    }
}
