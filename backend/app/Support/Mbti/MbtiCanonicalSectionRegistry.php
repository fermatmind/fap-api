<?php

declare(strict_types=1);

namespace App\Support\Mbti;

use InvalidArgumentException;

final class MbtiCanonicalSectionRegistry
{
    public const BUCKET_SECTIONS = 'sections';

    public const BUCKET_PREMIUM_TEASER = 'premium_teaser';

    public const RENDER_VARIANT_LETTERS_INTRO = 'letters_intro';

    public const RENDER_VARIANT_RICH_TEXT = 'rich_text';

    public const RENDER_VARIANT_BULLET_LIST = 'bullets';

    public const RENDER_VARIANT_TRAIT_DIMENSION_GRID = 'trait_dimension_grid';

    public const RENDER_VARIANT_PREFERRED_ROLE_LIST = 'preferred_role_list';

    public const RENDER_VARIANT_PREMIUM_TEASER = 'premium_teaser';

    /**
     * @return array<string, array{
     *   bucket:string,
     *   render_variant:string,
     *   group:string,
     *   label:string,
     *   title:string,
     *   description:string,
     *   sort_order:int,
     *   enabled:bool,
     *   premium_teaser?:bool,
     *   payload_schema?:array<string,mixed>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'letters_intro' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_LETTERS_INTRO,
                'group' => 'top',
                'label' => 'Letters Intro',
                'title' => 'Letter-by-letter introduction',
                'description' => 'Headline and per-letter copy for the 32-type MBTI identity block.',
                'sort_order' => 10,
                'enabled' => true,
            ],
            'overview' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'top',
                'label' => 'Overview',
                'title' => 'Overview',
                'description' => 'Narrative overview of the canonical personality profile.',
                'sort_order' => 20,
                'enabled' => true,
            ],
            'trait_overview' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_TRAIT_DIMENSION_GRID,
                'group' => 'trait_overview',
                'label' => 'Trait Overview',
                'title' => 'Trait overview',
                'description' => 'Canonical MBTI dimension grid using EI / SN / TF / JP / AT axis ids.',
                'sort_order' => 30,
                'enabled' => true,
                'payload_schema' => [
                    'summary' => 'string|null',
                    'dimensions' => 'list<trait_dimension>',
                    'axis_aliases' => self::traitOverviewAxisAliases(),
                ],
            ],
            'traits.decision_style' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'traits',
                'label' => 'Decision Style',
                'title' => 'Decision style',
                'description' => 'Scene-level narrative for how the profile narrows options and commits under uncertainty.',
                'sort_order' => 35,
                'enabled' => true,
            ],
            'career.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'career',
                'label' => 'Career Summary',
                'title' => 'Career summary',
                'description' => 'Narrative career overview for the canonical base profile.',
                'sort_order' => 40,
                'enabled' => true,
            ],
            'career.collaboration_fit' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'career',
                'label' => 'Collaboration Fit',
                'title' => 'Collaboration fit',
                'description' => 'Scene-level narrative for how the profile aligns, contributes, and creates friction inside teams.',
                'sort_order' => 45,
                'enabled' => true,
            ],
            'career.work_environment' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'career',
                'label' => 'Work Environment',
                'title' => 'Work environment fit',
                'description' => 'Scene-level narrative for pace, feedback, autonomy, and collaboration conditions that fit the profile.',
                'sort_order' => 47,
                'enabled' => true,
            ],
            'career.advantages' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
                'label' => 'Career Advantages',
                'title' => 'Career advantages',
                'description' => 'Bullet list of work strengths in career contexts.',
                'sort_order' => 50,
                'enabled' => true,
            ],
            'career.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
                'label' => 'Career Weaknesses',
                'title' => 'Career weaknesses',
                'description' => 'Bullet list of career blind spots and trade-offs.',
                'sort_order' => 60,
                'enabled' => true,
            ],
            'career.preferred_roles' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_PREFERRED_ROLE_LIST,
                'group' => 'career',
                'label' => 'Preferred Roles',
                'title' => 'Preferred roles',
                'description' => 'Grouped role recommendations for the canonical profile.',
                'sort_order' => 70,
                'enabled' => true,
            ],
            'career.next_step' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'career',
                'label' => 'Career Next Step',
                'title' => 'Career next step',
                'description' => 'Scene-level narrative that turns the current work pattern into one concrete next move.',
                'sort_order' => 75,
                'enabled' => true,
            ],
            'career.upgrade_suggestions' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'career',
                'label' => 'Upgrade Suggestions',
                'title' => 'Career upgrade suggestions',
                'description' => 'Upgrade formulas and bullet suggestions for career growth.',
                'sort_order' => 80,
                'enabled' => true,
            ],
            'growth.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'growth',
                'label' => 'Growth Summary',
                'title' => 'Growth summary',
                'description' => 'Narrative personal growth overview.',
                'sort_order' => 90,
                'enabled' => true,
            ],
            'growth.strengths' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'growth',
                'label' => 'Growth Strengths',
                'title' => 'Growth strengths',
                'description' => 'Bullet list of personal growth strengths.',
                'sort_order' => 100,
                'enabled' => true,
            ],
            'growth.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'growth',
                'label' => 'Growth Weaknesses',
                'title' => 'Growth weaknesses',
                'description' => 'Bullet list of growth liabilities and tensions.',
                'sort_order' => 110,
                'enabled' => true,
            ],
            'growth.stress_recovery' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'growth',
                'label' => 'Stress Recovery',
                'title' => 'Stress and recovery',
                'description' => 'Scene-level narrative for overload, recovery, and reset loops.',
                'sort_order' => 115,
                'enabled' => true,
            ],
            'growth.motivators' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'growth',
                'label' => 'Growth Motivators',
                'title' => 'Growth motivators',
                'description' => 'Premium teaser describing what energizes the profile.',
                'sort_order' => 120,
                'enabled' => true,
                'premium_teaser' => true,
            ],
            'growth.drainers' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'growth',
                'label' => 'Growth Drainers',
                'title' => 'Growth drainers',
                'description' => 'Premium teaser describing what depletes the profile.',
                'sort_order' => 130,
                'enabled' => true,
                'premium_teaser' => true,
            ],
            'relationships.summary' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'relationships',
                'label' => 'Relationships Summary',
                'title' => 'Relationships summary',
                'description' => 'Narrative overview for relationships and close dynamics.',
                'sort_order' => 140,
                'enabled' => true,
            ],
            'relationships.strengths' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'relationships',
                'label' => 'Relationships Strengths',
                'title' => 'Relationships strengths',
                'description' => 'Bullet list of relationship strengths.',
                'sort_order' => 150,
                'enabled' => true,
            ],
            'relationships.weaknesses' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_BULLET_LIST,
                'group' => 'relationships',
                'label' => 'Relationships Weaknesses',
                'title' => 'Relationships weaknesses',
                'description' => 'Bullet list of relationship weak points.',
                'sort_order' => 160,
                'enabled' => true,
            ],
            'relationships.communication_style' => [
                'bucket' => self::BUCKET_SECTIONS,
                'render_variant' => self::RENDER_VARIANT_RICH_TEXT,
                'group' => 'relationships',
                'label' => 'Communication Style',
                'title' => 'Communication and collaboration',
                'description' => 'Scene-level narrative for expression style, collaboration patterns, and alignment repair.',
                'sort_order' => 165,
                'enabled' => true,
            ],
            'relationships.rel_advantages' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'relationships',
                'label' => 'Relationship Advantages',
                'title' => 'Relationship advantages',
                'description' => 'Premium teaser for interpersonal advantages.',
                'sort_order' => 170,
                'enabled' => true,
                'premium_teaser' => true,
            ],
            'relationships.rel_risks' => [
                'bucket' => self::BUCKET_PREMIUM_TEASER,
                'render_variant' => self::RENDER_VARIANT_PREMIUM_TEASER,
                'group' => 'relationships',
                'label' => 'Relationship Risks',
                'title' => 'Relationship risks',
                'description' => 'Premium teaser for interpersonal risks.',
                'sort_order' => 180,
                'enabled' => true,
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

    /**
     * @return list<string>
     */
    public static function traitOverviewAxisTargets(): array
    {
        return ['EI', 'SN', 'TF', 'JP', 'AT'];
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
