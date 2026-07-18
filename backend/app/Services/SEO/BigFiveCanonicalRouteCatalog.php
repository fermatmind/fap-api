<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Models\PersonalityPublicContentAsset;

final class BigFiveCanonicalRouteCatalog
{
    public const EN_REDIRECT_ONLY_ALIAS_TARGETS = [
        'emotional-stability' => 'neuroticism-low',
        'high-agreeableness' => 'agreeableness-high',
        'high-conscientiousness' => 'conscientiousness-high',
        'high-extraversion' => 'extraversion-high',
        'high-neuroticism' => 'neuroticism-high',
        'high-openness' => 'openness-high',
        'low-agreeableness' => 'agreeableness-low',
        'low-conscientiousness' => 'conscientiousness-low',
        'low-extraversion' => 'extraversion-low',
        'low-openness' => 'openness-low',
    ];

    public const ZH_REDIRECT_ONLY_ALIASES = [
        'emotional-stability',
        'high-agreeableness',
        'high-conscientiousness',
        'high-extraversion',
        'high-neuroticism',
        'high-openness',
        'low-agreeableness',
        'low-conscientiousness',
        'low-extraversion',
        'low-openness',
    ];

    /** @return array<string,string> */
    public static function redirectOnlyAliasTargets(string $locale): array
    {
        if (! in_array($locale, ['en', 'zh-CN'], true)) {
            return [];
        }

        return self::EN_REDIRECT_ONLY_ALIAS_TARGETS;
    }

    /** @return array<string,string> */
    public static function reviewedRedirectPaths(): array
    {
        $paths = [];
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
            foreach (self::redirectOnlyAliasTargets($locale) as $alias => $target) {
                $paths["/{$segment}/personality/big-five/{$alias}"] =
                    "/{$segment}/personality/big-five/{$target}";
            }
        }

        ksort($paths);

        return $paths;
    }

    public const DOMAINS = [
        'agreeableness',
        'conscientiousness',
        'extraversion',
        'neuroticism',
        'openness',
    ];

    public const V2_RANGES = [
        'agreeableness-high', 'agreeableness-mid', 'agreeableness-low',
        'conscientiousness-high', 'conscientiousness-mid', 'conscientiousness-low',
        'extraversion-high', 'extraversion-mid', 'extraversion-low',
        'neuroticism-high', 'neuroticism-mid', 'neuroticism-low',
        'openness-high', 'openness-mid', 'openness-low',
    ];

    public const FACETS = [
        'achievement-striving', 'actions', 'activity', 'aesthetics', 'altruism', 'anger',
        'anxiety', 'assertiveness', 'competence', 'compliance', 'deliberation', 'depression',
        'dutifulness', 'excitement-seeking', 'feelings', 'gregariousness', 'ideas', 'imagination',
        'impulsiveness', 'modesty', 'order', 'positive-emotions', 'self-consciousness',
        'self-discipline', 'straightforwardness', 'tender-mindedness', 'trust', 'values',
        'vulnerability', 'warmth',
    ];

    public static function expectedPath(string $locale, string $entityType, string $entityKey): ?string
    {
        $segment = match ($locale) {
            'en' => 'en',
            'zh-CN' => 'zh',
            default => null,
        };
        if ($segment === null) {
            return null;
        }

        $suffix = match ($entityType) {
            PersonalityPublicContentAsset::ENTITY_HUB => $entityKey === 'big-five' ? '' : null,
            PersonalityPublicContentAsset::ENTITY_DOMAIN => in_array($entityKey, self::DOMAINS, true)
                ? '/'.$entityKey
                : null,
            PersonalityPublicContentAsset::ENTITY_POLARITY => self::polaritySuffix($locale, $entityKey),
            PersonalityPublicContentAsset::ENTITY_FACET_HUB => $entityKey === 'facets' ? '/facets' : null,
            PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => in_array($entityKey, self::FACETS, true)
                ? '/facets/'.$entityKey
                : null,
            default => null,
        };

        return $suffix === null ? null : "/{$segment}/personality/big-five{$suffix}";
    }

    /** @return list<array{entity_type:string,entity_key:string,path:string}> */
    public static function canonicalEntries(string $locale): array
    {
        $entries = [
            [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
                'entity_key' => 'big-five',
            ],
            [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
                'entity_key' => 'facets',
            ],
        ];

        foreach (self::DOMAINS as $entityKey) {
            $entries[] = [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
                'entity_key' => $entityKey,
            ];
        }
        foreach (self::V2_RANGES as $entityKey) {
            $entries[] = [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_POLARITY,
                'entity_key' => $entityKey,
            ];
        }
        foreach (self::FACETS as $entityKey) {
            $entries[] = [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
                'entity_key' => $entityKey,
            ];
        }

        return array_values(array_map(static function (array $entry) use ($locale): array {
            $path = self::expectedPath($locale, $entry['entity_type'], $entry['entity_key']);

            return $entry + ['path' => (string) $path];
        }, $entries));
    }

    private static function polaritySuffix(string $locale, string $entityKey): ?string
    {
        if (in_array($entityKey, self::V2_RANGES, true)) {
            return '/'.$entityKey;
        }

        return null;
    }
}
