<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;

final class PersonalityVariantCloneContentRepository
{
    public function findPublishedByRuntimeType(
        string $runtimeTypeCode,
        int $orgId,
        string $scaleCode,
        string $locale,
        string $templateKey,
    ): ?PersonalityProfileVariantCloneContent {
        return PersonalityProfileVariantCloneContent::query()
            ->with([
                'variant' => static fn ($query) => $query->with([
                    'profile' => static fn ($profileQuery) => $profileQuery->withoutGlobalScopes(),
                ]),
            ])
            ->where('template_key', $templateKey)
            ->published()
            ->whereHas('variant', function ($query) use ($runtimeTypeCode): void {
                $query->where('runtime_type_code', $runtimeTypeCode)
                    ->where('is_published', true)
                    ->where(static function ($nested): void {
                        $nested->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    });
            })
            ->whereHas('variant.profile', function ($query) use ($orgId, $scaleCode, $locale): void {
                $query->withoutGlobalScopes()
                    ->where('org_id', max(0, $orgId))
                    ->where('scale_code', strtoupper(trim($scaleCode)))
                    ->where('locale', trim($locale))
                    ->where('status', 'published')
                    ->where('is_public', true)
                    ->where(static function ($nested): void {
                        $nested->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    });
            })
            ->first();
    }

    public function findByVariantAndTemplate(
        PersonalityProfileVariant $variant,
        string $templateKey,
    ): ?PersonalityProfileVariantCloneContent {
        return PersonalityProfileVariantCloneContent::query()
            ->where('personality_profile_variant_id', (int) $variant->id)
            ->where('template_key', trim($templateKey))
            ->first();
    }
}
