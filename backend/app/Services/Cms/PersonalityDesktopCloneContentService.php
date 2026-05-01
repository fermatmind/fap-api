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

        $content = is_array($record->content_json) ? $record->content_json : [];

        return [
            'template_key' => (string) $record->template_key,
            'schema_version' => (string) $record->schema_version,
            'full_code' => (string) $variant->runtime_type_code,
            'base_code' => $baseCode,
            'locale' => (string) $profile->locale,
            'content' => $this->redactPublicContent($content),
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

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function redactPublicContent(array $content): array
    {
        foreach (['career', 'growth', 'relationships'] as $chapterKey) {
            $chapter = $content['chapters'][$chapterKey] ?? null;
            if (! is_array($chapter)) {
                continue;
            }

            $chapter['lockedBlocks'] = $this->redactLockedBlocks(
                is_array($chapter['lockedBlocks'] ?? null) ? $chapter['lockedBlocks'] : [],
            );

            foreach ($this->premiumInsightModuleKeys($chapterKey) as $moduleKey) {
                if (is_array($chapter[$moduleKey] ?? null)) {
                    $chapter[$moduleKey] = $this->redactPremiumInsightModule($chapter[$moduleKey]);
                }
            }

            $content['chapters'][$chapterKey] = $chapter;
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private function premiumInsightModuleKeys(string $chapterKey): array
    {
        return match ($chapterKey) {
            'growth' => ['what_energizes', 'what_drains'],
            'relationships' => ['superpowers', 'pitfalls'],
            default => [],
        };
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function redactLockedBlocks(array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $blurredItems = is_array($block['blurredItems'] ?? null) ? $block['blurredItems'] : [];
            $block['blurredItems'] = array_map(
                static fn (): array => ['is_locked' => true],
                $blurredItems,
            );
            $block['is_locked'] = true;
            $blocks[$index] = $block;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function redactPremiumInsightModule(array $module): array
    {
        $items = is_array($module['items'] ?? null) ? $module['items'] : [];

        $module['items'] = array_map(
            static function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                return array_filter([
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'description' => $item['description'] ?? null,
                    'tags' => $item['tags'] ?? null,
                    'is_locked' => true,
                ], static fn (mixed $value): bool => $value !== null);
            },
            $items,
        );

        $module['is_locked'] = true;

        return $module;
    }
}
