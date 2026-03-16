<?php

declare(strict_types=1);

namespace App\Services\Mbti\Adapters;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class MbtiPersonalityProfileAuthoritySourceAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function fromBaseProfile(PersonalityProfile $profile): array
    {
        $profile->loadMissing([
            'sections' => static function ($query): void {
                $query->where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('id');
            },
            'seoMeta',
        ]);

        return [
            'canonical_type_code' => $this->canonicalTypeCode(
                $profile->canonical_type_code,
                (string) $profile->type_code
            ),
            'slug' => $this->nullableText($profile->slug),
            'locale' => $this->nullableText($profile->locale),
            'is_indexable' => (bool) $profile->is_indexable,
            'profile' => [
                'type_name' => $this->nullableText($profile->type_name),
                'nickname' => $this->nullableText($profile->nickname),
                'rarity' => $this->nullableText($profile->rarity_text),
                'keywords' => $this->stringList($profile->keywords_json),
                'hero_summary' => $this->nullableText($profile->hero_summary_md),
            ],
            'summary_card' => [
                'title' => $this->nullableText($profile->title),
                'subtitle' => $this->nullableText($profile->subtitle),
                'summary' => $this->nullableText($profile->excerpt),
            ],
            'sections' => $this->sectionMapFromCollection($profile->sections, 'base'),
            'seo' => $this->seoPayload($profile->seoMeta),
            '_meta' => [
                'schema_version' => $this->nullableText($profile->schema_version) ?? PersonalityProfile::SCHEMA_VERSION_V2,
                'profile_id' => (int) $profile->id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseAuthority
     * @return array<string, mixed>
     */
    public function overlayVariant(array $baseAuthority, ?PersonalityProfileVariant $variant): array
    {
        if (! $variant instanceof PersonalityProfileVariant) {
            return $baseAuthority;
        }

        $variant->loadMissing([
            'sections' => static function ($query): void {
                $query->orderBy('sort_order')
                    ->orderBy('id');
            },
            'seoMeta',
        ]);

        $baseAuthority['runtime_type_code'] = $this->nullableText($variant->runtime_type_code);
        $baseAuthority['variant_code'] = $this->nullableText($variant->variant_code);

        foreach ([
            'type_name' => $variant->type_name,
            'nickname' => $variant->nickname,
            'rarity' => $variant->rarity_text,
            'hero_summary' => $variant->hero_summary_md,
        ] as $key => $value) {
            $normalized = $this->nullableText($value);
            if ($normalized !== null) {
                $baseAuthority['profile'][$key] = $normalized;
            }
        }

        $keywords = $this->stringList($variant->keywords_json);
        if ($keywords !== []) {
            $baseAuthority['profile']['keywords'] = $keywords;
        }

        $sections = is_array($baseAuthority['sections'] ?? null) ? $baseAuthority['sections'] : [];
        foreach ($variant->sections as $section) {
            if (! $section instanceof PersonalityProfileVariantSection) {
                continue;
            }

            $sectionKey = trim((string) $section->section_key);
            if (! $this->supportsSectionKey($sectionKey)) {
                continue;
            }

            if (! (bool) $section->is_enabled) {
                unset($sections[$sectionKey]);

                continue;
            }

            $definition = MbtiCanonicalSectionRegistry::definition($sectionKey);
            $sections[$sectionKey] = [
                'key' => $sectionKey,
                'title' => $this->nullableText($section->title) ?? (string) $definition['title'],
                'render' => $this->nullableText($section->render_variant) ?? (string) $definition['render_variant'],
                'body_md' => $this->nullableText($section->body_md),
                'payload' => $this->arrayOrNull($section->payload_json),
                'is_enabled' => true,
                'source' => 'variant',
            ];
        }
        $baseAuthority['sections'] = $sections;

        foreach ($this->seoPayload($variant->seoMeta) as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($key === 'jsonld' && is_array($value) && $value !== []) {
                $baseAuthority['seo'][$key] = $value;

                continue;
            }

            $baseAuthority['seo'][$key] = $value;
        }

        if (! isset($baseAuthority['_meta']) || ! is_array($baseAuthority['_meta'])) {
            $baseAuthority['_meta'] = [];
        }
        $baseAuthority['_meta']['variant_id'] = (int) $variant->id;

        return $baseAuthority;
    }

    /**
     * @param  Collection<int, PersonalityProfileSection|PersonalityProfileVariantSection>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function sectionMapFromCollection(Collection $sections, string $source): array
    {
        $mapped = [];

        foreach ($sections as $section) {
            $sectionKey = trim((string) $section->section_key);
            if (! $this->supportsSectionKey($sectionKey)) {
                continue;
            }

            $definition = MbtiCanonicalSectionRegistry::definition($sectionKey);
            $mapped[$sectionKey] = [
                'key' => $sectionKey,
                'title' => $this->nullableText($section->title) ?? (string) $definition['title'],
                'render' => $this->nullableText($section->render_variant) ?? (string) $definition['render_variant'],
                'body_md' => $this->nullableText($section->body_md),
                'payload' => $this->arrayOrNull($section->payload_json),
                'is_enabled' => (bool) $section->is_enabled,
                'source' => $source,
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function seoPayload(PersonalityProfileSeoMeta|PersonalityProfileVariantSeoMeta|null $seoMeta): array
    {
        if ($seoMeta === null) {
            return [
                'title' => null,
                'description' => null,
                'canonical_url' => null,
                'og_title' => null,
                'og_description' => null,
                'og_image_url' => null,
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_url' => null,
                'robots' => null,
                'jsonld' => [],
            ];
        }

        return [
            'title' => $this->nullableText($seoMeta->seo_title),
            'description' => $this->nullableText($seoMeta->seo_description),
            'canonical_url' => $this->nullableText($seoMeta->canonical_url),
            'og_title' => $this->nullableText($seoMeta->og_title),
            'og_description' => $this->nullableText($seoMeta->og_description),
            'og_image_url' => $this->nullableText($seoMeta->og_image_url),
            'twitter_title' => $this->nullableText($seoMeta->twitter_title),
            'twitter_description' => $this->nullableText($seoMeta->twitter_description),
            'twitter_image_url' => $this->nullableText($seoMeta->twitter_image_url),
            'robots' => $this->nullableText($seoMeta->robots),
            'jsonld' => is_array($seoMeta->jsonld_overrides_json) ? $seoMeta->jsonld_overrides_json : [],
        ];
    }

    private function supportsSectionKey(string $sectionKey): bool
    {
        try {
            MbtiCanonicalSectionRegistry::definition($sectionKey);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function canonicalTypeCode(mixed $canonicalTypeCode, string $fallbackTypeCode): string
    {
        $normalized = strtoupper(trim((string) $canonicalTypeCode));
        if ($normalized !== '') {
            return $normalized;
        }

        return strtoupper(trim($fallbackTypeCode));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->nullableText($item);
            if ($normalized === null) {
                continue;
            }

            $items[$normalized] = true;
        }

        return array_keys($items);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
