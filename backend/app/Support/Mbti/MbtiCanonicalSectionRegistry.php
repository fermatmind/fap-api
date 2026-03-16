<?php

declare(strict_types=1);

namespace App\Support\Mbti;

use InvalidArgumentException;

final class MbtiCanonicalSectionRegistry
{
    public const BUCKET_SECTIONS = 'sections';

    public const BUCKET_PREMIUM_TEASER = 'premium_teaser';

    public const RENDER_VARIANT_RICH_TEXT = 'rich_text';

    public const RENDER_VARIANT_BULLET_LIST = 'bullet_list';

    public const RENDER_VARIANT_TRAIT_DIMENSION_GRID = 'trait_dimension_grid';

    public const RENDER_VARIANT_PREFERRED_ROLE_LIST = 'preferred_role_list';

    public const RENDER_VARIANT_PREMIUM_TEASER = 'premium_teaser';

    /**
     * @return array<string, array{
     *   bucket:string,
     *   render_variant:string,
     *   group:string,
     *   premium_teaser?:bool,
     *   payload_schema?:array<string,mixed>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'letters_intro' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'top',
            ],
            'overview' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'top',
            ],
            'trait_overview' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_TRAIT_DIMENSION_GRID,
                'group' => 'trait_overview',
                'payload_schema' => [
                    'summary' => 'string|null',
                    'dimensions' => 'list<trait_dimension>',
                    'axis_aliases' => self::traitOverviewAxisAliases(),
                ],
            ],
            'career.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'career',
            ],
            'career.advantages' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
            ],
            'career.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
            ],
            'career.preferred_roles' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_PREFERRED_ROLE_LIST,
                'group' => 'career',
            ],
            'career.upgrade_suggestions' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
            ],
            'growth.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'growth',
            ],
            'growth.strengths' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'growth',
            ],
            'growth.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'growth',
            ],
            'growth.motivators' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'growth',
                'premium_teaser' => true,
            ],
            'growth.drainers' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'growth',
                'premium_teaser' => true,
            ],
            'relationships.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'relationships',
            ],
            'relationships.strengths' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'relationships',
            ],
            'relationships.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'relationships',
            ],
            'relationships.rel_advantages' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'relationships',
                'premium_teaser' => true,
            ],
            'relationships.rel_risks' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'relationships',
                'premium_teaser' => true,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function traitOverviewAxisAliases(): array
    {
        return [
            'EI' => 'EI',
            'NS' => 'SN',
            'SN' => 'SN',
            'FT' => 'TF',
            'TF' => 'TF',
            'JP' => 'JP',
            'AT' => 'AT',
        ];
    }

    public static function normalizeTraitAxisCode(string $axisCode): string
    {
        $normalized = strtoupper(trim($axisCode));
        $mapped = self::traitOverviewAxisAliases()[$normalized] ?? null;

        if ($mapped === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported MBTI trait_overview axis code [%s].',
                $axisCode,
            ));
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return list<string>
     */
    public static function renderVariants(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $definition): string => (string) $definition['render_variant'],
            self::definitions(),
        )));
    }

    /**
     * @return list<string>
     */
    public static function premiumTeaserKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::definitions(),
            static fn (array $definition): bool => (($definition['bucket'] ?? null) === self::BUCKET_PREMIUM_TEASER),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $sectionKey): array
    {
        $definition = self::definitions()[$sectionKey] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported MBTI canonical section key [%s].',
                $sectionKey,
            ));
        }

        return $definition;
    }

    public static function isPremiumTeaser(string $sectionKey): bool
    {
        return (self::definition($sectionKey)['bucket'] ?? null) === self::BUCKET_PREMIUM_TEASER;
    }
}
