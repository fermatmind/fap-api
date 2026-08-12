<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicReadModelCache;
use App\Services\Cms\PersonalityReviewAttestationService;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use DomainException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fixed authority bridge for SEO-TOP100-FROZEN-20260812-v1 only.
 *
 * @review-surface personality_public_content_asset_revision_review
 * @review-surface article
 * @review-surface mbti_approval_batch
 */
final class Top100FrozenCmsBatchAuthority
{
    public function __construct(
        private readonly Top100FrozenPackage $packages,
        private readonly PersonalityPublicAssetReadModelCache $personalityAssetCache,
        private readonly PersonalityPublicReadModelCache $mbtiCache,
        private readonly PersonalityReviewAttestationService $reviews,
        private readonly PersonalityCmsPromotionReviewBinder $personalityReviewBinder,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
        private readonly ArticlePublishService $articlePublisher,
        private readonly HttpKernel $httpKernel,
        private readonly SeoDiscoverabilityCacheInvalidator $discoverabilityCache,
    ) {}

    /** @return array{targets:list<array<string,mixed>>,changed_count:int,unchanged_count:int,target_set_sha256:string} */
    public function inspect(PromotionContext $context, bool $lock = false): array
    {
        $package = $this->packages->inspect($context);
        $targets = [];
        $changed = 0;
        foreach ($package['targets'] as $target) {
            $resolved = $this->resolve($target, $lock);
            $resolved = $this->withTarget($resolved, $target);
            $current = $this->state($resolved);
            $desired = $this->desired($resolved, $current);
            $isChanged = ! hash_equals(
                PromotionContextFactory::canonicalJson($current['mutable']),
                PromotionContextFactory::canonicalJson($desired),
            );
            $targets[] = [
                ...$target,
                'model_kind' => $resolved['kind'],
                'model_id' => (int) $resolved['model']->getKey(),
                'current' => $current,
                'desired' => $desired,
                'changed' => $isChanged,
                'before_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($current)),
                'desired_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($desired)),
            ];
            $changed += $isChanged ? 1 : 0;
        }

        return [
            'targets' => $targets,
            'changed_count' => $changed,
            'unchanged_count' => count($targets) - $changed,
            'target_set_sha256' => $package['target_set_sha256'],
        ];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        return DB::transaction(function () use ($context): array {
            $package = $this->inspect($context, true);
            $created = 0;
            $personalityReviewTargets = [];
            foreach ($package['targets'] as $target) {
                if (! $target['changed']) {
                    continue;
                }
                $resolved = $this->resolve($target, true);
                $created += match ($resolved['kind']) {
                    'article' => $this->importArticleRevision($context, $target, $resolved),
                    'personality_asset' => $this->importPersonalityRevision($context, $target, $resolved, $personalityReviewTargets),
                    'mbti_profile', 'mbti_variant', 'mbti_comparison' => $this->importMbtiRevision($context, $target, $resolved),
                    'test_landing' => 0,
                    default => throw new DomainException('top100_frozen_draft_kind_invalid'),
                };
            }
            if ($personalityReviewTargets !== []) {
                $this->bindPersonalityReview($context, $personalityReviewTargets);
            }

            $readback = $this->inspect($context);

            return [
                'created_count' => $created,
                'unchanged_count' => 30 - $created,
                'readback_count' => count($readback['targets']),
            ];
        }, 3);
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    public function publish(PromotionContext $context): array
    {
        $result = DB::transaction(function () use ($context): array {
            $package = $this->inspect($context, true);
            $personalityReviewTargets = [];
            foreach ($package['targets'] as $target) {
                if ($target['changed'] && $target['model_kind'] === 'personality_asset') {
                    $resolved = $this->resolve($target, true);
                    $revision = $this->assertPersonalityRevisionMatchesTarget($context, $target, $resolved);
                    $personalityReviewTargets[] = [
                        'asset' => $resolved['model'],
                        'revision' => $revision,
                        'asset_key' => $this->assetKey($target),
                    ];
                }
            }
            if ($personalityReviewTargets !== []) {
                $this->personalityReviewBinder->assertApproved($context, $personalityReviewTargets);
            }
            $changed = 0;
            foreach ($package['targets'] as $target) {
                if (! $target['changed']) {
                    continue;
                }
                $resolved = $this->resolve($target, true);
                $this->assertBefore($target, $this->state($resolved));
                $this->apply($context, $target, $resolved);
                $changed++;
            }
            $this->assertReadback($context);

            return ['changed_count' => $changed, 'unchanged_count' => 30 - $changed, 'readback_count' => 30];
        }, 3);
        $this->invalidate($context);

        return $result;
    }

    /** @return array{readback_count:int,public_api_readback_count:int,live_html_readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $this->assertReadback($context);
        $package = $this->inspect($context);
        $publicApiReadbacks = 0;
        $liveHtmlReadbacks = 0;
        foreach ($package['targets'] as $target) {
            $this->assertPublicApiReadback($target);
            $publicApiReadbacks++;
            $this->assertLiveHtmlReadback($target);
            $liveHtmlReadbacks++;
        }

        return [
            'readback_count' => 30,
            'public_api_readback_count' => $publicApiReadbacks,
            'live_html_readback_count' => $liveHtmlReadbacks,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    public function rollback(PromotionContext $context, array $rows): void
    {
        DB::transaction(function () use ($context, $rows): void {
            if (count($rows) !== 30) {
                throw new DomainException('top100_frozen_rollback_count_invalid');
            }
            $targets = collect($this->inspect($context, true)['targets'])->keyBy(
                static fn (array $target): string => $target['priority'].':'.hash('sha256', $target['url']),
            );
            foreach ($rows as $row) {
                if (! is_array($row) || ($row['package_sha256'] ?? null) !== $context->packageSha256) {
                    throw new DomainException('top100_frozen_rollback_row_invalid');
                }
                $key = ((int) ($row['priority'] ?? 0)).':'.((string) ($row['url_sha256'] ?? ''));
                $target = $targets->get($key);
                if (! is_array($target) || ! $this->rollbackStateIsOwned($target, $row)) {
                    throw new DomainException('top100_frozen_rollback_concurrent_mutation');
                }
            }
            foreach (array_reverse($rows) as $row) {
                $this->restore($row);
            }
        }, 3);
        $this->invalidate($context, true);
    }

    /** @return array<string,mixed> */
    public function snapshotRow(PromotionContext $context, array $target): array
    {
        return [
            'priority' => $target['priority'],
            'url_sha256' => hash('sha256', $target['url']),
            'model_kind' => $target['model_kind'],
            'model_id' => $target['model_id'],
            'package_sha256' => $context->packageSha256,
            'before_sha256' => $target['before_sha256'],
            'desired_sha256' => $target['desired_sha256'],
            'before' => $target['current'],
            'desired' => $target['desired'],
        ];
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $row */
    private function rollbackStateIsOwned(array $target, array $row): bool
    {
        $current = $this->rollbackComparable((array) data_get($target, 'current.mutable', []));
        $before = $this->rollbackComparable((array) data_get($row, 'before.mutable', []));
        $desired = $this->rollbackComparable((array) ($row['desired'] ?? []));
        $currentSha = hash('sha256', PromotionContextFactory::canonicalJson($current));

        return $this->rollbackRevisionStateIsOwned($target, $row)
            && (hash_equals(hash('sha256', PromotionContextFactory::canonicalJson($before)), $currentSha)
                || hash_equals(hash('sha256', PromotionContextFactory::canonicalJson($desired)), $currentSha));
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $row */
    private function rollbackRevisionStateIsOwned(array $target, array $row): bool
    {
        if (! in_array($row['model_kind'] ?? null, ['article', 'personality_asset'], true)) {
            return true;
        }
        $assetKey = 'seo-top100-frozen:'.str_pad((string) ((int) ($row['priority'] ?? 0)), 3, '0', STR_PAD_LEFT).':'.(string) ($row['url_sha256'] ?? '');
        $revision = ($row['model_kind'] === 'article'
            ? ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $row['model_id'])
            : PersonalityPublicContentAssetRevision::query()->where('asset_id', (int) $row['model_id']))
            ->where('authority_package_sha256', (string) $row['package_sha256'])
            ->where('authority_asset_key', $assetKey)
            ->first();
        if (! $revision instanceof Model) {
            return false;
        }
        $current = (array) data_get($target, 'current.mutable', []);
        $before = (array) data_get($row, 'before.mutable', []);
        $currentArticle = (array) ($current['article'] ?? []);
        $beforeArticle = (array) ($before['article'] ?? []);
        $working = $currentArticle['working_revision_id'] ?? $current['working_revision_id'] ?? null;
        $published = $currentArticle['published_revision_id'] ?? $current['published_revision_id'] ?? null;
        $beforeWorking = $beforeArticle['working_revision_id'] ?? $before['working_revision_id'] ?? null;
        $beforePublished = $beforeArticle['published_revision_id'] ?? $before['published_revision_id'] ?? null;
        if (! in_array($working, [$beforeWorking, $revision->getKey()], true)
            || ! in_array($published, [$beforePublished, $revision->getKey()], true)) {
            return false;
        }
        if ($row['model_kind'] !== 'article') {
            return true;
        }
        $statuses = (array) ($current['revision_statuses'] ?? []);
        $beforeStatuses = (array) ($before['revision_statuses'] ?? []);
        foreach ($beforeStatuses as $id => $status) {
            $currentStatus = $statuses[$id] ?? null;
            $controlledPriorPublicationDemotion = (int) $id === (int) $beforePublished
                && $status === ArticleTranslationRevision::STATUS_PUBLISHED
                && $currentStatus === ArticleTranslationRevision::STATUS_STALE
                && (int) $published === (int) $revision->getKey();
            if ($currentStatus !== $status
                && (int) $id !== (int) $revision->getKey()
                && ! $controlledPriorPublicationDemotion) {
                return false;
            }
        }
        $extra = array_diff(array_map('intval', array_keys($statuses)), [...array_map('intval', array_keys($beforeStatuses)), (int) $revision->getKey()]);

        return $extra === [];
    }

    /** @param array<string,mixed> $mutable @return array<string,mixed> */
    private function rollbackComparable(array $mutable): array
    {
        data_forget($mutable, 'working_revision_id');
        data_forget($mutable, 'published_revision_id');
        data_forget($mutable, 'revision_statuses');
        data_forget($mutable, 'article.working_revision_id');
        data_forget($mutable, 'article.published_revision_id');
        data_forget($mutable, 'article.source_version_hash');

        return $mutable;
    }

    /** @return array{kind:string,model:Model,seo?:Model,section?:Model} */
    private function resolve(array $target, bool $lock): array
    {
        return match ($target['family']) {
            'enneagram_wing', 'big_five' => $this->resolvePersonalityAsset($target, $lock),
            'mbti_profile' => $this->resolveMbtiProfile($target, $lock),
            'mbti_comparison' => $this->resolveMbtiComparison($target, $lock),
            'article' => $this->resolveArticle($target, $lock),
            'test_landing' => $this->resolveLanding($target, $lock),
            default => throw new DomainException('top100_frozen_family_not_supported'),
        };
    }

    /** @return array{kind:string,model:Model} */
    private function resolvePersonalityAsset(array $target, bool $lock): array
    {
        $framework = $target['family'] === 'big_five' ? PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE : PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM;
        $query = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('framework', $framework)->where('locale', $target['locale'])
            ->where(static fn ($query) => $query->where('entity_key', $target['slug'])->orWhere('slug', $target['slug']))
            ->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->where('is_public', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw new DomainException('top100_frozen_personality_target_invalid');
        }

        return ['kind' => 'personality_asset', 'model' => $matches->first()];
    }

    /** @return array{kind:string,model:Model,seo:Model} */
    private function resolveMbtiProfile(array $target, bool $lock): array
    {
        if (preg_match('/\A([a-z]{4})(?:-([at]))?\z/', $target['slug'], $match) !== 1) {
            throw new DomainException('top100_frozen_mbti_profile_slug_invalid');
        }
        $profileQuery = PersonalityProfile::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)->where('locale', $target['locale'])
            ->where('canonical_type_code', strtoupper($match[1]))->where('status', 'published')->where('is_public', true);
        if ($lock) {
            $profileQuery->lockForUpdate();
        }
        $profile = $profileQuery->first();
        if (! $profile instanceof PersonalityProfile) {
            throw new DomainException('top100_frozen_mbti_profile_target_invalid');
        }
        if (! isset($match[2])) {
            $seoQuery = PersonalityProfileSeoMeta::query()->withoutGlobalScopes()->where('profile_id', $profile->id);
            if ($lock) {
                $seoQuery->lockForUpdate();
            }
            $seo = $seoQuery->first();
            if (! $seo instanceof PersonalityProfileSeoMeta) {
                throw new DomainException('top100_frozen_mbti_profile_seo_missing');
            }

            return ['kind' => 'mbti_profile', 'model' => $profile, 'seo' => $seo, 'lock_links' => $lock];
        }
        $variantQuery = PersonalityProfileVariant::query()->withoutGlobalScopes()->where('personality_profile_id', $profile->id)
            ->where('runtime_type_code', strtoupper($target['slug']))->where('is_published', true);
        if ($lock) {
            $variantQuery->lockForUpdate();
        }
        $variant = $variantQuery->first();
        $seoQuery = $variant instanceof PersonalityProfileVariant
            ? PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->where('personality_profile_variant_id', $variant->id)
            : null;
        if ($lock && $seoQuery !== null) {
            $seoQuery->lockForUpdate();
        }
        $seo = $seoQuery?->first();
        if (! $variant instanceof PersonalityProfileVariant || ! $seo instanceof PersonalityProfileVariantSeoMeta) {
            throw new DomainException('top100_frozen_mbti_variant_target_invalid');
        }

        return ['kind' => 'mbti_variant', 'model' => $variant, 'seo' => $seo, 'lock_links' => $lock];
    }

    /** @return array{kind:string,model:Model,section:Model} */
    private function resolveMbtiComparison(array $target, bool $lock): array
    {
        if (preg_match('/\A([a-z]{4})-a-vs-\1-t\z/', $target['slug'], $match) !== 1) {
            throw new DomainException('top100_frozen_mbti_comparison_slug_invalid');
        }
        $profileQuery = PersonalityProfile::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)->where('locale', $target['locale'])
            ->where('canonical_type_code', strtoupper($match[1]))->where('status', 'published')->where('is_public', true);
        if ($lock) {
            $profileQuery->lockForUpdate();
        }
        $profile = $profileQuery->first();
        $sectionQuery = $profile instanceof PersonalityProfile
            ? PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('section_key', 'mbti64_comparison_a_vs_t')
            : null;
        if ($lock && $sectionQuery !== null) {
            $sectionQuery->lockForUpdate();
        }
        $section = $sectionQuery?->first();
        if (! $profile instanceof PersonalityProfile || ! $section instanceof PersonalityProfileSection || ! (bool) $section->is_enabled) {
            throw new DomainException('top100_frozen_mbti_comparison_target_invalid');
        }

        return ['kind' => 'mbti_comparison', 'model' => $profile, 'section' => $section];
    }

    /** @return array{kind:string,model:Model,seo:Model} */
    private function resolveArticle(array $target, bool $lock): array
    {
        $query = Article::query()->withoutGlobalScopes()->where('org_id', 0)->where('locale', $target['locale'])
            ->where('slug', $target['slug'])->where('status', 'published')->where('is_public', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $article = $query->first();
        $seoQuery = $article instanceof Article
            ? ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->where('locale', $article->locale)
            : null;
        if ($lock && $seoQuery !== null) {
            $seoQuery->lockForUpdate();
        }
        $seo = $seoQuery?->first();
        if (! $article instanceof Article || ! $seo instanceof ArticleSeoMeta || ! $article->published_revision_id) {
            throw new DomainException('top100_frozen_article_target_invalid');
        }

        return ['kind' => 'article', 'model' => $article, 'seo' => $seo];
    }

    /** @return array{kind:string,model:Model} */
    private function resolveLanding(array $target, bool $lock): array
    {
        $key = 'test_detail_'.str_replace('-', '_', $target['slug']);
        $query = LandingSurface::query()->withoutGlobalScopes()->where('org_id', 0)->where('surface_key', $key)
            ->where('locale', $target['locale'])->where('status', LandingSurface::STATUS_PUBLISHED)->where('is_public', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $surface = $query->first();
        if (! $surface instanceof LandingSurface) {
            throw new DomainException('top100_frozen_landing_target_invalid');
        }

        return ['kind' => 'test_landing', 'model' => $surface];
    }

    /** @param array{kind:string,model:Model,seo?:Model,section?:Model} $resolved @return array{mutable:array<string,mixed>,protected:array<string,mixed>} */
    private function state(array $resolved): array
    {
        $model = $resolved['model'];

        return match ($resolved['kind']) {
            'personality_asset' => [
                'mutable' => $this->attributes($model, ['title', 'summary', 'content_sections_json', 'seo_json', 'internal_links_json', 'source_package', 'source_hash', 'published_revision_id', 'working_revision_id']),
                'protected' => $this->attributes($model, ['org_id', 'framework', 'entity_type', 'entity_key', 'slug', 'locale', 'robots', 'canonical_json', 'hreflang_json', 'faq_json', 'schema_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'is_public', 'index_eligible', 'sitemap_eligible', 'llms_eligible', 'launch_state']),
            ],
            'mbti_profile', 'mbti_variant' => [
                'mutable' => [
                    'content' => $this->attributes($model, $resolved['kind'] === 'mbti_profile'
                        ? ['title', 'hero_summary_md', 'hero_summary_html']
                        : ['hero_summary_md', 'hero_summary_html']),
                    'seo' => $this->attributes($resolved['seo'], ['seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'jsonld_overrides_json']),
                    'links' => $this->mbtiLinksState($resolved),
                ],
                'protected' => [
                    'content' => $this->attributes($model, $resolved['kind'] === 'mbti_profile'
                        ? ['org_id', 'scale_code', 'type_code', 'canonical_type_code', 'slug', 'locale', 'status', 'is_public', 'is_indexable', 'published_at']
                        : ['org_id', 'personality_profile_id', 'canonical_type_code', 'variant_code', 'runtime_type_code', 'is_published', 'published_at']),
                    'seo' => $this->attributes($resolved['seo'], ['canonical_url', 'robots', 'og_image_url', 'twitter_image_url']),
                ],
            ],
            'mbti_comparison' => [
                'mutable' => $this->attributes($resolved['section'], ['title', 'body_md', 'body_html', 'payload_json', 'sort_order', 'is_enabled']),
                'protected' => [
                    'profile' => $this->attributes($model, ['org_id', 'scale_code', 'canonical_type_code', 'slug', 'locale', 'status', 'is_public', 'is_indexable', 'published_at']),
                    'section_identity' => $this->attributes($resolved['section'], ['profile_id', 'section_key']),
                ],
            ],
            'article' => [
                'mutable' => [
                    'article' => $this->attributes($model, ['title', 'excerpt', 'content_md', 'content_html', 'source_version_hash', 'working_revision_id', 'published_revision_id']),
                    'seo' => $this->attributes($resolved['seo'], ['seo_title', 'seo_description', 'og_title', 'og_description']),
                    'revision_statuses' => ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $model->getKey())->pluck('revision_status', 'id')->all(),
                ],
                'protected' => $this->attributes($model, ['org_id', 'slug', 'locale', 'status', 'is_public', 'is_indexable', 'sitemap_eligible', 'llms_eligible', 'published_at', 'category_id', 'cover_image_url']),
            ],
            'test_landing' => [
                'mutable' => $this->attributes($model, ['title', 'description', 'payload_json']),
                'protected' => $this->attributes($model, ['org_id', 'surface_key', 'locale', 'schema_version', 'status', 'is_public', 'is_indexable', 'published_at']),
            ],
            default => throw new DomainException('top100_frozen_state_kind_invalid'),
        };
    }

    /** @return array<string,mixed> */
    private function desired(array $resolved, array $current): array
    {
        $target = $resolved['target'] ?? null;
        if (! is_array($target)) {
            throw new DomainException('top100_frozen_desired_target_missing');
        }

        return match ($resolved['kind']) {
            'personality_asset' => $this->desiredPersonality($target, $current['mutable']),
            'mbti_profile', 'mbti_variant' => $this->desiredMbtiProfile($target, $current['mutable']),
            'mbti_comparison' => $this->desiredMbtiComparison($target, $current['mutable']),
            'article' => $this->desiredArticle($target, $current['mutable']),
            'test_landing' => $this->desiredLanding($target, $current['mutable']),
            default => throw new DomainException('top100_frozen_desired_kind_invalid'),
        };
    }

    /** @return array{kind:string,model:Model,seo?:Model,section?:Model,target:array<string,mixed>} */
    private function withTarget(array $resolved, array $target): array
    {
        $resolved['target'] = $target;

        return $resolved;
    }

    /** @return array<string,mixed> */
    private function desiredPersonality(array $target, array $current): array
    {
        $seo = is_array($current['seo_json'] ?? null) ? $current['seo_json'] : [];
        if ($target['patch']['seo_title'] !== null) {
            $seo['title'] = $target['patch']['seo_title'];
        }
        if ($target['patch']['seo_description'] !== null) {
            $seo['description'] = $target['patch']['seo_description'];
        }
        $current['seo_json'] = $seo;
        if ($target['patch']['h1'] !== null) {
            $current['title'] = $target['patch']['h1'];
        }
        if ($target['patch']['intro'] !== null) {
            $current['summary'] = $target['patch']['intro'];
        }
        $current['internal_links_json'] = $this->mergeLinks((array) ($current['internal_links_json'] ?? []), $target['internal_links']);
        $current['source_package'] = 'content-promotion/TOP100/'.Top100FrozenPackage::SUBSCOPE;
        $current['source_hash'] = $target['source_row_sha256'];

        return $current;
    }

    /** @return array<string,mixed> */
    private function desiredMbtiProfile(array $target, array $current): array
    {
        $seo = (array) $current['seo'];
        if ($target['patch']['seo_title'] !== null) {
            foreach (['seo_title', 'og_title', 'twitter_title'] as $field) {
                $seo[$field] = $target['patch']['seo_title'];
            }
        }
        if ($target['patch']['seo_description'] !== null) {
            foreach (['seo_description', 'og_description', 'twitter_description'] as $field) {
                $seo[$field] = $target['patch']['seo_description'];
            }
        }
        if ($target['patch']['h1'] !== null) {
            $jsonld = is_array($seo['jsonld_overrides_json'] ?? null) ? $seo['jsonld_overrides_json'] : [];
            $jsonld['name'] = $target['patch']['h1'];
            $seo['jsonld_overrides_json'] = $jsonld;
            if (array_key_exists('title', $current['content'])) {
                $current['content']['title'] = $target['patch']['h1'];
            }
        }
        if ($target['patch']['intro'] !== null) {
            $current['content']['hero_summary_md'] = $target['patch']['intro'];
            $current['content']['hero_summary_html'] = null;
        }
        $current['seo'] = $seo;
        $current['links'] = $this->mergeLinks((array) $current['links'], $target['internal_links']);

        return $current;
    }

    /** @return array<string,mixed> */
    private function desiredMbtiComparison(array $target, array $current): array
    {
        $payload = is_array($current['payload_json'] ?? null) ? $current['payload_json'] : [];
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        if ($target['patch']['seo_title'] !== null) {
            $seo['seo_title'] = $target['patch']['seo_title'];
        }
        if ($target['patch']['seo_description'] !== null) {
            $seo['seo_description'] = $target['patch']['seo_description'];
        }
        if ($target['patch']['h1'] !== null) {
            $seo['h1'] = $target['patch']['h1'];
            $current['title'] = $target['patch']['h1'];
        }
        if ($target['patch']['intro'] !== null) {
            $seo['quick_answer_summary'] = $target['patch']['intro'];
            $content['quick_answer'] = $target['patch']['intro'];
            $current['body_md'] = $target['patch']['intro'];
            $current['body_html'] = null;
        }
        $payload['seo'] = $seo;
        $payload['content'] = $content;
        $payload['internal_links'] = $this->mergeLinks((array) ($payload['internal_links'] ?? []), $target['internal_links']);
        $current['payload_json'] = $payload;

        return $current;
    }

    /** @return array<string,mixed> */
    private function desiredArticle(array $target, array $current): array
    {
        $article = (array) $current['article'];
        $seo = (array) $current['seo'];
        if ($target['patch']['seo_title'] !== null) {
            $seo['seo_title'] = $target['patch']['seo_title'];
            $seo['og_title'] = $target['patch']['seo_title'];
        }
        if ($target['patch']['seo_description'] !== null) {
            $seo['seo_description'] = $target['patch']['seo_description'];
            $seo['og_description'] = $target['patch']['seo_description'];
        }
        if ($target['patch']['h1'] !== null) {
            $article['title'] = $target['patch']['h1'];
        }
        if ($target['patch']['intro'] !== null) {
            $article['content_md'] = $this->replaceFirstParagraph((string) $article['content_md'], $target['patch']['intro']);
            $article['content_html'] = null;
        }
        $article['content_md'] = $this->appendMarkdownLinks((string) $article['content_md'], $target['internal_links']);
        $this->articleBodyHeadingGuard->assertNoBodyH1((string) $article['content_md']);

        return ['article' => $article, 'seo' => $seo, 'revision_statuses' => $current['revision_statuses']];
    }

    /** @return array<string,mixed> */
    private function desiredLanding(array $target, array $current): array
    {
        $payload = is_array($current['payload_json'] ?? null) ? $current['payload_json'] : [];
        if ($target['patch']['seo_title'] !== null) {
            $payload['seo_title'] = $target['patch']['seo_title'];
        }
        if ($target['patch']['seo_description'] !== null) {
            $current['description'] = $target['patch']['seo_description'];
            $payload['seo_description'] = $target['patch']['seo_description'];
        }
        if ($target['patch']['h1'] !== null) {
            $current['title'] = $target['patch']['h1'];
            $payload['h1_or_hero_title'] = $target['patch']['h1'];
        }
        if ($target['patch']['intro'] !== null) {
            $payload['intro'] = $target['patch']['intro'];
        }
        $payload['internal_links'] = $this->mergeLinks((array) ($payload['internal_links'] ?? []), $target['internal_links']);
        $current['payload_json'] = $payload;

        return $current;
    }

    /** @param array{kind:string,model:Model,seo?:Model,section?:Model} $resolved */
    private function importArticleRevision(PromotionContext $context, array $target, array $resolved): int
    {
        /** @var Article $article */
        $article = $resolved['model'];
        $assetKey = $this->assetKey($target);
        $existing = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $assetKey)->first();
        if ($existing instanceof ArticleTranslationRevision) {
            if ((int) $existing->article_id !== (int) $article->id
                || ! in_array((string) $existing->revision_status, [ArticleTranslationRevision::STATUS_APPROVED, ArticleTranslationRevision::STATUS_PUBLISHED], true)
                || ! in_array($article->working_revision_id, [null, $existing->id], true)) {
                throw new DomainException('top100_frozen_article_revision_collision');
            }
            if (! $this->articleRevisionMatchesTarget($existing, $article, $target)) {
                if ($article->working_revision_id !== null || (string) $existing->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                    throw new DomainException('top100_frozen_article_revision_recovery_drift');
                }
                $existing->delete();
                $existing = null;
            }
        }
        if ($existing instanceof ArticleTranslationRevision) {
            if ($article->working_revision_id === null) {
                $article->forceFill(['working_revision_id' => $existing->id])->saveQuietly();
            }

            return 0;
        }
        if ($article->working_revision_id !== null) {
            throw new DomainException('top100_frozen_article_foreign_working_revision');
        }
        $desiredArticle = (array) $target['desired']['article'];
        $desiredSeo = (array) $target['desired']['seo'];
        $actor = $this->ownerActor();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'source_version_hash' => hash('sha256', PromotionContextFactory::canonicalJson(['title' => $desiredArticle['title'], 'excerpt' => $desiredArticle['excerpt'], 'content_md' => $desiredArticle['content_md']])),
            'translated_from_version_hash' => (string) ($article->translated_from_version_hash ?: $article->source_version_hash),
            'supersedes_revision_id' => $article->published_revision_id,
            'authority_asset_key' => $assetKey,
            'authority_source_package' => 'content-promotion/TOP100/'.Top100FrozenPackage::SUBSCOPE,
            'authority_source_hash' => $target['source_row_sha256'],
            'authority_package_sha256' => $context->packageSha256,
            'authority_metadata_json' => [
                'priority' => $target['priority'],
                'desired_payload_sha256' => $this->articleRevisionPayloadSha256($target),
            ],
            'title' => $desiredArticle['title'],
            'excerpt' => $desiredArticle['excerpt'],
            'content_md' => $desiredArticle['content_md'],
            'seo_title' => $desiredSeo['seo_title'],
            'seo_description' => $desiredSeo['seo_description'],
            'created_by' => $actor,
            'reviewed_by' => $actor,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);
        $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();

        return 1;
    }

    /** @param array<string,mixed> $target */
    private function articleRevisionMatchesTarget(ArticleTranslationRevision $revision, Article $article, array $target): bool
    {
        $desiredArticle = (array) data_get($target, 'desired.article', []);
        $desiredSeo = (array) data_get($target, 'desired.seo', []);
        $expectedSourceVersionHash = hash('sha256', PromotionContextFactory::canonicalJson([
            'title' => $desiredArticle['title'] ?? null,
            'excerpt' => $desiredArticle['excerpt'] ?? null,
            'content_md' => $desiredArticle['content_md'] ?? null,
        ]));

        return (int) $revision->supersedes_revision_id === (int) $article->published_revision_id
            && (string) $revision->authority_source_hash === (string) $target['source_row_sha256']
            && hash_equals($expectedSourceVersionHash, (string) $revision->source_version_hash)
            && hash_equals($this->articleRevisionPayloadSha256($target), (string) data_get($revision->authority_metadata_json, 'desired_payload_sha256', ''))
            && hash_equals(PromotionContextFactory::canonicalJson([
                'title' => $revision->title,
                'excerpt' => $revision->excerpt,
                'content_md' => $revision->content_md,
                'seo_title' => $revision->seo_title,
                'seo_description' => $revision->seo_description,
            ]), PromotionContextFactory::canonicalJson([
                'title' => $desiredArticle['title'] ?? null,
                'excerpt' => $desiredArticle['excerpt'] ?? null,
                'content_md' => $desiredArticle['content_md'] ?? null,
                'seo_title' => $desiredSeo['seo_title'] ?? null,
                'seo_description' => $desiredSeo['seo_description'] ?? null,
            ]));
    }

    /** @param array<string,mixed> $target */
    private function articleRevisionPayloadSha256(array $target): string
    {
        $article = (array) data_get($target, 'desired.article', []);
        $seo = (array) data_get($target, 'desired.seo', []);

        return hash('sha256', PromotionContextFactory::canonicalJson([
            'article' => [
                'title' => $article['title'] ?? null,
                'excerpt' => $article['excerpt'] ?? null,
                'content_md' => $article['content_md'] ?? null,
            ],
            'seo' => [
                'seo_title' => $seo['seo_title'] ?? null,
                'seo_description' => $seo['seo_description'] ?? null,
            ],
        ]));
    }

    /** @param list<array<string,mixed>> $reviewTargets */
    private function importPersonalityRevision(PromotionContext $context, array $target, array $resolved, array &$reviewTargets): int
    {
        /** @var PersonalityPublicContentAsset $asset */
        $asset = $resolved['model'];
        $assetKey = $this->assetKey($target);
        $existing = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $assetKey)->first();
        $snapshot = $this->personalityRevisionSnapshot($target);
        $created = 0;
        if ($existing instanceof PersonalityPublicContentAssetRevision) {
            if ((int) $existing->asset_id !== (int) $asset->id
                || ! in_array((string) $existing->workflow_state, [PersonalityPublicContentAssetRevision::STATE_DRAFT, 'published'], true)
                || ! in_array($asset->working_revision_id, [null, $existing->id], true)) {
                throw new DomainException('top100_frozen_personality_revision_collision');
            }
            $snapshotMatches = (string) $existing->source_hash === (string) $target['source_row_sha256']
                && hash_equals(
                    PromotionContextFactory::canonicalJson((array) $existing->snapshot_json),
                    PromotionContextFactory::canonicalJson($snapshot),
                );
            if (! $snapshotMatches) {
                if ($asset->working_revision_id !== null || (string) $existing->workflow_state !== PersonalityPublicContentAssetRevision::STATE_DRAFT) {
                    throw new DomainException('top100_frozen_personality_revision_recovery_drift');
                }
                PersonalityPublicContentAssetRevisionReview::query()->where('revision_id', $existing->id)->delete();
                $existing->delete();
                $existing = null;
            }
        }
        if (! $existing instanceof PersonalityPublicContentAssetRevision) {
            if ($asset->working_revision_id !== null) {
                throw new DomainException('top100_frozen_personality_foreign_working_revision');
            }
            $existing = PersonalityPublicContentAssetRevision::query()->create([
                'asset_id' => $asset->id,
                'revision_no' => ((int) PersonalityPublicContentAssetRevision::query()->where('asset_id', $asset->id)->max('revision_no')) + 1,
                'authority_asset_key' => $assetKey,
                'source_package' => $snapshot['source_package'],
                'source_hash' => $snapshot['source_hash'],
                'authority_package_sha256' => $context->packageSha256,
                'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
                'snapshot_json' => $snapshot,
                'public_runtime_fingerprint_before' => $target['before_sha256'],
                'created_by_admin_user_id' => $this->ownerActor(),
            ]);
            $asset->forceFill(['working_revision_id' => $existing->id])->saveQuietly();
            $created = 1;
        } else {
            if ($asset->working_revision_id === null) {
                $asset->forceFill(['working_revision_id' => $existing->id])->saveQuietly();
            }
        }
        $reviewTargets[] = ['asset' => $asset, 'revision' => $existing, 'asset_key' => $assetKey, 'source_hash' => $target['source_row_sha256']];

        return $created;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function personalityRevisionSnapshot(array $target): array
    {
        $snapshot = array_intersect_key($target['desired'], array_flip([
            'title', 'summary', 'content_sections_json', 'seo_json', 'internal_links_json', 'source_package', 'source_hash',
        ]));
        $snapshot['source_package'] = 'content-promotion/TOP100/'.Top100FrozenPackage::SUBSCOPE;
        $snapshot['source_hash'] = $target['source_row_sha256'];

        return $snapshot;
    }

    private function importMbtiRevision(PromotionContext $context, array $target, array $resolved): int
    {
        $key = 'seo_top100_frozen_20260812_v1';
        $snapshot = [$key => [
            'package_sha256' => $context->packageSha256,
            'priority' => $target['priority'],
            'source_row_sha256' => $target['source_row_sha256'],
            'desired_sha256' => $target['desired_sha256'],
            'desired' => $target['desired'],
        ]];
        if ($resolved['kind'] === 'mbti_variant') {
            $model = $resolved['model'];
            $existing = PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $model->id)
                ->get()->first(static fn (PersonalityProfileVariantRevision $revision): bool => data_get($revision->snapshot_json, $key.'.package_sha256') === $context->packageSha256);
            if ($existing instanceof PersonalityProfileVariantRevision) {
                if (hash_equals(PromotionContextFactory::canonicalJson((array) $existing->snapshot_json), PromotionContextFactory::canonicalJson($snapshot))) {
                    return 0;
                }
                $existing->delete();
            }
            PersonalityProfileVariantRevision::query()->create([
                'personality_profile_variant_id' => $model->id,
                'revision_no' => ((int) PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $model->id)->max('revision_no')) + 1,
                'snapshot_json' => $snapshot,
                'note' => Top100FrozenPackage::BATCH_ID,
                'created_by_admin_user_id' => $this->ownerActor(),
                'created_at' => now(),
            ]);

            return 1;
        }
        $model = $resolved['model'];
        $existing = PersonalityProfileRevision::query()->where('profile_id', $model->id)
            ->get()->first(static fn (PersonalityProfileRevision $revision): bool => data_get($revision->snapshot_json, $key.'.package_sha256') === $context->packageSha256
                && data_get($revision->snapshot_json, $key.'.priority') === $target['priority']);
        if ($existing instanceof PersonalityProfileRevision) {
            if (hash_equals(PromotionContextFactory::canonicalJson((array) $existing->snapshot_json), PromotionContextFactory::canonicalJson($snapshot))) {
                return 0;
            }
            $existing->delete();
        }
        PersonalityProfileRevision::query()->create([
            'profile_id' => $model->id,
            'revision_no' => ((int) PersonalityProfileRevision::query()->where('profile_id', $model->id)->max('revision_no')) + 1,
            'snapshot_json' => $snapshot,
            'note' => Top100FrozenPackage::BATCH_ID,
            'created_by_admin_user_id' => $this->ownerActor(),
            'created_at' => now(),
        ]);

        return 1;
    }

    /** @param list<array{asset:PersonalityPublicContentAsset,revision:PersonalityPublicContentAssetRevision,asset_key:string,source_hash:string}> $targets */
    private function bindPersonalityReview(PromotionContext $context, array $targets): void
    {
        $actor = $this->ownerActor();
        $authoritativeTargets = array_map(static fn (array $target): array => [
            'identity' => 'content-promotion:TOP100/'.Top100FrozenPackage::SUBSCOPE.':'.$target['asset_key'],
            'sha256' => $target['source_hash'],
        ], $targets);
        $attestation = $this->reviews->bindOrCreateApproved(
            null,
            'personality_public_content_asset_revision_review',
            'exact_package',
            Top100FrozenPackage::BATCH_ID,
            $authoritativeTargets,
            $actor,
            $context->packageSha256,
        )->loadMissing('targetEvidences');
        $evidence = $attestation->targetEvidences->keyBy('target_identity');
        foreach ($targets as $target) {
            $identity = 'personality_public_content_asset_revision_review:content-promotion:TOP100/'.Top100FrozenPackage::SUBSCOPE.':'.$target['asset_key'];
            $targetEvidence = $evidence->get($identity);
            if ($targetEvidence === null) {
                throw new DomainException('top100_frozen_personality_review_evidence_missing');
            }
            PersonalityPublicContentAssetRevisionReview::query()->firstOrCreate(
                ['revision_id' => $target['revision']->id],
                [
                    'asset_id' => $target['asset']->id,
                    'authority_asset_key' => $target['asset_key'],
                    'source_package' => $target['revision']->source_package,
                    'asset_sha256' => $target['source_hash'],
                    'authority_package_sha256' => $context->packageSha256,
                    'review_register_sha256' => $attestation->evidence_sha256,
                    'reviewer_name' => 'configured_solo_owner',
                    'reviewed_at' => $attestation->attested_at,
                    'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
                    'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
                    'evidence_sha256' => $targetEvidence->evidence_sha256,
                    'bound_by_admin_user_id' => $actor,
                ],
            );
        }
    }

    private function apply(PromotionContext $context, array $target, array $resolved): void
    {
        match ($resolved['kind']) {
            'personality_asset' => $this->publishPersonality($context, $target, $resolved),
            'mbti_profile', 'mbti_variant' => $this->publishMbtiProfile($target, $resolved),
            'mbti_comparison' => $this->publishMbtiComparison($target, $resolved),
            'article' => $this->publishArticle($context, $target, $resolved),
            'test_landing' => $this->publishLanding($target, $resolved),
            default => throw new DomainException('top100_frozen_publish_kind_invalid'),
        };
    }

    private function publishPersonality(PromotionContext $context, array $target, array $resolved): void
    {
        /** @var PersonalityPublicContentAsset $asset */
        $asset = $resolved['model'];
        $revision = $this->assertPersonalityRevisionMatchesTarget($context, $target, $resolved);
        $desired = $target['desired'];
        $asset->forceFill([
            'title' => $desired['title'],
            'summary' => $desired['summary'],
            'content_sections_json' => $desired['content_sections_json'],
            'seo_json' => $desired['seo_json'],
            'internal_links_json' => $desired['internal_links_json'],
            'source_package' => 'content-promotion/TOP100/'.Top100FrozenPackage::SUBSCOPE,
            'source_hash' => $target['source_row_sha256'],
            'published_revision_id' => $revision->id,
            'working_revision_id' => null,
        ])->saveQuietly();
        $revision->forceFill(['workflow_state' => 'published'])->save();
    }

    /** @param array{kind:string,model:Model,seo?:Model,section?:Model} $resolved */
    private function assertPersonalityRevisionMatchesTarget(
        PromotionContext $context,
        array $target,
        array $resolved,
    ): PersonalityPublicContentAssetRevision {
        /** @var PersonalityPublicContentAsset $asset */
        $asset = $resolved['model'];
        $revision = PersonalityPublicContentAssetRevision::query()->lockForUpdate()
            ->where('authority_package_sha256', $context->packageSha256)
            ->where('authority_asset_key', $this->assetKey($target))->first();
        if (! $revision instanceof PersonalityPublicContentAssetRevision
            || (int) $revision->asset_id !== (int) $asset->id
            || (int) $asset->working_revision_id !== (int) $revision->id
            || (string) $revision->workflow_state !== PersonalityPublicContentAssetRevision::STATE_DRAFT
            || ! hash_equals((string) $target['source_row_sha256'], (string) $revision->source_hash)
            || ! hash_equals('content-promotion/TOP100/'.Top100FrozenPackage::SUBSCOPE, (string) $revision->source_package)
            || ! hash_equals(
                PromotionContextFactory::canonicalJson($this->personalityRevisionSnapshot($target)),
                PromotionContextFactory::canonicalJson((array) $revision->snapshot_json),
            )) {
            throw new DomainException('top100_frozen_personality_revision_not_approved');
        }

        return $revision;
    }

    private function publishMbtiProfile(array $target, array $resolved): void
    {
        $content = $target['desired']['content'];
        $seo = $target['desired']['seo'];
        $resolved['model']->forceFill($content)->saveQuietly();
        $resolved['seo']->forceFill($seo)->saveQuietly();
        $this->writeMbtiLinks($resolved, $target['desired']['links']);
    }

    private function publishMbtiComparison(array $target, array $resolved): void
    {
        $resolved['section']->forceFill($target['desired'])->saveQuietly();
    }

    private function publishArticle(PromotionContext $context, array $target, array $resolved): void
    {
        /** @var Article $article */
        $article = $resolved['model'];
        /** @var ArticleTranslationRevision|null $revision */
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->lockForUpdate()
            ->where('authority_package_sha256', $context->packageSha256)
            ->where('authority_asset_key', $this->assetKey($target))->first();
        if (! $revision instanceof ArticleTranslationRevision || (int) $article->working_revision_id !== (int) $revision->id
            || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
            throw new DomainException('top100_frozen_article_revision_not_approved');
        }
        $publishedRevisionId = (int) $article->published_revision_id;
        $this->articlePublisher->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $revision->id,
            $publishedRevisionId,
            'seo_top100_frozen_exact_package_existing_article_promotion',
            dispatchFollowUp: false,
            transactionGuard: function (Article $lockedArticle, ArticleTranslationRevision $lockedRevision) use ($context, $target): void {
                if (! hash_equals($context->packageSha256, (string) $lockedRevision->authority_package_sha256)
                    || ! hash_equals($this->assetKey($target), (string) $lockedRevision->authority_asset_key)
                    || (string) $lockedRevision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED
                    || ! $this->articleRevisionMatchesTarget($lockedRevision, $lockedArticle, $target)) {
                    throw new DomainException('top100_frozen_article_controlled_promotion_lock_invalid');
                }
                $this->articleBodyHeadingGuard->assertNoBodyH1((string) $lockedRevision->content_md);
            },
        );
        Article::query()->withoutGlobalScopes()->whereKey($article->id)->update(['working_revision_id' => null]);
    }

    private function publishLanding(array $target, array $resolved): void
    {
        $resolved['model']->forceFill($target['desired'])->saveQuietly();
    }

    private function assertReadback(PromotionContext $context): void
    {
        $package = $this->inspect($context);
        foreach ($package['targets'] as $target) {
            $actual = $target['current']['mutable'];
            $desired = $target['desired'];
            if (! hash_equals(PromotionContextFactory::canonicalJson($desired), PromotionContextFactory::canonicalJson($actual))) {
                throw new DomainException('top100_frozen_public_readback_mismatch');
            }
        }
    }

    /** @param array{mutable:array<string,mixed>,protected:array<string,mixed>} $actual */
    private function assertBefore(array $target, array $actual): void
    {
        $expected = $target['current'];
        // Draft pointers and immutable revision statuses may change during the
        // non-public import phase. Public/editorial fields and every protected
        // boundary must remain exact until publication begins.
        foreach ([['mutable', 'working_revision_id'], ['mutable', 'revision_statuses'], ['mutable', 'article', 'working_revision_id']] as $path) {
            data_forget($actual, implode('.', $path));
            data_forget($expected, implode('.', $path));
        }
        if (! hash_equals(PromotionContextFactory::canonicalJson($expected), PromotionContextFactory::canonicalJson($actual))) {
            throw new DomainException('top100_frozen_before_snapshot_drift');
        }
    }

    /** @param array<string,mixed> $row */
    private function restore(array $row): void
    {
        $kind = (string) ($row['model_kind'] ?? '');
        $id = (int) ($row['model_id'] ?? 0);
        $before = is_array($row['before'] ?? null) ? $row['before'] : [];
        $mutable = is_array($before['mutable'] ?? null) ? $before['mutable'] : [];
        match ($kind) {
            'personality_asset' => $this->restorePersonality($id, $mutable, $row),
            'mbti_profile' => $this->restoreMbti($id, false, $mutable),
            'mbti_variant' => $this->restoreMbti($id, true, $mutable),
            'mbti_comparison' => $this->restoreComparison($id, $mutable),
            'article' => $this->restoreArticle($id, $mutable, $row),
            'test_landing' => $this->restoreSimple(LandingSurface::class, $id, $mutable),
            default => throw new DomainException('top100_frozen_rollback_kind_invalid'),
        };
    }

    private function restorePersonality(int $id, array $mutable, array $row): void
    {
        $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
        if (! $asset instanceof PersonalityPublicContentAsset) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $asset->forceFill($mutable)->saveQuietly();
        $assetKey = 'seo-top100-frozen:'.str_pad((string) ((int) ($row['priority'] ?? 0)), 3, '0', STR_PAD_LEFT).':'.(string) ($row['url_sha256'] ?? '');
        $packageRevision = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', (string) ($row['package_sha256'] ?? ''))
            ->where('authority_asset_key', $assetKey)
            ->first();
        if ($packageRevision instanceof PersonalityPublicContentAssetRevision
            && (int) ($mutable['published_revision_id'] ?? 0) !== (int) $packageRevision->id) {
            $packageRevision->forceFill(['workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT])->save();
        }
    }

    private function restoreMbti(int $id, bool $variant, array $mutable): void
    {
        $model = $variant
            ? PersonalityProfileVariant::query()->withoutGlobalScopes()->lockForUpdate()->find($id)
            : PersonalityProfile::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
        if (! $model instanceof Model) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $model->forceFill((array) ($mutable['content'] ?? []))->saveQuietly();
        $seo = $variant
            ? PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->where('personality_profile_variant_id', $id)->lockForUpdate()->first()
            : PersonalityProfileSeoMeta::query()->withoutGlobalScopes()->where('profile_id', $id)->lockForUpdate()->first();
        if (! $seo instanceof Model) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $seo->forceFill((array) ($mutable['seo'] ?? []))->saveQuietly();
        $this->writeMbtiLinks(['kind' => $variant ? 'mbti_variant' : 'mbti_profile', 'model' => $model], (array) ($mutable['links'] ?? []));
    }

    private function restoreComparison(int $profileId, array $mutable): void
    {
        $section = PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profileId)
            ->where('section_key', 'mbti64_comparison_a_vs_t')->lockForUpdate()->first();
        if (! $section instanceof PersonalityProfileSection) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $section->forceFill($mutable)->saveQuietly();
    }

    private function restoreArticle(int $id, array $mutable, array $row): void
    {
        $article = Article::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $id)->lockForUpdate()->first();
        if (! $article instanceof Article || ! $seo instanceof ArticleSeoMeta) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $articleState = (array) ($mutable['article'] ?? []);
        $articleState['published_at'] = data_get($row, 'before.protected.published_at');
        $article->forceFill($articleState)->saveQuietly();
        $seo->forceFill((array) ($mutable['seo'] ?? []))->saveQuietly();
        foreach ((array) ($mutable['revision_statuses'] ?? []) as $revisionId => $status) {
            ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $id)->whereKey((int) $revisionId)
                ->update(['revision_status' => (string) $status]);
        }
        $assetKey = 'seo-top100-frozen:'.str_pad((string) ((int) ($row['priority'] ?? 0)), 3, '0', STR_PAD_LEFT).':'.(string) ($row['url_sha256'] ?? '');
        $packageRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('article_id', $id)
            ->where('authority_package_sha256', (string) ($row['package_sha256'] ?? ''))
            ->where('authority_asset_key', $assetKey)
            ->first();
        if ($packageRevision instanceof ArticleTranslationRevision
            && ! array_key_exists((string) $packageRevision->id, (array) ($mutable['revision_statuses'] ?? []))
            && ! array_key_exists($packageRevision->id, (array) ($mutable['revision_statuses'] ?? []))) {
            $packageRevision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'published_at' => null,
            ])->save();
        }
    }

    /** @param class-string<Model> $model */
    private function restoreSimple(string $model, int $id, array $mutable): void
    {
        $record = $model::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
        if (! $record instanceof Model) {
            throw new DomainException('top100_frozen_rollback_target_missing');
        }
        $record->forceFill($mutable)->saveQuietly();
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private function attributes(Model $model, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $model->getAttribute($field);
        }

        return $values;
    }

    /** @return list<array<string,mixed>> */
    private function mbtiLinksState(array $resolved): array
    {
        $section = $this->mbtiLinkSection($resolved, (bool) ($resolved['lock_links'] ?? false));
        if (! $section instanceof Model) {
            return [];
        }
        $payload = is_array($section->getAttribute('payload_json')) ? $section->getAttribute('payload_json') : [];

        return array_values((array) ($payload['internal_links'] ?? $payload['items'] ?? []));
    }

    /** @param list<array<string,mixed>> $current @param list<array<string,mixed>> $additions @return list<array<string,mixed>> */
    private function mergeLinks(array $current, array $additions): array
    {
        if ($additions === []) {
            return array_values($current);
        }
        $merged = [];
        foreach ([...$current, ...$additions] as $link) {
            if (! is_array($link)) {
                continue;
            }
            $identity = (string) ($link['href'] ?? $link['url'] ?? $link['path'] ?? PromotionContextFactory::canonicalJson($link));
            $merged[$identity] = $link;
        }

        return array_values($merged);
    }

    private function writeMbtiLinks(array $resolved, array $links): void
    {
        $section = $this->mbtiLinkSection($resolved, true);
        if (! $section instanceof Model) {
            if ($links === []) {
                return;
            }
            throw new DomainException('top100_frozen_mbti_internal_link_section_missing');
        }
        $payload = is_array($section->getAttribute('payload_json')) ? $section->getAttribute('payload_json') : [];
        $key = array_key_exists('internal_links', $payload) ? 'internal_links' : 'items';
        $payload[$key] = array_values($links);
        $section->forceFill(['payload_json' => $payload])->saveQuietly();
    }

    private function mbtiLinkSection(array $resolved, bool $lock): ?Model
    {
        if ($resolved['kind'] === 'mbti_variant') {
            $query = PersonalityProfileVariantSection::query()->withoutGlobalScopes()
                ->where('personality_profile_variant_id', $resolved['model']->getKey())
                ->where('section_key', 'mbti_content15_internal_links');
        } else {
            $query = PersonalityProfileSection::query()->withoutGlobalScopes()
                ->where('profile_id', $resolved['model']->getKey())
                ->where('section_key', 'related_content');
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replaceFirstParagraph(string $markdown, string $intro): string
    {
        $trimmed = ltrim($markdown);
        if ($trimmed === '') {
            return $intro;
        }
        $offset = strpos($trimmed, "\n\n");

        return $offset === false ? $intro : $intro.substr($trimmed, $offset);
    }

    /** @param list<array<string,mixed>> $links */
    private function appendMarkdownLinks(string $markdown, array $links): string
    {
        if ($links === []) {
            return $markdown;
        }
        $rows = [];
        foreach ($links as $link) {
            $label = trim((string) ($link['anchor_text'] ?? $link['label'] ?? $link['title'] ?? ''));
            $href = trim((string) ($link['href'] ?? $link['url'] ?? $link['path'] ?? ''));
            if ($label === '' || preg_match('#\A/(?:en|zh)/#', $href) !== 1) {
                throw new DomainException('top100_frozen_article_internal_link_invalid');
            }
            $row = '- ['.$label.']('.$href.')';
            if (! str_contains($markdown, $row)) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return $markdown;
        }

        return rtrim($markdown)."\n\n".implode("\n", $rows)."\n";
    }

    private function assetKey(array $target): string
    {
        return 'seo-top100-frozen:'.str_pad((string) $target['priority'], 3, '0', STR_PAD_LEFT).':'.hash('sha256', $target['url']);
    }

    private function ownerActor(): int
    {
        $actor = (int) config('review_governance.solo_owner_admin_user_id');
        if (! $this->reviews->isConfiguredSoloOwner($actor)) {
            throw new DomainException('top100_frozen_solo_owner_not_configured');
        }

        return $actor;
    }

    private function invalidate(PromotionContext $context, bool $rollback = false): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->performInvalidation($context, $rollback));

            return;
        }
        $this->performInvalidation($context, $rollback);
    }

    private function performInvalidation(PromotionContext $context, bool $rollback): void
    {
        $package = $this->inspect($context);
        foreach ($package['targets'] as $target) {
            if ($target['model_kind'] === 'personality_asset') {
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->find($target['model_id']);
                if ($asset instanceof PersonalityPublicContentAsset) {
                    $assetInvalidated = $this->personalityAssetCache->invalidateAsset(
                        $asset->framework,
                        $asset->entity_type,
                        $asset->entity_key,
                        $asset->slug,
                        $asset->locale,
                        (int) $asset->org_id,
                        ! $rollback,
                    );
                    $collectionsInvalidated = $this->personalityAssetCache->invalidateCollections(
                        $asset->framework,
                        $asset->entity_type,
                        $asset->locale,
                        (int) $asset->org_id,
                        ! $rollback,
                    );
                    if (! $assetInvalidated || ! $collectionsInvalidated) {
                        throw new DomainException('top100_frozen_personality_cache_invalidation_failed');
                    }
                }
            }
            if (in_array($target['model_kind'], ['mbti_profile', 'mbti_variant'], true)) {
                if (! $this->mbtiCache->forgetType(strtoupper((string) $target['slug']), (string) $target['locale'], 0, 'MBTI')) {
                    throw new DomainException('top100_frozen_mbti_cache_invalidation_failed');
                }
            }
        }
        if (collect($package['targets'])->contains(static fn (array $target): bool => $target['model_kind'] === 'article')) {
            // This exact-package lane must fail closed if the public list generation
            // cannot advance; preserving the old generation would expose stale titles.
            $this->discoverabilityCache->flushArticleDiscoverabilityCaches(false);
        }
    }

    private function assertPublicApiReadback(array $target): void
    {
        $locale = rawurlencode((string) $target['locale']);
        $path = match ($target['family']) {
            'article' => '/api/v0.5/articles/'.rawurlencode((string) $target['slug']).'?locale='.$locale,
            'enneagram_wing' => '/api/v0.5/personality-content-assets/enneagram/'.rawurlencode((string) $target['slug']).'?locale='.$locale,
            'big_five' => '/api/v0.5/personality-content-assets/big-five/'.rawurlencode((string) $target['slug']).'?locale='.$locale,
            'mbti_profile' => '/api/v0.5/personality/'.rawurlencode((string) $target['slug']).'?locale='.$locale.'&scale_code=MBTI',
            'mbti_comparison' => '/api/v0.5/personality/comparisons/'.rawurlencode((string) $target['slug']).'?locale='.$locale.'&scale_code=MBTI',
            'test_landing' => '/api/v0.5/landing-surfaces/test_detail_'.str_replace('-', '_', (string) $target['slug']).'?locale='.$locale,
            default => throw new DomainException('top100_frozen_public_api_family_invalid'),
        };
        $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->httpKernel->handle($request);
        try {
            if ($response->getStatusCode() !== 200) {
                throw new DomainException('top100_frozen_public_api_status_invalid');
            }
            $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $projected = $this->publicScalarValues($body);
            foreach ($this->expectedPublicValues($target) as $value) {
                if (! $this->publicValueProjected($value, $projected)) {
                    throw new DomainException('top100_frozen_public_api_projection_invalid');
                }
            }
            foreach ($target['internal_links'] as $link) {
                if (! $this->publicLinkProjected($body, (string) $link['anchor_text'], (string) $link['href'])) {
                    throw new DomainException('top100_frozen_public_api_link_projection_invalid');
                }
            }
        } finally {
            $this->httpKernel->terminate($request, $response);
        }
    }

    private function assertLiveHtmlReadback(array $target): void
    {
        $response = Http::accept('text/html')->timeout(15)->retry(2, 250)->get((string) $target['url']);
        if (! $response->successful()) {
            throw new DomainException('top100_frozen_live_html_status_invalid');
        }
        $body = html_entity_decode((string) $response->body(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($this->expectedPublicValues($target) as $value) {
            if (! str_contains($body, (string) $value)) {
                throw new DomainException('top100_frozen_live_html_projection_invalid');
            }
        }
        foreach ($target['internal_links'] as $link) {
            if (! $this->liveHtmlLinkProjected($body, (string) $link['anchor_text'], (string) $link['href'])) {
                throw new DomainException('top100_frozen_live_html_link_projection_invalid');
            }
        }
        if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']'.preg_quote((string) $target['url'], '~').'["\']~i', $body) !== 1
            && preg_match('~<link[^>]+href=["\']'.preg_quote((string) $target['url'], '~').'["\'][^>]+rel=["\']canonical["\']~i', $body) !== 1) {
            throw new DomainException('top100_frozen_live_html_canonical_invalid');
        }
    }

    /** @return list<string> */
    private function expectedPublicValues(array $target): array
    {
        $values = array_values(array_filter([
            $target['patch']['seo_title'],
            $target['patch']['seo_description'],
            $target['patch']['h1'],
            $target['patch']['intro'],
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return array_values(array_unique($values));
    }

    private function publicLinkProjected(mixed $value, string $anchor, string $href): bool
    {
        if (is_string($value)) {
            return str_contains($value, '['.$anchor.']('.$href.')')
                || $this->liveHtmlLinkProjected(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $anchor, $href);
        }
        if (! is_array($value)) {
            return false;
        }

        $directScalars = array_values(array_filter($value, 'is_string'));
        if (in_array($anchor, $directScalars, true) && in_array($href, $directScalars, true)) {
            return true;
        }
        foreach ($value as $child) {
            if ($this->publicLinkProjected($child, $anchor, $href)) {
                return true;
            }
        }

        return false;
    }

    private function liveHtmlLinkProjected(string $html, string $anchor, string $href): bool
    {
        if (preg_match_all("~<a\\b[^>]*\\bhref=[\"']".preg_quote($href, '~')."[\"'][^>]*>(.*?)</a>~is", $html, $matches) < 1) {
            return false;
        }
        foreach ($matches[1] as $innerHtml) {
            if (trim(html_entity_decode(strip_tags((string) $innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $anchor) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function publicScalarValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        $values = [];
        foreach ($value as $child) {
            array_push($values, ...$this->publicScalarValues($child));
        }

        return array_values(array_unique($values));
    }

    /** @param list<string> $projected */
    private function publicValueProjected(string $expected, array $projected): bool
    {
        return collect($projected)->contains(
            static fn (string $value): bool => $value === $expected || str_contains($value, $expected),
        );
    }
}
