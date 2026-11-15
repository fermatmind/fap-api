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
        return $this->readBatch([$row], $lock)[0];
    }

    public function readBatch(array $rows, bool $lock = false): array
    {
        $members = $this->members($rows, $lock);
        $states = [];
        foreach ($rows as $key => $row) {
            $states[$key] = $this->memberState($row, $members[$key]);
        }

        return $states;
    }

    private function memberState(array $row, array $members): array
    {
        $state = [];
        foreach ($members as $locale => [$article, $revision, $seo, $canonical]) {
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
                'canonical' => $canonical,
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
        $this->activateBatch([$row], [$before]);
    }

    public function activateBatch(array $rows, array $before): void
    {
        $this->assertTransaction();
        $current = $this->readBatch($rows, true);
        if (! Hash::sameValue($current, $before)) {
            throw new DomainException('traditional_article_index_concurrent_state');
        }
        foreach ($rows as $key => $row) {
            $this->assertHeld($row, $before[$key]);
            $this->activateMembers($row, $before[$key]);
        }
    }

    private function activateMembers(array $row, array $before): void
    {
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
        $this->assertActiveBatch([$row], [$before]);
    }

    public function assertActiveBatch(array $rows, array $before): void
    {
        $members = $this->members($rows, DB::transactionLevel() > 0);
        foreach ($rows as $key => $row) {
            $this->assertActiveMembers($row, $before[$key], $members[$key]);
        }
    }

    private function assertActiveMembers(array $row, array $before, array $members): void
    {
        $after = $this->memberState($row, $members);
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
            [$article, $revision] = $members[$locale];
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

    private function members(array $rows, bool $lock): array
    {
        $groups = array_map(static fn (array $row): array => [$row['target'], ...$row['peers']], $rows);
        $identities = array_merge(...array_values($groups));
        $articleIds = array_column($identities, 'id');
        $articles = Article::withoutGlobalScopes()->where('org_id', 0)->whereKey($articleIds)
            ->whereNull('deleted_at')->publiclyReadable()->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->where('org_id', 0)
            ->whereKey(array_column($identities, 'published_revision_id'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $seoByArticle = ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
            ->whereIn('article_id', $articleIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->groupBy('article_id');

        $members = [];
        foreach ($groups as $key => $identities) {
            foreach ($identities as $identity) {
                $locale = $identity['locale'];
                if (isset($members[$key][$locale])) {
                    throw new DomainException('traditional_article_index_locale_collision');
                }
                $article = $articles->get($identity['id']);
                if (! $article instanceof Article || $article->locale !== $locale || $article->slug !== $identity['slug']
                    || (int) $article->published_revision_id !== $identity['published_revision_id']
                    || $article->translation_group_id !== $identity['translation_group_id']
                    || $this->counterparts->canonical($article) !== $identity['canonical']) {
                    throw new DomainException('traditional_article_index_identity_drift');
                }
                $revision = $revisions->get($identity['published_revision_id']);
                $seoRows = $seoByArticle->get($article->id, collect())->whereStrict('locale', $locale);
                $seo = $seoRows->first();
                $stage = $identity['staging_control'] ?? null;
                $seoMatches = $stage === null
                    ? in_array($seo?->canonical_url, [$identity['canonical'], parse_url($identity['canonical'], PHP_URL_PATH)], true)
                    : PublicContent53Package::environment() === 'staging' && ($seo instanceof ArticleSeoMeta) === $stage['has_seo']
                        && $seo?->canonical_url === $stage['canonical_url'] && $seo?->robots === $stage['robots'];
                if (! $revision instanceof ArticleTranslationRevision || (int) $revision->article_id !== (int) $article->id
                    || $revision->locale !== $locale || $seoRows->count() > 1
                    || ! $revision->isPubliclyReadableForArticle($article) || ! $seoMatches) {
                    throw new DomainException('traditional_article_index_revision_drift');
                }
                $article->setRelation('publishedRevision', $revision)->setRelation('seoMeta', $seo);
                $members[$key][$locale] = [$article, $revision, $seo, $identity['canonical']];
            }
        }

        return $members;
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
