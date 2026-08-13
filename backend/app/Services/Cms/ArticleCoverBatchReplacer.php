<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\SeoImageBundle\SeoImageBundleImporter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ArticleCoverBatchReplacer
{
    public const SCHEMA_VERSION = 'article-cover-replacement.v1';

    public function __construct(
        private readonly SeoImageBundleImporter $imageImporter,
        private readonly ArticleImageMetadataUpdater $metadataUpdater,
        private readonly ArticleSeoService $articleSeoService,
        private readonly ArticleCoverBatchVerifier $verifier,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function run(array $options): array
    {
        $startedAt = now()->toIso8601String();
        $runId = (string) Str::uuid();
        $execute = (bool) ($options['execute'] ?? false);
        $manifestPath = $this->absoluteManifestPath((string) ($options['manifest'] ?? ''));
        $manifestRaw = $manifestPath !== null ? (string) file_get_contents($manifestPath) : '';
        $manifestSha = $manifestRaw !== '' ? hash('sha256', $manifestRaw) : '';
        $manifest = $manifestRaw !== '' ? json_decode($manifestRaw, true) : null;
        $errors = [];

        if ($manifestPath === null) {
            $errors[] = $this->issue('manifest', 'manifest_unreadable', 'Manifest must be an absolute readable JSON file.');
        }
        if (! is_array($manifest) || array_is_list($manifest)) {
            $errors[] = $this->issue('manifest', 'manifest_json_invalid', 'Manifest must contain one JSON object.');
            $manifest = [];
        }
        if ($execute && (bool) ($options['dry_run'] ?? false)) {
            $errors[] = $this->issue('mode', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }

        $requiredPhrase = $manifestSha !== '' ? 'EXECUTE ARTICLE COVER BATCH '.$manifestSha : '';
        if ($execute) {
            foreach (['no_publish', 'no_schema', 'no_hreflang', 'no_search', 'no_sitemap_llms_change', 'no_revalidation'] as $hold) {
                if ((bool) ($options[$hold] ?? false) !== true) {
                    $errors[] = $this->issue('authorization.'.$hold, 'required_safety_hold_missing', 'All production safety holds are required in execute mode.');
                }
            }
            if (trim((string) ($options['actor'] ?? '')) === '' || trim((string) ($options['reason'] ?? '')) === '') {
                $errors[] = $this->issue('authorization', 'actor_reason_required', '--actor and --reason are required in execute mode.');
            }
            if (! hash_equals($manifestSha, trim((string) ($options['confirm_manifest_sha256'] ?? '')))) {
                $errors[] = $this->issue('authorization.confirm_manifest_sha256', 'manifest_sha256_confirmation_mismatch', 'Exact manifest SHA-256 confirmation is required.');
            }
            if (! hash_equals($requiredPhrase, (string) ($options['confirm_execution'] ?? ''))) {
                $errors[] = $this->issue('authorization.confirm_execution', 'execution_confirmation_mismatch', 'Exact execution confirmation phrase is required.');
            }
        }

        [$groups, $preflightErrors] = $this->preflightManifest($manifest, $options);
        $errors = [...$errors, ...$preflightErrors];
        $receipt = $this->baseReceipt($runId, $startedAt, $execute, $manifestPath, $manifestSha, $manifest, $options, $requiredPhrase);
        $receipt['groups'] = $groups;

        if ($errors !== []) {
            return $this->finish($receipt, 'failed', $errors, false, false);
        }
        if (! $execute) {
            return $this->finish($receipt, 'passed', [], false, false);
        }

        $writesCommitted = false;
        $verificationTargets = [];
        $baselineRecords = [];
        foreach ($groups as $index => $group) {
            try {
                foreach ((array) $group['articles'] as $articlePlan) {
                    if ((bool) ($articlePlan['baseline_required'] ?? false)) {
                        $baseline = $this->articleSeoService->generateSeoMeta((int) $articlePlan['article_id']);
                        foreach ($receipt['groups'][$index]['articles'] as $articleIndex => $receiptArticle) {
                            if ((int) ($receiptArticle['article_id'] ?? 0) === (int) $articlePlan['article_id']) {
                                $receipt['groups'][$index]['articles'][$articleIndex]['baseline'] = [
                                    'action' => 'ensured_seo_meta_baseline',
                                    'article_id' => (int) $articlePlan['article_id'],
                                    'canonical_url' => (string) $baseline->canonical_url,
                                    'robots' => (string) $baseline->robots,
                                    'is_indexable' => (bool) $baseline->is_indexable,
                                ];
                                $baselineRecords[] = $receipt['groups'][$index]['articles'][$articleIndex]['baseline'];
                                break;
                            }
                        }
                        $writesCommitted = true;
                    }
                }

                $media = $this->imageImporter->importCoverAsset([
                    'source_path' => $group['source_image'],
                    'translation_group_id' => $group['translation_group_id'],
                    'asset_key' => $group['asset_key'],
                    'alt_text' => $group['localized_alt'],
                    'allow_update_existing' => true,
                ]);
                $receipt['groups'][$index]['media_execute'] = $media;
                if (! (bool) ($media['ok'] ?? false)) {
                    throw new RuntimeException('Media Library import failed.');
                }
                $writesCommitted = true;

                foreach ((array) $group['articles'] as $articleIndex => $articlePlan) {
                    $metadata = (array) ($media['resolved_metadata'] ?? []);
                    $metadata['cover_image_alt'] = (string) $articlePlan['alt'];
                    data_set($metadata, 'social_image_metadata.alt_text', (string) $articlePlan['alt']);
                    $update = $this->metadataUpdater->run([
                        'article_ids' => (string) $articlePlan['article_id'],
                        'translation_group_id' => $group['translation_group_id'],
                        'resolved_metadata_payload' => $metadata,
                        'execute' => true,
                        'no_publish' => true,
                        'no_schema' => true,
                        'no_hreflang' => true,
                        'no_search' => true,
                        'no_sitemap_llms_change' => true,
                    ]);
                    $receipt['groups'][$index]['articles'][$articleIndex]['update'] = $update;
                    if (! (bool) ($update['ok'] ?? false)) {
                        throw new RuntimeException('Article image metadata update failed.');
                    }
                    $this->assertExpectedState((int) $articlePlan['article_id'], $articlePlan);
                    $verificationTargets[] = [
                        'article_id' => (int) $articlePlan['article_id'],
                        'slug' => (string) $group['slug'],
                        'locale' => (string) $articlePlan['locale'],
                        'cover_image_url' => (string) $metadata['cover_image_url'],
                        'og_image_url' => (string) $metadata['og_image_url'],
                        'cover_image_variants' => (array) $metadata['cover_image_variants'],
                    ];
                }
                $receipt['groups'][$index]['status'] = 'passed';
            } catch (Throwable $exception) {
                $receipt['groups'][$index]['status'] = 'failed';
                $receipt['groups'][$index]['errors'][] = $this->issue('execute', 'group_execution_failed', $exception->getMessage());

                $receipt['baseline_records'] = $baselineRecords;

                return $this->finish($receipt, 'partial', [[
                    'field' => 'groups.'.$index,
                    'code' => 'batch_execution_stopped',
                    'message' => $exception->getMessage(),
                ]], true, $writesCommitted);
            }
        }

        $receipt['baseline_records'] = $baselineRecords;
        $verification = $this->verifier->verify($verificationTargets, $options);
        $receipt['verification'] = $verification;
        if (! (bool) ($verification['ok'] ?? false)) {
            return $this->finish($receipt, 'partial', [$this->issue('verification', 'public_cache_not_converged', 'Writes completed, but bounded public cache verification did not converge.')], true, true);
        }

        return $this->finish($receipt, 'passed', [], true, true);
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $options @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} */
    private function preflightManifest(array $manifest, array $options): array
    {
        $errors = [];
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = $this->issue('schema_version', 'schema_version_invalid', 'schema_version must be '.self::SCHEMA_VERSION.'.');
        }
        if (trim((string) ($manifest['batch_id'] ?? '')) === '') {
            $errors[] = $this->issue('batch_id', 'batch_id_required', 'batch_id is required.');
        }
        $rows = $manifest['groups'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return [[], [...$errors, $this->issue('groups', 'groups_required', 'groups must be a non-empty array.')]];
        }

        $seenIds = [];
        $seenSlugs = [];
        $groups = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->issue('groups.'.$index, 'group_invalid', 'Each group must be an object.');

                continue;
            }
            $groupErrors = [];
            $slug = trim((string) ($row['slug'] ?? ''));
            $translationGroupId = trim((string) ($row['translation_group_id'] ?? ''));
            $assetKey = trim((string) ($row['asset_key'] ?? ''));
            $sourceImage = trim((string) ($row['source_image'] ?? ''));
            if ($slug === '' || in_array($slug, $seenSlugs, true)) {
                $groupErrors[] = $this->issue('slug', $slug === '' ? 'slug_required' : 'duplicate_slug', 'Slug must be present and unique.');
            }
            $seenSlugs[] = $slug;
            if ($translationGroupId === '') {
                $groupErrors[] = $this->issue('translation_group_id', 'translation_group_id_required', 'translation_group_id is required.');
            }
            if ($sourceImage === '' || ! str_starts_with($sourceImage, '/')) {
                $groupErrors[] = $this->issue('source_image', 'source_image_absolute_path_required', 'source_image must be an absolute path.');
            }

            $localizedAlt = [];
            $articlePlans = [];
            $localeRows = $row['locales'] ?? null;
            foreach (['zh-CN', 'en'] as $locale) {
                $localeRow = is_array($localeRows) ? ($localeRows[$locale] ?? null) : null;
                if (! is_array($localeRow)) {
                    $groupErrors[] = $this->issue('locales.'.$locale, 'locale_pair_missing', 'Both zh-CN and en locale rows are required.');

                    continue;
                }
                $articleId = (int) ($localeRow['article_id'] ?? 0);
                $alt = trim((string) ($localeRow['alt'] ?? ''));
                if ($articleId <= 0 || in_array($articleId, $seenIds, true)) {
                    $groupErrors[] = $this->issue('locales.'.$locale.'.article_id', $articleId <= 0 ? 'article_id_invalid' : 'duplicate_article_id', 'Article IDs must be positive and unique.');
                }
                $seenIds[] = $articleId;
                if ($alt === '' || mb_strlen($alt) > 255) {
                    $groupErrors[] = $this->issue('locales.'.$locale.'.alt', 'alt_invalid', 'Localized alt is required and must be <=255 characters.');
                }
                $localizedAlt[$locale] = $alt;
                $article = $articleId > 0 ? Article::query()->withoutGlobalScopes()->with('seoMeta')->find($articleId) : null;
                $plan = $this->preflightArticle($article, $locale, $slug, $translationGroupId, $localeRow, $row, $options, $groupErrors);
                if ($plan !== null) {
                    $articlePlans[] = $plan;
                }
            }

            $mediaPlan = $this->imageImporter->planCoverAsset([
                'source_path' => $sourceImage,
                'translation_group_id' => $translationGroupId,
                'asset_key' => $assetKey,
                'alt_text' => $localizedAlt,
                'allow_update_existing' => true,
                'require_media_runtime' => true,
            ]);
            foreach ((array) ($mediaPlan['errors'] ?? []) as $mediaError) {
                if (is_array($mediaError)) {
                    $groupErrors[] = $mediaError;
                }
            }
            if ($groupErrors !== []) {
                foreach ($groupErrors as $error) {
                    $errors[] = [...$error, 'group_index' => $index];
                }
            }
            $groups[] = [
                'translation_group_id' => $translationGroupId,
                'slug' => $slug,
                'source_image' => $sourceImage,
                'asset_key' => $assetKey,
                'localized_alt' => $localizedAlt,
                'media_plan' => $mediaPlan,
                'articles' => $articlePlans,
                'status' => $groupErrors === [] ? 'preflight_passed' : 'preflight_failed',
                'errors' => $groupErrors,
            ];
        }

        return [$groups, $errors];
    }

    /** @param array<string,mixed> $localeRow @param array<string,mixed> $groupRow @param array<string,mixed> $options @param list<array<string,mixed>> $errors @return array<string,mixed>|null */
    private function preflightArticle(?Article $article, string $locale, string $slug, string $translationGroupId, array $localeRow, array $groupRow, array $options, array &$errors): ?array
    {
        if (! $article instanceof Article) {
            $errors[] = $this->issue('article', 'article_not_found', 'Article was not found.');

            return null;
        }
        foreach (['locale' => $locale, 'slug' => $slug, 'translation_group_id' => $translationGroupId] as $field => $expected) {
            if ((string) $article->{$field} !== $expected) {
                $errors[] = $this->issue('article.'.$article->id.'.'.$field, $field.'_mismatch', 'Article '.$field.' does not match manifest lock.');
            }
        }
        if ((string) $article->status !== 'published' || ! (bool) $article->is_public || ! (int) $article->published_revision_id) {
            $errors[] = $this->issue('article.'.$article->id.'.status', 'article_not_published_public', 'Target article must be published, public, and revision-bound.');
        }

        $expected = [
            'is_indexable' => (bool) ($localeRow['is_indexable'] ?? false),
            'sitemap_eligible' => (bool) ($localeRow['sitemap_eligible'] ?? false),
            'llms_eligible' => (bool) ($localeRow['llms_eligible'] ?? false),
            'robots' => trim((string) ($localeRow['robots'] ?? ((bool) ($localeRow['is_indexable'] ?? false) ? 'index,follow' : 'noindex,nofollow'))),
            'canonical' => trim((string) ($localeRow['canonical'] ?? '')),
        ];
        foreach (['is_indexable', 'sitemap_eligible', 'llms_eligible'] as $requiredBoolean) {
            if (! array_key_exists($requiredBoolean, $localeRow) || ! is_bool($localeRow[$requiredBoolean])) {
                $errors[] = $this->issue('article.'.$article->id.'.'.$requiredBoolean, 'expected_boolean_required', 'Discoverability expectations must be explicit booleans.');
            }
        }
        foreach (['is_indexable', 'sitemap_eligible', 'llms_eligible'] as $field) {
            if ((bool) $article->{$field} !== $expected[$field]) {
                $errors[] = $this->issue('article.'.$article->id.'.'.$field, 'expected_state_mismatch', 'Article discoverability state does not match manifest.');
            }
        }

        $baselineRequired = ! $article->seoMeta instanceof ArticleSeoMeta;
        $manifestAllows = (bool) ($groupRow['allow_ensure_seo_meta_baseline'] ?? false);
        $executionAllows = (bool) ($options['allow_ensure_seo_meta_baseline'] ?? false);
        if ($baselineRequired && (! $manifestAllows || ! $executionAllows)) {
            $errors[] = $this->issue('article.'.$article->id.'.seo_meta', 'seo_meta_baseline_not_authorized', 'Missing SEO meta requires authorization in both manifest and command.');
        }
        if ($baselineRequired && $expected['canonical'] === '') {
            $errors[] = $this->issue('article.'.$article->id.'.canonical', 'canonical_required_for_baseline', 'Canonical is required when SEO meta baseline is missing.');
        }
        $generatedCanonical = $this->articleSeoService->buildCanonicalUrl((string) $article->slug, (string) $article->locale);
        if ($expected['canonical'] !== '' && ! hash_equals((string) $generatedCanonical, $expected['canonical'])) {
            $errors[] = $this->issue('article.'.$article->id.'.canonical', 'canonical_route_mismatch', 'Manifest canonical does not match the backend canonical builder.');
        }
        if ($article->seoMeta instanceof ArticleSeoMeta) {
            if ($expected['canonical'] !== '' && ! hash_equals($expected['canonical'], (string) $article->seoMeta->canonical_url)) {
                $errors[] = $this->issue('article.'.$article->id.'.canonical', 'canonical_mismatch', 'Existing canonical does not match manifest.');
            }
            if (! hash_equals($expected['robots'], (string) $article->seoMeta->robots) || (bool) $article->seoMeta->is_indexable !== $expected['is_indexable']) {
                $errors[] = $this->issue('article.'.$article->id.'.seo_meta', 'seo_indexability_mismatch', 'SEO meta indexability state does not match manifest.');
            }
        }

        return [
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'alt' => (string) $localeRow['alt'],
            'baseline_required' => $baselineRequired,
            'expected' => $expected,
            'before' => $this->snapshot($article),
        ];
    }

    /** @param array<string,mixed> $plan */
    private function assertExpectedState(int $articleId, array $plan): void
    {
        $article = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail($articleId);
        $expected = (array) $plan['expected'];
        if ((bool) $article->is_indexable !== (bool) $expected['is_indexable']
            || (bool) $article->sitemap_eligible !== (bool) $expected['sitemap_eligible']
            || (bool) $article->llms_eligible !== (bool) $expected['llms_eligible']
            || ! $article->seoMeta instanceof ArticleSeoMeta
            || ! hash_equals((string) $expected['robots'], (string) $article->seoMeta->robots)
            || ((string) $expected['canonical'] !== '' && ! hash_equals((string) $expected['canonical'], (string) $article->seoMeta->canonical_url))) {
            throw new RuntimeException('Post-write discoverability readback mismatch for article '.$articleId.'.');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(Article $article): array
    {
        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'canonical_url' => $article->seoMeta?->canonical_url,
            'robots' => $article->seoMeta?->robots,
            'seo_is_indexable' => $article->seoMeta?->is_indexable,
            'cover_image_url' => $article->cover_image_url,
            'cover_image_alt' => $article->cover_image_alt,
            'og_image_url' => $article->seoMeta?->og_image_url,
        ];
    }

    private function absoluteManifestPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }
        $real = realpath($path);

        return $real !== false && is_file($real) && is_readable($real) ? $real : null;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $options @return array<string,mixed> */
    private function baseReceipt(string $runId, string $startedAt, bool $execute, ?string $manifestPath, string $manifestSha, array $manifest, array $options, string $requiredPhrase): array
    {
        return [
            'schema_version' => 'article-cover-replacement-receipt.v1',
            'run_id' => $runId,
            'batch_id' => (string) ($manifest['batch_id'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => null,
            'mode' => $execute ? 'execute' : 'dry-run',
            'dry_run' => ! $execute,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'authorization' => [
                'actor' => (string) ($options['actor'] ?? ''),
                'reason' => (string) ($options['reason'] ?? ''),
                'allow_ensure_seo_meta_baseline' => (bool) ($options['allow_ensure_seo_meta_baseline'] ?? false),
                'required_confirmation_phrase' => $requiredPhrase,
                'holds' => array_intersect_key($options, array_flip(['no_publish', 'no_schema', 'no_hreflang', 'no_search', 'no_sitemap_llms_change', 'no_revalidation'])),
            ],
            'groups' => [],
            'verification' => null,
            'baseline_records' => [],
        ];
    }

    /** @param array<string,mixed> $receipt @param list<array<string,mixed>> $errors @return array<string,mixed> */
    private function finish(array $receipt, string $status, array $errors, bool $writesAttempted, bool $writesCommitted): array
    {
        $receipt['finished_at'] = now()->toIso8601String();
        $receipt['overall_status'] = $status;
        $receipt['ok'] = $status === 'passed';
        $receipt['writes_attempted'] = $writesAttempted;
        $receipt['writes_committed'] = $writesCommitted;
        $receipt['errors'] = $errors;

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function issue(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
