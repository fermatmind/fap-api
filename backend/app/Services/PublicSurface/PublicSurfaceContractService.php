<?php

declare(strict_types=1);

namespace App\Services\PublicSurface;

final class PublicSurfaceContractService
{
    /**
     * @param  array{
     *   entry_surface:string,
     *   discoverability_keys?:array<int,string>,
     *   continue_reading_keys?:array<int,string>,
     *   canonical_url:?string,
     *   robots_policy?:?string,
     *   attribution_scope?:?string,
     *   scale_code?:?string,
     *   locale?:?string,
     *   fingerprint_seed?:array<string,mixed>
     * }  $context
     * @return array<string,mixed>
     */
    public function build(array $context): array
    {
        $entrySurface = trim((string) ($context['entry_surface'] ?? ''));
        $canonicalUrl = $this->normalizeString($context['canonical_url'] ?? null);
        $robotsPolicy = $this->normalizeString($context['robots_policy'] ?? 'noindex,follow') ?? 'noindex,follow';
        $attributionScope = $this->normalizeString($context['attribution_scope'] ?? 'public_share_surface') ?? 'public_share_surface';
        $discoverabilityKeys = $this->normalizeStringList($context['discoverability_keys'] ?? []);
        $continueReadingKeys = $this->normalizeStringList($context['continue_reading_keys'] ?? []);

        $fingerprintSeed = is_array($context['fingerprint_seed'] ?? null)
            ? $context['fingerprint_seed']
            : [];
        $fingerprintSeed['entry_surface'] = $entrySurface;
        $fingerprintSeed['canonical_url'] = $canonicalUrl;
        $fingerprintSeed['robots_policy'] = $robotsPolicy;
        $fingerprintSeed['discoverability_keys'] = $discoverabilityKeys;
        $fingerprintSeed['continue_reading_keys'] = $continueReadingKeys;
        $fingerprintSeed['attribution_scope'] = $attributionScope;
        $fingerprintSeed['scale_code'] = $this->normalizeString($context['scale_code'] ?? null);
        $fingerprintSeed['locale'] = $this->normalizeString($context['locale'] ?? null);

        return [
            'version' => 'public.surface.v1',
            'entry_surface' => $entrySurface,
            'public_summary_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'discoverability_keys' => $discoverabilityKeys,
            'continue_reading_keys' => $continueReadingKeys,
            'canonical_url' => $canonicalUrl,
            'robots_policy' => $robotsPolicy,
            'attribution_scope' => $attributionScope,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $item = $this->normalizeString($value);
            if ($item === null) {
                continue;
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }
}
