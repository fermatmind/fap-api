<?php

declare(strict_types=1);

namespace App\Domain\Career\Links;

use App\Models\ScaleRegistry;
use App\Models\TopicProfile;
use App\Services\Cms\TopicProfileSeoService;

final class CareerCanonicalSupportRouteRegistry
{
    public function __construct(
        private readonly TopicProfileSeoService $topicProfileSeoService,
    ) {}

    /**
     * @return list<array{
     *     route_kind:string,
     *     canonical_path:string,
     *     canonical_slug:string,
     *     metadata:array<string, string>,
     *     is_active:bool,
     *     source_of_truth:string
     * }>
     */
    public function list(string $locale = 'en'): array
    {
        $routes = array_merge(
            $this->listCanonicalTestLandingRoutes($locale),
            $this->listCanonicalTopicDetailRoutes($locale),
        );

        return array_values(array_map(
            static fn (array $row): array => $row,
            collect($routes)
                ->unique(static fn (array $row): string => sprintf(
                    '%s|%s|%s',
                    $row['route_kind'] ?? '',
                    $row['canonical_path'] ?? '',
                    $row['canonical_slug'] ?? '',
                ))
                ->values()
                ->all()
        ));
    }

    /**
     * @return list<array{
     *     route_kind:string,
     *     canonical_path:string,
     *     canonical_slug:string,
     *     metadata:array<string, string>,
     *     is_active:bool,
     *     source_of_truth:string
     * }>
     */
    private function listCanonicalTestLandingRoutes(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return ScaleRegistry::queryByOrgWhitelist([0])
            ->where('org_id', 0)
            ->where('is_public', true)
            ->where('is_active', true)
            ->whereNotNull('primary_slug')
            ->orderBy('code')
            ->get()
            ->map(function (ScaleRegistry $row) use ($segment): ?array {
                $primarySlug = strtolower(trim((string) $row->primary_slug));
                $scaleCode = strtoupper(trim((string) $row->code));

                if ($primarySlug === '' || $scaleCode === '') {
                    return null;
                }

                return [
                    'route_kind' => 'test_landing',
                    'canonical_path' => '/'.$segment.'/tests/'.rawurlencode($primarySlug),
                    'canonical_slug' => $primarySlug,
                    'metadata' => [
                        'scale_code' => $scaleCode,
                        'primary_slug' => $primarySlug,
                    ],
                    'is_active' => true,
                    'source_of_truth' => 'scales_registry.primary_slug',
                ];
            })
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     route_kind:string,
     *     canonical_path:string,
     *     canonical_slug:string,
     *     metadata:array<string, string>,
     *     is_active:bool,
     *     source_of_truth:string
     * }>
     */
    private function listCanonicalTopicDetailRoutes(string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $segment = $this->frontendLocaleSegment($resolvedLocale);

        return TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->forLocale($resolvedLocale)
            ->publishedPublic()
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->orderBy('topic_code')
            ->orderBy('id')
            ->get()
            ->map(function (TopicProfile $profile) use ($segment): ?array {
                $topicCode = strtolower(trim((string) $profile->topic_code));
                $slug = strtolower(trim((string) $profile->slug));

                if ($topicCode === '' || $slug === '') {
                    return null;
                }

                return [
                    'route_kind' => 'topic_detail',
                    'canonical_path' => '/'.$segment.'/topics/'.rawurlencode($slug),
                    'canonical_slug' => $slug,
                    'metadata' => [
                        'topic_code' => $topicCode,
                        'slug' => $slug,
                    ],
                    'is_active' => true,
                    'source_of_truth' => 'topic_profiles.slug',
                ];
            })
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }
}
