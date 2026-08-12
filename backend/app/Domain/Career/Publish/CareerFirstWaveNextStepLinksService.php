<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveNextStepLinksSummary;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class CareerFirstWaveNextStepLinksService
{
    public const SUMMARY_VERSION = 'career.next_step.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public const CACHE_KEY_PREFIX = 'career:public-authority:first-wave-next-step:v1';

    public const CACHE_TTL_SECONDS = 3600;

    public const NEGATIVE_CACHE_TTL_SECONDS = 300;

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $discoverabilityRoutes = null;

    /**
     * @var array<string, CareerFirstWaveNextStepLinksSummary|null>
     */
    private array $summaryBySlug = [];

    public function __construct(
        private readonly CareerFirstWaveDiscoverabilityManifestService $discoverabilityManifestService,
    ) {}

    public function buildBySlug(
        string $slug,
        string $publicLocale = 'zh-CN',
        bool $allowCacheWrites = true,
    ): ?CareerFirstWaveNextStepLinksSummary {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return null;
        }

        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $memoKey = $normalizedSlug.'|'.$normalizedLocale;
        if (array_key_exists($memoKey, $this->summaryBySlug)) {
            return $this->summaryBySlug[$memoKey];
        }

        try {
            if (Cache::get($this->negativeCacheKey($normalizedSlug, $normalizedLocale)) === true) {
                return $this->summaryBySlug[$memoKey] = null;
            }

            $cached = $this->summaryFromPayload(Cache::get($this->activeCacheKey($normalizedSlug, $normalizedLocale)));
            if ($cached instanceof CareerFirstWaveNextStepLinksSummary) {
                return $this->summaryBySlug[$memoKey] = $cached;
            }
        } catch (\Throwable $throwable) {
            Log::debug('CAREER_NEXT_STEP_CACHE_READ_FAILED', [
                'exception' => $throwable::class,
            ]);
        }

        try {
            $summary = $this->buildUncachedBySlug($normalizedSlug);
        } catch (\Throwable $throwable) {
            try {
                $stale = $this->summaryFromPayload(Cache::get($this->lkgCacheKey($normalizedSlug, $normalizedLocale)));
                if ($stale instanceof CareerFirstWaveNextStepLinksSummary) {
                    return $this->summaryBySlug[$memoKey] = $stale;
                }
            } catch (\Throwable $cacheThrowable) {
                Log::debug('CAREER_NEXT_STEP_LKG_READ_FAILED', [
                    'exception' => $cacheThrowable::class,
                ]);
            }

            throw $throwable;
        }

        if ($allowCacheWrites) {
            try {
                if ($summary === null) {
                    Cache::put(
                        $this->negativeCacheKey($normalizedSlug, $normalizedLocale),
                        true,
                        now()->addSeconds(self::NEGATIVE_CACHE_TTL_SECONDS),
                    );
                } else {
                    $payload = $summary->toArray();
                    Cache::put(
                        $this->activeCacheKey($normalizedSlug, $normalizedLocale),
                        $payload,
                        now()->addSeconds(self::CACHE_TTL_SECONDS),
                    );
                    Cache::forever($this->lkgCacheKey($normalizedSlug, $normalizedLocale), $payload);
                    Cache::forget($this->negativeCacheKey($normalizedSlug, $normalizedLocale));
                }
            } catch (\Throwable $throwable) {
                Log::debug('CAREER_NEXT_STEP_CACHE_WRITE_FAILED', [
                    'exception' => $throwable::class,
                ]);
            }
        }

        return $this->summaryBySlug[$memoKey] = $summary;
    }

    private function buildUncachedBySlug(string $normalizedSlug): ?CareerFirstWaveNextStepLinksSummary
    {
        $subject = Occupation::query()
            ->with('family')
            ->where('canonical_slug', $normalizedSlug)
            ->first();

        if (! $subject instanceof Occupation) {
            return $this->summaryBySlug[$normalizedSlug] = null;
        }

        $routes = collect($this->discoverabilityRoutes());

        $jobRoutes = $routes
            ->where('route_kind', 'career_job_detail')
            ->keyBy(static fn (array $row): string => (string) ($row['canonical_slug'] ?? ''));

        if (! $jobRoutes->has($subject->canonical_slug)) {
            return $this->summaryBySlug[$normalizedSlug] = null;
        }

        $nextStepLinks = [];

        $family = $subject->family;
        if ($family instanceof OccupationFamily) {
            $familyRoute = $routes
                ->first(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub'
                    && ($row['canonical_slug'] ?? null) === $family->canonical_slug
                    && ($row['discoverability_state'] ?? null) === 'discoverable');

            if (is_array($familyRoute)) {
                $nextStepLinks[] = [
                    'route_kind' => 'career_family_hub',
                    'canonical_path' => (string) ($familyRoute['canonical_path'] ?? '/career/family/'.$family->canonical_slug),
                    'canonical_slug' => (string) $family->canonical_slug,
                    'link_reason_code' => 'family_hub_discoverable',
                    'family_uuid' => (string) $family->id,
                    'title_en' => (string) $family->title_en,
                ];
            }

            $siblings = Occupation::query()
                ->where('family_id', $family->id)
                ->whereKeyNot($subject->id)
                ->orderBy('canonical_title_en')
                ->orderBy('canonical_slug')
                ->get();

            foreach ($siblings as $sibling) {
                $route = $jobRoutes->get((string) $sibling->canonical_slug);
                if (! is_array($route) || ($route['discoverability_state'] ?? null) !== 'discoverable') {
                    continue;
                }

                $nextStepLinks[] = [
                    'route_kind' => 'career_job_detail',
                    'canonical_path' => (string) ($route['canonical_path'] ?? '/career/jobs/'.$sibling->canonical_slug),
                    'canonical_slug' => (string) $sibling->canonical_slug,
                    'link_reason_code' => 'same_family_sibling_discoverable',
                    'occupation_uuid' => (string) $sibling->id,
                    'canonical_title_en' => (string) $sibling->canonical_title_en,
                ];
            }
        }

        $dedupedLinks = collect($nextStepLinks)
            ->unique(static fn (array $row): string => sprintf(
                '%s|%s|%s',
                (string) ($row['route_kind'] ?? ''),
                (string) ($row['canonical_path'] ?? ''),
                (string) ($row['canonical_slug'] ?? '')
            ))
            ->sortBy(static fn (array $row): array => [
                strtolower((string) ($row['route_kind'] ?? '')),
                strtolower((string) ($row['canonical_path'] ?? '')),
            ])
            ->values()
            ->all();

        $counts = [
            'total' => count($dedupedLinks),
            'job_detail' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_job_detail')),
            'family_hub' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub')),
        ];

        return $this->summaryBySlug[$normalizedSlug] = new CareerFirstWaveNextStepLinksSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            subjectIdentity: [
                'occupation_uuid' => (string) $subject->id,
                'canonical_slug' => (string) $subject->canonical_slug,
                'canonical_title_en' => (string) $subject->canonical_title_en,
            ],
            counts: $counts,
            nextStepLinks: $dedupedLinks,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverabilityRoutes(): array
    {
        if ($this->discoverabilityRoutes !== null) {
            return $this->discoverabilityRoutes;
        }

        $manifest = $this->discoverabilityManifestService->build()->toArray();
        $this->discoverabilityRoutes = collect((array) ($manifest['routes'] ?? []))
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();

        return $this->discoverabilityRoutes;
    }

    private function normalizePublicLocale(string $publicLocale): string
    {
        $normalized = strtolower(trim($publicLocale));

        return in_array($normalized, ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function activeCacheKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:active', self::CACHE_KEY_PREFIX, $slug, $publicLocale);
    }

    private function lkgCacheKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:lkg', self::CACHE_KEY_PREFIX, $slug, $publicLocale);
    }

    private function negativeCacheKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:negative', self::CACHE_KEY_PREFIX, $slug, $publicLocale);
    }

    private function summaryFromPayload(mixed $payload): ?CareerFirstWaveNextStepLinksSummary
    {
        if (! is_array($payload)
            || ($payload['summary_version'] ?? null) !== self::SUMMARY_VERSION
            || ($payload['scope'] ?? null) !== self::SCOPE
            || ! is_array($payload['subject_identity'] ?? null)
            || ! is_array($payload['counts'] ?? null)
            || ! is_array($payload['next_step_links'] ?? null)) {
            return null;
        }

        return new CareerFirstWaveNextStepLinksSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            subjectIdentity: $payload['subject_identity'],
            counts: $payload['counts'],
            nextStepLinks: $payload['next_step_links'],
        );
    }
}
