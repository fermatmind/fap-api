<?php

declare(strict_types=1);

namespace App\Support;

final class PublicSeoTitleNormalizer
{
    public static function withoutTrailingBrand(string $title): string
    {
        $trimmed = trim($title);
        $normalized = preg_replace('/(?:\s*\|\s*FermatMind)+\s*$/iu', '', $trimmed);

        if (! is_string($normalized) || trim($normalized) === '') {
            return $trimmed;
        }

        return trim($normalized);
    }

    /**
     * @param  array<string,mixed>  $seo
     * @return array<string,mixed>
     */
    public static function normalizeSeoPayload(array $seo): array
    {
        foreach (['title', 'og_title', 'twitter_title'] as $field) {
            if (is_string($seo[$field] ?? null)) {
                $seo[$field] = self::withoutTrailingBrand((string) $seo[$field]);
            }
        }

        return $seo;
    }
}
