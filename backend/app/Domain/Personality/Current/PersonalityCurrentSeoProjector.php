<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

use App\Support\CanonicalFrontendUrl;
use App\Support\PublicMediaUrlGuard;
use UnexpectedValueException;

final class PersonalityCurrentSeoProjector
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{meta:array<string,mixed>,jsonld:array<string,mixed>,seo_surface_v1:array<string,mixed>}
     */
    public function project(array $payload): array
    {
        $surface = $this->requiredArray($payload, 'seo_surface_v1');
        $profile = $this->requiredArray($payload, 'profile');
        $projection = is_array($payload['mbti_public_projection_v1'] ?? null)
            ? $payload['mbti_public_projection_v1']
            : [];
        $seoMeta = is_array($payload['seo_meta'] ?? null) ? $payload['seo_meta'] : [];

        $title = $this->requiredString($surface, 'title');
        $description = $this->requiredString($surface, 'description');
        $canonical = $this->requiredString($surface, 'canonical_url');
        $robots = $this->requiredString($surface, 'robots_policy');
        $alternates = $this->requiredArray($surface, 'alternates');
        $ogPayload = $this->requiredArray($surface, 'og_payload');
        $twitterPayload = $this->requiredArray($surface, 'twitter_payload');

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta([
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $alternates,
            'og' => [
                'title' => $this->stringOr($ogPayload['title'] ?? null, $title),
                'description' => $this->stringOr($ogPayload['description'] ?? null, $description),
                'image' => $this->stringOrNull(
                    $seoMeta['og_image_url'] ?? data_get($projection, 'seo.og_image_url')
                ),
                'type' => $this->stringOr($ogPayload['type'] ?? null, 'article'),
            ],
            'twitter' => [
                'card' => $this->stringOr($twitterPayload['card'] ?? null, 'summary_large_image'),
                'title' => $this->stringOr($twitterPayload['title'] ?? null, $title),
                'description' => $this->stringOr($twitterPayload['description'] ?? null, $description),
                'image' => $this->stringOrNull(
                    $seoMeta['twitter_image_url'] ?? data_get($projection, 'seo.twitter_image_url')
                ),
            ],
            'robots' => $robots,
        ]);

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            'name' => $title,
            'description' => $description,
            'about' => [
                '@type' => 'DefinedTerm',
                'name' => $this->requiredString($profile, 'canonical_type_code'),
                'inDefinedTermSet' => $this->requiredString($profile, 'scale_code'),
            ],
            'mainEntityOfPage' => $canonical,
        ];
        $overrides = data_get($projection, 'seo.jsonld');
        if (! is_array($overrides) || $overrides === []) {
            $overrides = $seoMeta['jsonld_overrides_json'] ?? null;
        }
        if (is_array($overrides) && $overrides !== []) {
            $jsonLd = array_replace_recursive($jsonLd, $overrides);
        }
        $jsonLd['mainEntityOfPage'] = $canonical;

        return [
            'meta' => $meta,
            'jsonld' => CanonicalFrontendUrl::normalizeNestedUrls($jsonLd),
            'seo_surface_v1' => $surface,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function requiredArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new UnexpectedValueException("Current personality SEO field {$key} must be an object.");
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $this->stringOrNull($payload[$key] ?? null);
        if ($value === null) {
            throw new UnexpectedValueException("Current personality SEO field {$key} must be a non-empty string.");
        }

        return $value;
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        return $this->stringOrNull($value) ?? $fallback;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
