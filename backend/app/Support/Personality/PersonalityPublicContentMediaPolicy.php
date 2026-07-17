<?php

declare(strict_types=1);

namespace App\Support\Personality;

final class PersonalityPublicContentMediaPolicy
{
    /** @param array<string,mixed> $seo @return array<string,mixed> */
    public static function sanitizeSeo(array $seo): array
    {
        unset($seo['og_image_url'], $seo['twitter_image_url'], $seo['image'], $seo['image_url']);
        foreach (['og', 'open_graph', 'twitter', 'twitter_card'] as $group) {
            if (is_array($seo[$group] ?? null)) {
                unset($seo[$group]['image'], $seo[$group]['image_url']);
            }
        }

        return $seo;
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    public static function sanitizeAuthority(array $authority): array
    {
        unset($authority['media'], $authority['media_authority'], $authority['media_deferred_by_operator']);

        return $authority;
    }
}
