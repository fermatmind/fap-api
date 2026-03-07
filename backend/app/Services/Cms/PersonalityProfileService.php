<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PersonalityProfileService
{
    public function listPublicProfiles(
        int $orgId,
        string $scaleCode,
        string $locale,
        int $page = 1,
        int $perPage = 20
    ): LengthAwarePaginator {
        return $this->basePublicQuery($orgId, $scaleCode, $locale)
            ->with('seoMeta')
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

    private function basePublicQuery(int $orgId, string $scaleCode, string $locale): Builder
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->where('scale_code', $this->normalizeScaleCode($scaleCode))
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
}
