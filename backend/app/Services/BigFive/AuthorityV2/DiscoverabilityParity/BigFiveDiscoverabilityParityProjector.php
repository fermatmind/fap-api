<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\DiscoverabilityParity;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Support\CanonicalFrontendUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class BigFiveDiscoverabilityParityProjector
{
    public const CONTRACT_VERSION = 'big5-discoverability-parity.v1';

    /**
     * Project hreflang and llms.txt eligibility without mutating Article, sitemap,
     * search, cache, or public runtime state.
     *
     * @return array<string, mixed>
     */
    public function forArticle(
        Article $article,
        ?ArticleTranslationRevision $revision = null,
        ?Article $counterpart = null,
        ?ArticleTranslationRevision $counterpartRevision = null,
    ): array {
        $currentPublicAuthority = $this->hasPublishedIndexableAuthority($article, $revision);
        $reciprocalCounterpartAuthority = $currentPublicAuthority
            && $counterpart instanceof Article
            && $this->isReciprocalCounterpart($article, $counterpart)
            && $this->hasPublishedIndexableAuthority($counterpart, $counterpartRevision);
        $canonicalBaseUrl = $this->canonicalBaseUrl();
        $reciprocalCounterpart = $reciprocalCounterpartAuthority && $canonicalBaseUrl !== null;

        $hreflangPolicy = ! $currentPublicAuthority
            ? 'withheld'
            : ($reciprocalCounterpart
                ? 'reciprocal_bilingual_counterparts'
                : ($reciprocalCounterpartAuthority ? 'withheld' : 'no_hreflang'));
        $alternates = $reciprocalCounterpart
            ? $this->alternates($article, $counterpart, $canonicalBaseUrl)
            : [];
        $llmsEligible = $currentPublicAuthority && (bool) $article->llms_eligible;

        $hreflangBlocked = [];
        if (! $currentPublicAuthority) {
            $hreflangBlocked[] = 'current_published_indexable_public_authority_missing';
        } elseif ($reciprocalCounterpartAuthority && $canonicalBaseUrl === null) {
            $hreflangBlocked[] = 'canonical_public_base_url_missing';
        } elseif (! $reciprocalCounterpartAuthority) {
            $hreflangBlocked[] = 'reciprocal_published_counterpart_missing';
        }

        $llmsBlocked = [];
        if (! $currentPublicAuthority) {
            $llmsBlocked[] = 'current_published_indexable_public_authority_missing';
        }
        if (! (bool) $article->llms_eligible) {
            $llmsBlocked[] = 'backend_llms_eligibility_disabled';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authority_surface' => 'Article',
            'identity' => 'article:'.(int) $article->id.':'.(string) $article->locale.':'.(string) $article->slug,
            'current_public_authority_eligible' => $currentPublicAuthority,
            'hreflang' => [
                'policy' => $hreflangPolicy,
                'policy_valid' => $currentPublicAuthority,
                'output_eligible' => $reciprocalCounterpart,
                'counterpart_authority_eligible' => $reciprocalCounterpart,
                'alternates' => $alternates,
                'blocked_reasons' => $hreflangBlocked,
            ],
            'llms' => [
                'basis' => 'backend_published_indexable_public_safe_and_explicit_llms_flag',
                'membership_eligible' => $llmsEligible,
                'explicit_backend_eligibility' => (bool) $article->llms_eligible,
                'blocked_reasons' => array_values(array_unique($llmsBlocked)),
            ],
            'preservation' => [
                'read_only_projection' => true,
                'sitemap_behavior_mutated' => false,
                'search_submission_performed' => false,
                'draft_discoverability_expanded' => false,
            ],
        ];
    }

    private function hasPublishedIndexableAuthority(
        Article $article,
        ?ArticleTranslationRevision $revision,
    ): bool {
        return (int) $article->id > 0
            && trim((string) $article->slug) !== ''
            && $this->normalizeLocale((string) $article->locale) !== null
            && $article->status === 'published'
            && (bool) $article->is_public
            && (bool) $article->is_indexable
            && ! $article->trashed()
            && ! in_array((string) $article->lifecycle_state, [
                Article::LIFECYCLE_ARCHIVED,
                Article::LIFECYCLE_SOFT_DELETED,
            ], true)
            && $revision instanceof ArticleTranslationRevision
            && (int) $revision->article_id === (int) $article->id
            && (int) $revision->org_id === (int) $article->org_id
            && (string) $revision->locale === (string) $article->locale
            && $article->published_revision_id !== null
            && (int) $revision->id === (int) $article->published_revision_id
            && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED
            && $this->publicationIsNullOrEffective($revision->published_at);
    }

    private function isReciprocalCounterpart(Article $article, Article $counterpart): bool
    {
        $group = trim((string) $article->translation_group_id);
        $counterpartGroup = trim((string) $counterpart->translation_group_id);
        $articleLocale = $this->normalizeLocale((string) $article->locale);
        $counterpartLocale = $this->normalizeLocale((string) $counterpart->locale);
        if ($articleLocale === null || $counterpartLocale === null) {
            return false;
        }
        $locales = [$articleLocale, $counterpartLocale];
        sort($locales);

        return (int) $article->id !== (int) $counterpart->id
            && (int) $article->org_id === (int) $counterpart->org_id
            && $group !== ''
            && hash_equals($group, $counterpartGroup)
            && $locales === ['en', 'zh-CN'];
    }

    /** @return array<string, string> */
    private function alternates(Article $article, Article $counterpart, string $canonicalBaseUrl): array
    {
        $articleLocale = $this->normalizeLocale((string) $article->locale);
        $counterpartLocale = $this->normalizeLocale((string) $counterpart->locale);
        if ($articleLocale === null || $counterpartLocale === null) {
            return [];
        }
        $byLocale = [
            $articleLocale => $article,
            $counterpartLocale => $counterpart,
        ];
        $en = $this->canonicalUrl($byLocale['en'], $canonicalBaseUrl);
        $zh = $this->canonicalUrl($byLocale['zh-CN'], $canonicalBaseUrl);

        return [
            'en' => $en,
            'zh-CN' => $zh,
            'x-default' => $en,
        ];
    }

    private function canonicalUrl(Article $article, string $canonicalBaseUrl): string
    {
        $segment = $this->normalizeLocale((string) $article->locale) === 'zh-CN' ? 'zh' : 'en';

        return $canonicalBaseUrl.'/'.$segment.'/articles/'.rawurlencode(trim((string) $article->slug));
    }

    private function canonicalBaseUrl(): ?string
    {
        $configured = CanonicalFrontendUrl::fromConfig();
        $parts = parse_url($configured);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            return null;
        }

        return $configured;
    }

    private function normalizeLocale(string $locale): ?string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            'zh', 'zh-cn', 'zh_hans' => 'zh-CN',
            default => null,
        };
    }

    private function publicationIsNullOrEffective(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);

            return $date->utc()->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
        } catch (Throwable) {
            return false;
        }
    }
}
