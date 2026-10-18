<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\EditorialReview;
use App\Models\MediaAsset;
use App\Services\Cms\EditorialPackage\EditorialPackageDraftImporter;
use App\Services\Cms\SeoImageBundle\SeoImageBundleImporter;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact reviewed bilingual addition through the existing native CMS commands.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class CareerInterestsValuesArticleRelease
{
    public const ROOT = 'content_assets/articles/20261018-interests-values';

    public const SHA256 = '27466fff4c3597a549a57437cf4937bf1d23ba7da9e506d3b040e6e337846ad0';

    public const SLUGS = ['zh-CN' => 'career-interests-vs-work-values', 'en' => 'why-you-like-the-work-but-dont-want-the-job'];

    public function run(bool $dryRun = false): array
    {
        $root = base_path(self::ROOT);
        $bytes = file_get_contents($root.'/manifest.json');
        if (! is_string($bytes) || ! hash_equals(self::SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('Interests-values package digest drift.');
        }
        $manifest = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if ($manifest['schema'] !== 'fermatmind.interests_values_article_pair.v1'
            || array_keys($manifest['files']) !== ['en.json', 'media/cover.png', 'zh-CN.json']) {
            throw new RuntimeException('Interests-values package scope drift.');
        }
        foreach ($manifest['files'] as $file => $digest) {
            if (! hash_equals($digest, hash_file('sha256', $root.'/'.$file))) {
                throw new RuntimeException('Interests-values source bytes drift.');
            }
        }
        $candidates = [];
        foreach (self::SLUGS as $locale => $slug) {
            $candidate = json_decode(file_get_contents($root.'/'.$locale.'.json'), true, 64, JSON_THROW_ON_ERROR);
            if ($candidate['slug'] !== $slug || $candidate['locale'] !== $locale
                || $candidate['translation_group_id'] !== $manifest['translation_group_id']
                || ! hash_equals($manifest['body_sha256'][$locale], hash('sha256', $candidate['body_markdown']))) {
                throw new RuntimeException('Interests-values candidate identity drift.');
            }
            $candidates[$locale] = $candidate;
        }
        $owner = (int) config('review_governance.solo_owner_admin_user_id');
        $reviews = app(CmsEditorialReviewAttestationService::class);
        if (! $reviews->isConfiguredSoloOwner($owner)) {
            throw new RuntimeException('Interests-values native review policy unavailable.');
        }
        $revalidator = app(PublicContent53FrontendRevalidator::class);
        $revalidator->assertConfigured();
        $mediaOptions = ['source_path' => $root.'/media/cover.png', 'translation_group_id' => $manifest['translation_group_id'],
            'asset_key' => 'article.career-interests-values.cover.v1', 'allow_update_existing' => false,
            'alt_text' => array_map(static fn (array $row): string => $row['cover_image_alt'], $candidates),
            'require_media_runtime' => true];

        $result = DB::transaction(function () use ($dryRun, $root, $manifest, $candidates, $owner, $reviews, $mediaOptions): array {
            $existing = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)
                ->where(function ($query) use ($manifest): void {
                    $query->whereIn('slug', array_values(self::SLUGS))->orWhere('translation_group_id', $manifest['translation_group_id']);
                })->orderBy('id')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $this->assertPublished($candidates, $existing);

                return ['status' => 'unchanged', 'changed' => 0];
            }
            $importer = app(EditorialPackageDraftImporter::class);
            foreach (self::SLUGS as $locale => $slug) {
                $plan = $importer->planFromFile($root.'/'.$locale.'.json', $locale, false);
                if (($plan['ok'] ?? false) !== true || $plan['action'] !== 'will_create') {
                    throw new RuntimeException('Interests-values draft preflight failed: '.json_encode($plan['errors'] ?? []));
                }
            }
            $images = app(SeoImageBundleImporter::class);
            $mediaPlan = $images->planCoverAsset($mediaOptions);
            if (($mediaPlan['ok'] ?? false) !== true) {
                throw new RuntimeException('Interests-values media preflight failed: '.json_encode($mediaPlan['errors'] ?? []));
            }
            if ($dryRun) {
                return ['status' => 'ready_for_media_and_draft_import', 'changed' => 0];
            }
            $media = $images->importCoverAsset($mediaOptions);
            if (($media['ok'] ?? false) !== true) {
                throw new RuntimeException('Interests-values verified media unavailable.');
            }
            $asset = MediaAsset::withoutGlobalScopes()->where('asset_key', $mediaOptions['asset_key'])->firstOrFail();
            $asset->forceFill(['credit' => 'AI-generated editorial illustration / FermatMind',
                'payload_json' => array_replace_recursive($asset->payload_json, ['seo_image_bundle_v1' => ['provenance' => [
                    'source' => 'built_in_image_gen', 'competitor_asset' => false,
                    'source_sha256' => $manifest['files']['media/cover.png'],
                ]]])])->save();
            $resolved = $media['resolved_metadata'];
            foreach ($candidates as $locale => $candidate) {
                $candidate['cover_image'] = $resolved['cover_image_url'];
                $temporary = tempnam(storage_path('app'), 'interests-values-');
                if ($temporary === false) {
                    throw new RuntimeException('Interests-values temporary import unavailable.');
                }
                try {
                    file_put_contents($temporary, json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    $import = $importer->importFromFile($temporary, $locale, false);
                } finally {
                    unlink($temporary);
                }
                if (($import['ok'] ?? false) !== true || ($import['imported'] ?? false) !== true) {
                    throw new RuntimeException('Interests-values native draft import failed.');
                }
                $article = Article::withoutGlobalScopes()->findOrFail($import['article_id']);
                $article->forceFill(['cover_image_width' => 1600, 'cover_image_height' => 900,
                    'cover_image_variants' => array_merge($article->cover_image_variants, $resolved['cover_image_variants'])])->save();
                ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $article->id)
                    ->update(['og_image_url' => $resolved['og_image_url']]);
                $revision = $article->workingRevision;
                $revision->forceFill(['authority_source_package' => self::ROOT, 'authority_package_sha256' => self::SHA256,
                    'authority_source_hash' => $manifest['body_sha256'][$locale],
                    'authority_metadata_json' => ['delivery' => 'automatic_trunk_migration', 'review_record_sha256' => $manifest['review']['record_sha256']]])->save();
                app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision($article->fresh(), $owner);
                EditorialReview::withoutGlobalScopes()->create(['org_id' => 0, 'content_type' => 'article', 'content_id' => $article->id,
                    'workflow_state' => 'approved', 'reviewed_by_admin_user_id' => $owner, 'reviewed_at' => now(),
                    'last_transition_at' => now(), 'note' => 'Reviewed bilingual exact package: '.self::SHA256]);
            }
            $ids = Article::withoutGlobalScopes()->where('org_id', 0)->where('translation_group_id', $manifest['translation_group_id'])
                ->orderBy('id')->pluck('id')->all();
            if (count($ids) !== 2) {
                throw new RuntimeException('Interests-values draft pair count drift.');
            }
            $options = ['--article' => $ids, '--make-indexable' => true, '--json' => true];
            if (Artisan::call('articles:publish-controlled', $options + ['--dry-run' => true]) !== 0) {
                throw new RuntimeException('Interests-values native publication preflight failed: '.Artisan::output());
            }
            $plan = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            if (Artisan::call('articles:publish-controlled', $options + ['--confirm' => $plan['expected_confirmation']]) !== 0) {
                throw new RuntimeException('Interests-values native publication failed: '.Artisan::output());
            }
            foreach ($ids as $id) {
                $article = Article::withoutGlobalScopes()->with(['publishedRevision'])->findOrFail($id);
                $article->forceFill(['sitemap_eligible' => true, 'llms_eligible' => true])->save();
                $review = EditorialReview::withoutGlobalScopes()->where('content_type', 'article')->where('content_id', $id)->firstOrFail();
                $reviews->bindOrCreateApproved(null, 'cms_article_review', 'interests-values:'.$id.':revision:'.$article->published_revision_id,
                    [['surface_id' => 'article', 'record' => $article], ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                        ['surface_id' => 'editorial_review', 'record' => $review]], $owner, self::SHA256);
            }
            $this->assertPublished($candidates, Article::withoutGlobalScopes()->whereIn('id', $ids)->get());

            return ['status' => 'published', 'changed' => 2];
        });
        if (! $dryRun) {
            $revalidator->revalidate(array_map(static fn (string $locale, string $slug): array => [
                'kind' => 'article', 'identity' => ['org_id' => 0, 'locale' => $locale, 'slug' => $slug],
            ], array_keys(self::SLUGS), array_values(self::SLUGS)));
            app(\App\Services\SEO\SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
        }

        return $result;
    }

    private function assertPublished(array $candidates, $articles): void
    {
        if ($articles->count() !== 2) {
            throw new RuntimeException('Interests-values existing identity collision.');
        }
        foreach ($articles as $article) {
            $candidate = $candidates[$article->locale] ?? null;
            $article->load(['publishedRevision', 'seoMeta']);
            if (! $candidate || $article->trashed() || $article->slug !== $candidate['slug']
                || $article->translation_group_id !== $candidate['translation_group_id']
                || $article->status !== 'published' || ! $article->is_public || ! $article->is_indexable
                || ! $article->sitemap_eligible || ! $article->llms_eligible
                || $article->working_revision_id !== $article->published_revision_id
                || $article->title !== $candidate['title'] || $article->excerpt !== $candidate['excerpt']
                || $article->content_md !== rtrim($candidate['body_markdown'])
                || $article->publishedRevision?->content_md !== rtrim($candidate['body_markdown'])
                || $article->publishedRevision?->authority_package_sha256 !== self::SHA256
                || $article->publishedRevision?->seo_title !== $candidate['seo_title']
                || $article->seoMeta?->canonical_url !== $candidate['canonical']) {
                throw new RuntimeException('Interests-values pair readback or collision failed.');
            }
        }
    }
}
