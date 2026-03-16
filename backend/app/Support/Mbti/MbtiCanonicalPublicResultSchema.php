<?php

declare(strict_types=1);

namespace App\Support\Mbti;

final class MbtiCanonicalPublicResultSchema
{
    public const SCHEMA_VERSION = 'mbti-public-canonical-pr1';

    /**
     * @return array<string, array{path:string,render_variant:string}>
     */
    public static function profileFieldDefinitions(): array
    {
        return [
            'hero_summary' => [
                'path' => 'profile.hero_summary',
                'render_variant' => MbtiCanonicalSectionRegistry::RENDER_VARIANT_RICH_TEXT,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function seoFieldKeys(): array
    {
        return [
            'seo_title',
            'seo_description',
            'canonical_url',
            'og_title',
            'og_description',
            'og_image_url',
            'twitter_title',
            'twitter_description',
            'twitter_image_url',
            'robots',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scaffoldPayload(MbtiPublicTypeIdentity $identity, string $authoritySource): array
    {
        $sections = [];
        $premiumTeaser = [];

        foreach (MbtiCanonicalSectionRegistry::definitions() as $sectionKey => $definition) {
            $entry = [
                'section_key' => $sectionKey,
                'render_variant' => (string) $definition['render_variant'],
                'title' => null,
                'body' => null,
                'payload' => $sectionKey === 'trait_overview'
                    ? [
                        'summary' => null,
                        'dimensions' => [],
                        'axis_aliases' => MbtiCanonicalSectionRegistry::traitOverviewAxisAliases(),
                    ]
                    : null,
            ];

            if (($definition['bucket'] ?? null) === MbtiCanonicalSectionRegistry::BUCKET_PREMIUM_TEASER) {
                $premiumTeaser[$sectionKey] = [
                    'section_key' => $sectionKey,
                    'render_variant' => (string) $definition['render_variant'],
                    'title' => null,
                    'teaser' => null,
                    'payload' => null,
                    'is_premium_teaser' => true,
                ];

                continue;
            }

            $sections[$sectionKey] = $entry;
        }

        return [
            'type_code' => $identity->typeCode,
            'base_type_code' => $identity->baseTypeCode,
            'variant' => $identity->variant,
            'profile' => [
                'hero_summary' => null,
            ],
            'sections' => $sections,
            'premium_teaser' => $premiumTeaser,
            'seo_meta' => array_fill_keys(self::seoFieldKeys(), null),
            '_meta' => [
                'authority_source' => $authoritySource,
                'schema_version' => self::SCHEMA_VERSION,
                'scaffold' => true,
                'resolved_type_code' => $identity->typeCode,
            ],
        ];
    }
}
