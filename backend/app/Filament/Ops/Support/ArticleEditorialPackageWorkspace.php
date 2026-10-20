<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Cms\EditorialPackage\EditorialPackageDraftImporter;
use App\Support\CanonicalTranslationPayloadHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/** @review-surface article */
final class ArticleEditorialPackageWorkspace
{
    public const MAX_INPUT_BYTES = 1000000;

    public function __construct(private readonly EditorialPackageDraftImporter $importer) {}

    /** A read-only snapshot for preparing the selected draft's transport. */
    public function baseline(Article $selected, bool $lock = false): array
    {
        abort_unless(ContentAccess::canWrite(), 403);
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $this->require(self::isPrivateDraft($article), 'private_draft_required');
        $revision = ArticleTranslationRevision::withoutGlobalScopes()
            ->whereKey($article->working_revision_id)->where('article_id', $article->id)
            ->where('org_id', 0)->where('locale', $article->locale)
            ->where('translation_group_id', $article->translation_group_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->require($revision instanceof ArticleTranslationRevision, 'working_revision_required');
        $seo = $this->seo($article, $lock);
        $importId = ArticleEditorialPackageImport::withoutGlobalScopes()
            ->where('org_id', 0)->where('article_id', $article->id)
            ->where('locale', $article->locale)->max('id');

        return [
            'article_id' => (int) $article->id,
            'org_id' => 0,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => null,
            'revision_fingerprint' => ArticleWorkspace::previewFingerprint($revision),
            'last_import_id' => $importId === null ? null : (int) $importId,
            'article_hash' => CanonicalTranslationPayloadHash::hash($article->attributesToArray()),
            'seo_hash' => CanonicalTranslationPayloadHash::hash($seo?->attributesToArray()),
        ];
    }

    public static function isPrivateDraft(Article $article): bool
    {
        return (int) $article->org_id === 0 && (string) $article->status === 'draft'
            && ! $article->is_public && $article->published_revision_id === null
            && $article->deleted_at === null;
    }

    /** Run the existing native importer for one already-selected private draft. */
    public function import(Article $selected, string $input): array
    {
        abort_unless(ContentAccess::canWrite(), 403);
        $this->require(strlen($input) <= self::MAX_INPUT_BYTES, 'input_too_large');
        $envelope = $this->json($input);
        $this->require(($envelope['format'] ?? null) === 'article.editorial-draft.transport.v1', 'invalid_format');
        $this->require(is_string($envelope['package_base64'] ?? null), 'invalid_package_encoding');
        $bytes = base64_decode($envelope['package_base64'], true);
        $this->require(is_string($bytes) && strlen($bytes) <= 750000, 'invalid_package_encoding');
        $digest = $envelope['package_sha256'] ?? null;
        $this->require(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
            && hash_equals($digest, hash('sha256', $bytes)), 'package_digest_mismatch');
        $package = $this->json($bytes);
        $this->require(($package['package_version'] ?? null) === 'editorial_package.v1', 'native_package_required');

        $root = storage_path('app/private/article-editorial-inputs/'.Str::uuid());
        try {
            File::makeDirectory($root, 0700, true);
            $file = $root.'/package.json';
            $this->require(file_put_contents($file, $bytes) === strlen($bytes) && chmod($file, 0600), 'temporary_file_write_failed');

            return DB::transaction(function () use ($selected, $envelope, $package, $file): array {
                $baseline = $this->baseline($selected, true);
                $this->require(CanonicalTranslationPayloadHash::sameValue($baseline, $envelope['target'] ?? null), 'draft_baseline_mismatch');
                $article = Article::withoutGlobalScopes()->findOrFail($baseline['article_id']);
                foreach (['slug', 'locale', 'translation_group_id'] as $key) {
                    $this->require(($package[$key] ?? null) === $baseline[$key], 'package_identity_mismatch:'.$key);
                }
                $this->assertMedia($article, $package, $envelope['media_asset_id'] ?? null);
                $plan = $this->importer->planFromFile($file);
                $this->assertPlan($plan, (int) $article->id);
                $result = $this->importer->importFromFile($file);
                $this->assertPlan($result, (int) $article->id);
                $this->require(($result['imported'] ?? false) === true && ($result['article_id'] ?? null) === (int) $article->id, 'native_import_failed');

                return array_intersect_key($result, array_flip(['article_id', 'working_revision_id', 'working_revision_status']));
            });
        } catch (RuntimeException) {
            $this->require(false, 'native_import_failed');
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function assertMedia(Article $article, array $package, mixed $assetId): void
    {
        $this->require(is_int($assetId) && $assetId > 0, 'media_asset_required');
        $asset = MediaAsset::withoutGlobalScopes()->where('org_id', 0)->whereKey($assetId)->lockForUpdate()->first();
        $this->require($asset instanceof MediaAsset && $asset->status === MediaAsset::STATUS_PUBLISHED
            && $asset->is_public && $asset->sync_status === MediaAsset::SYNC_SYNCED
            && $asset->cdn_status === MediaAsset::CDN_VERIFIED, 'published_media_required');
        $variants = MediaVariant::where('media_asset_id', $assetId)
            ->whereIn('variant_key', ['hero', 'card', 'og', 'thumbnail', 'preload'])
            ->orderBy('variant_key')->lockForUpdate()->get();
        $this->require($variants->count() === 5 && $variants->pluck('variant_key')->unique()->count() === 5, 'media_variants_required');
        $asset->setRelation('variants', $variants);
        // Use the selector's canonical URLs, MIME/disk readiness, and original
        // image dimensions rather than inventing a second media projection.
        $payload = ArticleResource::articleCoverPayload($asset);
        $this->require($payload['cover_image_url'] !== null, 'media_not_ready');
        foreach ($variants as $variant) {
            $this->require($variant->sync_status === MediaAsset::SYNC_SYNCED && $variant->verified_at !== null, 'verified_media_required');
            $current = data_get($article->cover_image_variants, $variant->variant_key);
            $this->require(is_array($current) && CanonicalTranslationPayloadHash::sameValue(
                array_intersect_key($current, array_flip(['url', 'width', 'height', 'mime_type'])),
                $payload['cover_image_variants'][$variant->variant_key] ?? null,
            ), 'draft_media_mismatch');
        }
        $this->require(($package['cover_image'] ?? null) === $payload['cover_image_url']
            && $article->cover_image_url === $payload['cover_image_url']
            && $article->cover_image_width === $payload['cover_image_width']
            && $article->cover_image_height === $payload['cover_image_height']
            && $this->seo($article, true)?->og_image_url === $payload['cover_image_variants']['og']['url'], 'draft_media_mismatch');
    }

    private function seo(Article $article, bool $lock): ?ArticleSeoMeta
    {
        return ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('locale', $article->locale)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function assertPlan(array $plan, int $articleId): void
    {
        $this->require(($plan['ok'] ?? false) === true && ($plan['action'] ?? null) === 'will_update'
            && ($plan['existing_article_id'] ?? null) === $articleId, 'native_validation_failed');
    }

    private function json(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->require(false, 'invalid_json');
        }
        $this->require(is_array($value) && ! array_is_list($value), 'json_object_required');

        return $value;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['package_json' => __('ops.resources.articles.editorial_import.rejected', ['code' => $code])]);
        }
    }
}
