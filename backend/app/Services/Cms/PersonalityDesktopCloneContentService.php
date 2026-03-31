<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use App\Repositories\PersonalityVariantCloneContentRepository;

final class PersonalityDesktopCloneContentService
{
    public function __construct(
        private readonly PersonalityVariantCloneContentRepository $repository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getPublishedByType(
        string $typeLookup,
        int $orgId,
        string $scaleCode,
        string $locale,
    ): ?array {
        $runtimeTypeCode = $this->normalizeRuntimeTypeCode($typeLookup);

        if ($runtimeTypeCode === null) {
            return null;
        }

        $record = $this->repository->findPublishedByRuntimeType(
            $runtimeTypeCode,
            $orgId,
            $scaleCode,
            $locale,
            PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
        );

        if (! $record instanceof PersonalityProfileVariantCloneContent) {
            return null;
        }

        $variant = $record->variant;
        $profile = $variant?->profile;

        if ($variant === null || $profile === null) {
            return null;
        }

        $baseCode = strtoupper(trim((string) ($profile->type_code ?? '')));

        return [
            'template_key' => (string) $record->template_key,
            'schema_version' => (string) $record->schema_version,
            'full_code' => (string) $variant->runtime_type_code,
            'base_code' => $baseCode,
            'locale' => (string) $profile->locale,
            'content' => is_array($record->content_json) ? $record->content_json : [],
            'asset_slots' => PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots(
                is_array($record->asset_slots_json) ? $record->asset_slots_json : [],
            ),
            '_meta' => [
                'authority_source' => 'personality_profile_variant_clone_contents',
                'route_mode' => 'full_code_exact',
                'public_route_type' => '32-type',
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'record_id' => (int) $record->id,
                'variant_id' => (int) $variant->id,
                'profile_id' => (int) $profile->id,
                'published_at' => $record->published_at?->toISOString(),
            ],
        ];
    }

    private function normalizeRuntimeTypeCode(string $typeLookup): ?string
    {
        $normalized = strtoupper(trim($typeLookup));

        if (preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $normalized, $matches) !== 1) {
            return null;
        }

        return (string) $matches['base'].'-'.(string) $matches['variant'];
    }
}
