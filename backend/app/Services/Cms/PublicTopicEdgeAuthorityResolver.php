<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ContentPage;
use App\Models\PersonalityProfile;
use App\Models\PublicTopicEdge;
use App\Models\TopicProfile;
use App\Support\CanonicalFrontendUrl;
use Illuminate\Database\Eloquent\Model;

final class PublicTopicEdgeAuthorityResolver
{
    /**
     * @return array{type:string,id:int,locale:string,canonical:string}|null
     */
    public function resolve(string $type, int $id, string $locale, int $orgId = 0): ?array
    {
        if (! in_array($type, PublicTopicEdge::PUBLIC_ENTITY_TYPES, true) || $id <= 0) {
            return null;
        }

        $entity = match ($type) {
            'article' => $this->article($id, $locale, $orgId),
            'content_page' => $this->contentPage($id, $locale, $orgId),
            'personality_profile' => $this->personalityProfile($id, $locale, $orgId),
            'topic' => $this->topic($id, $locale, $orgId),
        };

        if (! $entity instanceof Model) {
            return null;
        }

        $canonical = $this->canonicalFor($type, $entity);
        if ($canonical === null) {
            return null;
        }

        return [
            'type' => $type,
            'id' => (int) $entity->getKey(),
            'locale' => (string) $entity->getAttribute('locale'),
            'canonical' => $canonical,
        ];
    }

    private function topic(int $id, string $locale, int $orgId): ?TopicProfile
    {
        return TopicProfile::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->whereKey($id)
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->where('status', TopicProfile::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->where(static fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->first();
    }

    private function personalityProfile(int $id, string $locale, int $orgId): ?PersonalityProfile
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->whereKey($id)
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->where(static fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->first();
    }

    private function article(int $id, string $locale, int $orgId): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->publiclyReadable()
            ->whereKey($id)
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->where('is_indexable', true)
            ->whereHas('seoMeta', static fn ($query) => $query->where('is_indexable', true))
            ->first();
    }

    private function contentPage(int $id, string $locale, int $orgId): ?ContentPage
    {
        return ContentPage::query()
            ->withoutGlobalScopes()
            ->publiclyIndexable()
            ->whereKey($id)
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->first();
    }

    private function canonicalFor(string $type, Model $entity): ?string
    {
        if (in_array($type, ['article', 'personality_profile', 'topic'], true)) {
            $seoMeta = $entity->getRelation('seoMeta');
            $robots = strtolower(trim((string) ($seoMeta?->robots ?? '')));
            if (str_contains($robots, 'noindex')) {
                return null;
            }

            $raw = $seoMeta?->canonical_url;
        } else {
            $raw = $entity->getAttribute('canonical_path');
        }

        return $this->normalizePublicCanonical(is_string($raw) ? $raw : null);
    }

    private function normalizePublicCanonical(?string $value): ?string
    {
        $canonical = trim((string) $value);
        if ($canonical === '' || str_contains($canonical, '?') || str_contains($canonical, '#')) {
            return null;
        }

        if (str_starts_with($canonical, '/')) {
            if (str_starts_with($canonical, '//')) {
                return null;
            }

            $canonical = CanonicalFrontendUrl::APEX_URL.$canonical;
        }

        $canonical = CanonicalFrontendUrl::normalizeAbsoluteUrl($canonical);
        if ($canonical === null || ($canonical !== CanonicalFrontendUrl::APEX_URL && ! str_starts_with($canonical, CanonicalFrontendUrl::APEX_URL.'/'))) {
            return null;
        }

        $path = rawurldecode((string) parse_url($canonical, PHP_URL_PATH));
        if ($path === '' || preg_match('~/(?:take|result|results|report|reports|order|orders|payment|payments|checkout|share)(?:/|$)~i', $path) === 1) {
            return null;
        }

        return rtrim($canonical, '/');
    }
}
