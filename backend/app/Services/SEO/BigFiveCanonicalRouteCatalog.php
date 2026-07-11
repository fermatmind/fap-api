<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Models\PersonalityPublicContentAsset;

final class BigFiveCanonicalRouteCatalog
{
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

    private const DOMAINS = [
        'agreeableness',
        'conscientiousness',
        'extraversion',
        'neuroticism',
        'openness',
    ];

    private const V2_RANGES = [
        'agreeableness-high', 'agreeableness-mid', 'agreeableness-low',
        'conscientiousness-high', 'conscientiousness-mid', 'conscientiousness-low',
        'extraversion-high', 'extraversion-mid', 'extraversion-low',
        'neuroticism-high', 'neuroticism-mid', 'neuroticism-low',
        'openness-high', 'openness-mid', 'openness-low',
    ];

    private const FACETS = [
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

    private static function polaritySuffix(string $locale, string $entityKey): ?string
    {
        if (in_array($entityKey, self::V2_RANGES, true)) {
            return '/'.$entityKey;
        }

        if ($locale === 'en' && in_array($entityKey, self::ZH_REDIRECT_ONLY_ALIASES, true)) {
            return '/'.$entityKey;
        }

        return null;
    }
}
