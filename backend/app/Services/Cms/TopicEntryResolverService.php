<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\DB;

final class TopicEntryResolverService
{
    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly TopicProfileSeoService $topicProfileSeoService,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function resolveGroupedEntries(TopicProfile $profile, string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $grouped = [];

        foreach (TopicProfileEntry::GROUP_KEYS as $groupKey) {
            $grouped[$groupKey] = [];
        }

        $entries = $profile->relationLoaded('entries')
            ? $profile->entries
            : $profile->entries()->where('is_enabled', true)->get();

        foreach ($entries as $entry) {
            if (! $entry instanceof TopicProfileEntry || ! in_array($entry->group_key, TopicProfileEntry::GROUP_KEYS, true)) {
                continue;
            }

            $resolved = $this->resolveEntry($profile, $entry, $resolvedLocale);
            if ($resolved === null) {
                continue;
            }

            $grouped[$entry->group_key][] = $resolved;
        }

        return array_filter(
            $grouped,
            static fn (array $items): bool => $items !== []
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveEntry(TopicProfile $profile, TopicProfileEntry $entry, string $locale): ?array
    {
        return match ($entry->entry_type) {
            'article' => $this->resolveArticleEntry($profile, $entry, $locale),
            'personality_profile' => $this->resolvePersonalityEntry($profile, $entry, $locale),
            'scale' => $this->resolveScaleEntry($entry, $locale),
            'custom_link' => $this->resolveCustomLinkEntry($entry),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveArticleEntry(TopicProfile $profile, TopicProfileEntry $entry, string $locale): ?array
    {
        $targetLocale = $this->normalizeLocale($entry->effectiveTargetLocale($locale));
        $article = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $profile->org_id)
            ->where('slug', trim((string) $entry->target_key))
            ->where('locale', $targetLocale)
            ->where('status', 'published')
            ->where('is_public', true)
            ->first();

        if (! $article instanceof Article) {
            return null;
        }

        $segment = $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($targetLocale);

        return $this->buildResolvedEntry(
            $entry,
            $article->title,
            $article->excerpt,
            '/'.$segment.'/articles/'.rawurlencode((string) $article->slug),
            $article->cover_image_url,
            $targetLocale
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePersonalityEntry(TopicProfile $profile, TopicProfileEntry $entry, string $locale): ?array
    {
        $targetLocale = $this->normalizeLocale($entry->effectiveTargetLocale($locale));
        $personality = $this->personalityProfileService->getPublicProfileByType(
            (string) $entry->target_key,
            (int) $profile->org_id,
            PersonalityProfile::SCALE_CODE_MBTI,
            $targetLocale
        );

        if (! $personality instanceof PersonalityProfile) {
            return null;
        }

        $segment = $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($targetLocale);

        return $this->buildResolvedEntry(
            $entry,
            $personality->title,
            $personality->excerpt,
            '/'.$segment.'/personality/'.rawurlencode((string) $personality->slug),
            $personality->hero_image_url,
            $targetLocale
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveScaleEntry(TopicProfileEntry $entry, string $locale): ?array
    {
        $code = strtoupper(trim((string) $entry->target_key));
        if ($code === '') {
            return null;
        }

        $row = DB::table('scales_registry')
            ->select(['code', 'primary_slug', 'is_public', 'is_active'])
            ->where('org_id', 0)
            ->where('code', $code)
            ->where('is_public', 1)
            ->where('is_active', 1)
            ->first();

        if ($row === null) {
            return null;
        }

        $primarySlug = trim((string) ($row->primary_slug ?? ''));
        if ($primarySlug === '') {
            return null;
        }

        $targetLocale = $this->normalizeLocale($entry->effectiveTargetLocale($locale));
        $segment = $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($targetLocale);

        return $this->buildResolvedEntry(
            $entry,
            $code,
            null,
            '/'.$segment.'/tests/'.rawurlencode($primarySlug),
            null,
            $targetLocale
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCustomLinkEntry(TopicProfileEntry $entry): ?array
    {
        $url = trim((string) ($entry->target_url_override ?? ''));
        if ($url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return null;
        }

        $title = trim((string) ($entry->title_override ?? ''));
        if ($title === '') {
            return null;
        }

        return $this->buildResolvedEntry(
            $entry,
            $title,
            $entry->excerpt_override,
            $url,
            null,
            $entry->effectiveTargetLocale()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResolvedEntry(
        TopicProfileEntry $entry,
        ?string $title,
        ?string $excerpt,
        string $url,
        ?string $imageUrl,
        ?string $targetLocale
    ): array {
        $resolvedLocale = $this->normalizeLocale((string) ($targetLocale ?: 'en'));

        return [
            'entry_type' => (string) $entry->entry_type,
            'group_key' => (string) $entry->group_key,
            'target_key' => (string) $entry->target_key,
            'title' => $this->fallbackText($entry->title_override, $title) ?? (string) $entry->target_key,
            'excerpt' => $this->fallbackText($entry->excerpt_override, $excerpt),
            'url' => $url,
            'badge_label' => $this->fallbackText(
                $entry->badge_label,
                $this->defaultBadgeLabel((string) $entry->entry_type, $resolvedLocale)
            ),
            'cta_label' => $this->fallbackText(
                $entry->cta_label,
                $this->defaultCtaLabel((string) $entry->entry_type, $resolvedLocale)
            ),
            'image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($imageUrl),
            'is_featured' => (bool) $entry->is_featured,
        ];
    }

    private function defaultBadgeLabel(string $entryType, string $locale): string
    {
        $zh = $locale === 'zh-CN';

        return match ($entryType) {
            'article' => $zh ? '文章' : 'Article',
            'personality_profile' => $zh ? '人格' : 'Personality',
            'scale' => $zh ? '测试' : 'Test',
            'custom_link' => $zh ? '推荐' : 'Link',
            default => $zh ? '内容' : 'Content',
        };
    }

    private function defaultCtaLabel(string $entryType, string $locale): string
    {
        $zh = $locale === 'zh-CN';

        return match ($entryType) {
            'article' => $zh ? '阅读' : 'Read',
            'personality_profile' => $zh ? '查看' : 'Explore',
            'scale' => $zh ? '开始测试' : 'Take the test',
            'custom_link' => $zh ? '打开' : 'Open',
            default => $zh ? '查看' : 'View',
        };
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function fallbackText(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
