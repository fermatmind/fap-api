<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleReviewedCounterpartService;
use App\Services\Cms\ArticleSeoService;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** @review-surface article */
final class TraditionalArticleIndexRelease
{
    private const INDEX_FIELDS = ['is_indexable', 'sitemap_eligible', 'llms_eligible'];

    private const SEO_FIELDS = ['is_indexable', 'robots', 'schema_json'];

    public function __construct(
        private readonly ArticleBodyHeadingGuard $bodyGuard,
        private readonly ArticleReviewedCounterpartService $counterparts,
        private readonly ArticleSeoService $seo,
    ) {}

    /** The package parser supplies one frozen row per Traditional article and its exact language peers. */
    public function read(array $row, bool $lock = false): array
    {
        $state = [];
        foreach ([$row['target'], ...$row['peers']] as $identity) {
            [$article, $revision, $seo] = $this->member($identity, $lock);
            $locale = $identity['locale'];
            if (isset($state[$locale])) {
                throw new DomainException('traditional_article_index_locale_collision');
            }
            if ($locale === 'zh-TW'
                && ($article->source_article_id !== $row['source_id']
                    || $article->source_locale !== $row['source_locale']
                    || $revision->source_article_id !== $row['source_id']
                    || $revision->source_locale !== $row['source_locale']
                    || $revision->translation_group_id !== $article->translation_group_id
                    || $revision->authority_source_package !== PublicContent53Package::PACKAGE
                    || data_get($revision->authority_metadata_json, 'approval_kind') !== 'verified_automated_exact_package'
                    || ! hash_equals($row['body_sha256'], hash('sha256', $this->bodyGuard->downgradeMarkdownH1ToH2((string) $revision->content_md)))
                    || ! hash_equals($row['title_sha256'], hash('sha256', (string) $revision->title)))) {
                throw new DomainException('traditional_article_index_reviewed_body_drift');
            }
            $state[$locale] = [
                'id' => (int) $article->id,
                'locale' => $locale,
                'seo_id' => $seo?->id,
                'canonical' => $identity['canonical'],
                'protected_sha256' => Hash::hash([
                    Arr::except($article->getAttributes(), self::INDEX_FIELDS),
                    $revision->getAttributes(),
                    Arr::except($seo?->getAttributes() ?? [], self::SEO_FIELDS),
                ]),
                'article' => $article->only(self::INDEX_FIELDS),
                'seo' => $seo?->only(self::SEO_FIELDS),
            ];
        }

        return $state;
    }

    public function assertHeld(array $row, array $state): void
    {
        $target = $state['zh-TW'] ?? [];
        $source = $state[$row['source_locale']] ?? [];
        if (($source['id'] ?? null) !== $row['source_id']
            || ($target['article'] ?? null) !== array_fill_keys(self::INDEX_FIELDS, false)
            || ($target['seo']['is_indexable'] ?? null) !== false
            || ($target['seo']['robots'] ?? null) !== 'noindex,follow') {
            throw new DomainException('traditional_article_index_hold_drift');
        }
        foreach ($row['peers'] as $peer) {
            $saved = $state[$peer['locale']]['seo'];
            $gate = data_get($saved, 'schema_json.editorial_package_v1.hreflang_gate_v1');
            $publicGate = is_array($gate) ? Arr::only($gate, ['enabled', 'policy', 'reason']) : null;
            $stageControl = $peer['staging_control'] ?? null;
            $eligible = $stageControl === null
                ? $state[$peer['locale']]['article']['is_indexable'] === true && ($saved['is_indexable'] ?? null) === true
                    && preg_match('/(?:^|[,\s])(?:noindex|none)(?:$|[,\s])/i', (string) ($saved['robots'] ?? '')) !== 1
                : $state[$peer['locale']]['article']['is_indexable'] === $stageControl['indexable'];
            if (! $eligible || ($stageControl === null && ! Hash::sameValue($publicGate, $peer['hreflang_gate']))) {
                throw new DomainException('traditional_article_index_peer_drift');
            }
        }
    }

    public function activate(array $row, array $before): void
    {
        $this->assertTransaction();
        if (! Hash::sameValue($this->read($row, true), $before)) {
            throw new DomainException('traditional_article_index_concurrent_state');
        }
        $this->assertHeld($row, $before);
        $target = $before['zh-TW'];
        $target['article'] = ['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => $row['llms_eligible']];
        $target['seo']['is_indexable'] = true;
        $target['seo']['robots'] = 'index,follow';
        $this->write($this->enableHreflang($target), true);
        foreach ($row['peers'] as $peer) {
            $member = $before[$peer['locale']];
            if (! isset($peer['staging_control']) && data_get($member['seo']['schema_json'], 'editorial_package_v1.hreflang_gate_v1.enabled') !== true) {
                $this->write($this->enableHreflang($member), false);
            }
        }
    }

    public function assertActive(array $row, array $before): void
    {
        $after = $this->read($row, DB::transactionLevel() > 0);
        $identities = [$row['target'], ...$row['peers']];
        $stageOverrides = array_filter($row['peers'], static fn (array $peer): bool => isset($peer['staging_control']));
        $expected = [];
        foreach ($identities as $identity) {
            if (($identity['staging_control']['indexable'] ?? true) === true) {
                $expected[$identity['locale']] = $this->seo->buildCanonicalUrl($identity['slug'], $identity['locale']);
            }
        }
        foreach ($identities as $identity) {
            $locale = $identity['locale'];
            [$article, $revision] = $this->member($identity, false);
            $projection = $this->seo->buildSeoPayload($article, $revision);
            $actual = (array) data_get($projection, 'article_authority_v1.alternate_eligibility.alternates', []);
            $eligible = isset($identity['staging_control'])
                ? Hash::sameValue($after[$locale], $before[$locale])
                : $this->counterparts->eligible($article, includeTraditional: true)
                    && data_get($article->seoMeta?->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled') === true;
            $alternatesMatch = $stageOverrides === [] ? Hash::sameValue($actual, $expected) : array_diff_assoc($actual, $expected) === [];
            if ($after[$locale]['protected_sha256'] !== $before[$locale]['protected_sha256'] || ! $eligible || ! $alternatesMatch) {
                throw new DomainException('traditional_article_index_reciprocal_readback_failed');
            }
        }
        if (! Hash::sameValue($after['zh-TW']['article'], [
            'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => $row['llms_eligible'],
        ]) || $after['zh-TW']['seo']['robots'] !== 'index,follow') {
            throw new DomainException('traditional_article_index_flags_readback_failed');
        }
    }

    public function restore(array $row, array $before, array $after): void
    {
        $this->assertTransaction();
        $current = $this->read($row, true);
        if (Hash::sameValue($current, $before)) {
            return;
        }
        if (! Hash::sameValue($current, $after)) {
            throw new DomainException('traditional_article_index_rollback_concurrent_state');
        }
        foreach ($before as $locale => $member) {
            if (! Hash::sameValue($member, $after[$locale])) {
                $this->write($member, $locale === 'zh-TW');
            }
        }
        if (! Hash::sameValue($this->read($row, true), $before)) {
            throw new DomainException('traditional_article_index_rollback_readback_failed');
        }
    }

    private function member(array $identity, bool $lock): array
    {
        $article = Article::withoutGlobalScopes()->where('org_id', 0)->whereKey($identity['id'])
            ->where('locale', $identity['locale'])->where('slug', $identity['slug'])->whereNull('deleted_at')->publiclyReadable()
            ->where('published_revision_id', $identity['published_revision_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $article instanceof Article || $article->translation_group_id !== $identity['translation_group_id']
            || $this->counterparts->canonical($article) !== $identity['canonical']) {
            throw new DomainException('traditional_article_index_identity_drift');
        }
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('locale', $article->locale)->whereKey($article->published_revision_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $seoRows = ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('locale', $article->locale)
            ->when($lock, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        $seo = $seoRows->first();
        $stage = $identity['staging_control'] ?? null;
        $seoMatches = $stage === null
            ? in_array($seo?->canonical_url, [$identity['canonical'], parse_url($identity['canonical'], PHP_URL_PATH)], true)
            : PublicContent53Package::environment() === 'staging' && ($seo instanceof ArticleSeoMeta) === $stage['has_seo']
                && $seo?->canonical_url === $stage['canonical_url'] && $seo?->robots === $stage['robots'];
        if (! $revision instanceof ArticleTranslationRevision || $seoRows->count() > 1
            || ! $revision->isPubliclyReadableForArticle($article) || ! $seoMatches) {
            throw new DomainException('traditional_article_index_revision_drift');
        }
        $article->setRelation('publishedRevision', $revision)->setRelation('seoMeta', $seo);

        return [$article, $revision, $seo];
    }

    private function enableHreflang(array $member): array
    {
        $schema = $member['seo']['schema_json'] ?? [];
        Arr::forget($schema, ['editorial_package_v1.hreflang_hold', 'editorial_package_v1.hreflang_gate_v1.reason']);
        data_set($schema, 'editorial_package_v1.hreflang_gate_v1.enabled', true);
        data_set($schema, 'editorial_package_v1.hreflang_gate_v1.policy', 'reciprocal_counterparts_verified');
        $member['seo']['schema_json'] = $schema;

        return $member;
    }

    private function write(array $member, bool $updateArticle): void
    {
        if ($updateArticle) {
            Article::withoutGlobalScopes()->where('org_id', 0)->whereKey($member['id'])->toBase()->update($member['article']);
        }
        $seo = new ArticleSeoMeta;
        $seo->forceFill($member['seo']);
        ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)->whereKey($member['seo_id'])->toBase()->update($seo->getAttributes());
        event(new PublicAuthorityChanged('article', (string) $member['id'], $member['locale'],
            Hash::hash($member), 'authority_revision'));
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('traditional_article_index_transaction_required');
        }
    }
}
