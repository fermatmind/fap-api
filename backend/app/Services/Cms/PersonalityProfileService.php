<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Services\Mbti\MbtiPublicProjectionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PersonalityProfileService
{
    public function __construct(
        private readonly MbtiPublicProjectionService $mbtiPublicProjectionService,
    ) {}

    public function listPublicProfiles(
        int $orgId,
        string $scaleCode,
        string $locale,
        int $page = 1,
        int $perPage = 20
    ): LengthAwarePaginator {
        return $this->basePublicQuery($orgId, $scaleCode, $locale)
            ->with([
                'seoMeta',
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->orderBy('canonical_type_code')
            ->orderBy('type_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicProfileByType(
        string $typeLookup,
        int $orgId,
        string $scaleCode,
        string $locale
    ): ?PersonalityProfile {
        [$slug, $typeCode] = $this->resolveTypeLookup($typeLookup);

        return $this->basePublicQuery($orgId, $scaleCode, $locale)
            ->where(function (Builder $query) use ($slug, $typeCode): void {
                $query->where('slug', $slug)
                    ->orWhere('type_code', $typeCode);
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }

    /**
     * @return array{profile:PersonalityProfile,variant:?PersonalityProfileVariant}|null
     */
    public function getPublicDetailRouteProfileByType(
        string $typeLookup,
        int $orgId,
        string $scaleCode,
        string $locale
    ): ?array {
        $lookup = $this->resolvePublicTypeLookup($typeLookup);

        $profile = $this->basePublicQuery($orgId, $scaleCode, $locale)
            ->where(function (Builder $query) use ($lookup): void {
                $query->where('slug', $lookup['canonical_slug'])
                    ->orWhere('type_code', $lookup['canonical_type_code']);
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();

        if (! $profile instanceof PersonalityProfile) {
            return null;
        }

        $variant = null;

        if ($lookup['runtime_type_code'] !== null) {
            $variant = $this->resolvePublishedVariantForPublicRoute($profile, $lookup['runtime_type_code']);

            if (! $variant instanceof PersonalityProfileVariant) {
                return null;
            }
        }

        return [
            'profile' => $profile,
            'variant' => $variant,
        ];
    }

    /**
     * @return Collection<int, PersonalityProfile>
     */
    public function getSitemapPublicProfiles(): Collection
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->whereIn('type_code', PersonalityProfile::TYPE_CODES)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'seoMeta',
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();
    }

    /**
     * @return array{0:string,1:string}
     */
    public function resolveTypeLookup(string $typeLookup): array
    {
        $normalized = trim($typeLookup);

        return [
            strtolower($normalized),
            strtoupper($normalized),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCanonicalFields(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        $projection = $this->buildPublicProjection($profile, $variant);

        return [
            'canonical_type_code' => data_get($projection, 'canonical_type_code'),
            'schema_version' => (string) data_get($projection, '_meta.schema_version', $profile->schema_version),
            'type_name' => data_get($projection, 'profile.type_name'),
            'nickname' => data_get($projection, 'profile.nickname'),
            'rarity' => data_get($projection, 'profile.rarity'),
            'keywords' => is_array(data_get($projection, 'profile.keywords'))
                ? array_values(data_get($projection, 'profile.keywords'))
                : [],
            'hero_summary' => data_get($projection, 'profile.hero_summary'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPublicProjection(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        return $this->mbtiPublicProjectionService->buildForPublicPersonalityRoute($profile, $variant);
    }

    private function basePublicQuery(int $orgId, string $scaleCode, string $locale): Builder
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->where('scale_code', $this->normalizeScaleCode($scaleCode))
            ->whereIn('type_code', PersonalityProfile::TYPE_CODES)
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale);
    }

    private function normalizeScaleCode(string $scaleCode): string
    {
        $normalized = strtoupper(trim($scaleCode));

        return $normalized !== '' ? $normalized : PersonalityProfile::SCALE_CODE_MBTI;
    }

    /**
     * @return array{
     *   canonical_slug:string,
     *   canonical_type_code:string,
     *   runtime_type_code:?string,
     *   variant_code:?string
     * }
     */
    private function resolvePublicTypeLookup(string $typeLookup): array
    {
        $normalized = trim($typeLookup);

        if (preg_match('/^(?<base>[A-Za-z]{4})(?:-(?<variant>[AaTt]))?$/', $normalized, $matches) === 1) {
            $baseTypeCode = strtoupper($matches['base']);
            $variantCode = isset($matches['variant']) ? strtoupper($matches['variant']) : null;

            return [
                'canonical_slug' => strtolower($matches['base']),
                'canonical_type_code' => $baseTypeCode,
                'runtime_type_code' => $variantCode !== null ? $baseTypeCode.'-'.$variantCode : null,
                'variant_code' => $variantCode,
            ];
        }

        return [
            'canonical_slug' => strtolower($normalized),
            'canonical_type_code' => strtoupper($normalized),
            'runtime_type_code' => null,
            'variant_code' => null,
        ];
    }

    private function resolvePublishedVariantForPublicRoute(
        PersonalityProfile $profile,
        string $runtimeTypeCode
    ): ?PersonalityProfileVariant {
        $normalizedRuntimeTypeCode = strtoupper(trim($runtimeTypeCode));

        if ($normalizedRuntimeTypeCode === '') {
            return null;
        }

        return $profile->variants()
            ->where('runtime_type_code', $normalizedRuntimeTypeCode)
            ->where('is_published', true)
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }
}
