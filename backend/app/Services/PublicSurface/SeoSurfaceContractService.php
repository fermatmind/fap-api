<?php

declare(strict_types=1);

namespace App\Services\PublicSurface;

final class SeoSurfaceContractService
{
    /**
     * @param  array{
     *   metadata_scope:string,
     *   surface_type:string,
     *   canonical_url:?string,
     *   robots_policy?:?string,
     *   title:?string,
     *   description:?string,
     *   og_payload?:array<string,mixed>,
     *   twitter_payload?:array<string,mixed>,
     *   alternates?:array<string,mixed>,
     *   structured_data?:mixed,
     *   indexability_state?:?string,
     *   sitemap_state?:?string,
     *   llms_exposure_state?:?string,
     *   share_safety_state?:?string,
     *   public_summary_fingerprint?:?string,
     *   runtime_artifact_ref?:?string,
     *   fingerprint_seed?:array<string,mixed>
     * } $context
     * @return array<string,mixed>
     */
    public function build(array $context): array
    {
        $metadataScope = $this->normalizeString($context['metadata_scope'] ?? null) ?? 'public_detail';
        $surfaceType = $this->normalizeString($context['surface_type'] ?? null) ?? 'public_surface';
        $canonicalUrl = $this->normalizeString($context['canonical_url'] ?? null);
        $robotsPolicy = $this->normalizeString($context['robots_policy'] ?? null) ?? 'index,follow';
        $title = $this->normalizeString($context['title'] ?? null);
        $description = $this->normalizeString($context['description'] ?? null);
        $alternates = $this->normalizeStringMap($context['alternates'] ?? []);
        $structuredDataKeys = $this->extractStructuredDataKeys($context['structured_data'] ?? null);

        $ogPayload = $this->normalizePayload($context['og_payload'] ?? [], $title, $description, $canonicalUrl);
        $twitterPayload = $this->normalizePayload($context['twitter_payload'] ?? [], $title, $description, null);

        $indexabilityState = $this->normalizeString($context['indexability_state'] ?? null)
            ?? $this->resolveIndexabilityState($robotsPolicy);
        $sitemapState = $this->normalizeString($context['sitemap_state'] ?? null)
            ?? ($indexabilityState === 'indexable' ? 'included' : 'excluded');
        $llmsExposureState = $this->normalizeString($context['llms_exposure_state'] ?? null)
            ?? ($indexabilityState === 'indexable' ? 'allow' : 'withhold');
        $shareSafetyState = $this->normalizeString($context['share_safety_state'] ?? null);
        $publicSummaryFingerprint = $this->normalizeString($context['public_summary_fingerprint'] ?? null);
        $runtimeArtifactRef = $this->normalizeString($context['runtime_artifact_ref'] ?? null);

        $fingerprintSeed = is_array($context['fingerprint_seed'] ?? null)
            ? $context['fingerprint_seed']
            : [];
        $fingerprintSeed['metadata_scope'] = $metadataScope;
        $fingerprintSeed['surface_type'] = $surfaceType;
        $fingerprintSeed['canonical_url'] = $canonicalUrl;
        $fingerprintSeed['robots_policy'] = $robotsPolicy;
        $fingerprintSeed['title'] = $title;
        $fingerprintSeed['description'] = $description;
        $fingerprintSeed['alternates'] = $alternates;
        $fingerprintSeed['og_payload'] = $ogPayload;
        $fingerprintSeed['twitter_payload'] = $twitterPayload;
        $fingerprintSeed['structured_data_keys'] = $structuredDataKeys;
        $fingerprintSeed['indexability_state'] = $indexabilityState;
        $fingerprintSeed['sitemap_state'] = $sitemapState;
        $fingerprintSeed['llms_exposure_state'] = $llmsExposureState;
        $fingerprintSeed['share_safety_state'] = $shareSafetyState;
        $fingerprintSeed['public_summary_fingerprint'] = $publicSummaryFingerprint;
        $fingerprintSeed['runtime_artifact_ref'] = $runtimeArtifactRef;

        return [
            'version' => 'seo.surface.v1',
            'metadata_contract_version' => 'seo.surface.v1',
            'metadata_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'metadata_scope' => $metadataScope,
            'surface_type' => $surfaceType,
            'canonical_url' => $canonicalUrl,
            'robots_policy' => $robotsPolicy,
            'title' => $title,
            'description' => $description,
            'og_payload' => $ogPayload,
            'twitter_payload' => $twitterPayload,
            'alternates' => $alternates,
            'structured_data_keys' => $structuredDataKeys,
            'indexability_state' => $indexabilityState,
            'sitemap_state' => $sitemapState,
            'llms_exposure_state' => $llmsExposureState,
            'share_safety_state' => $shareSafetyState,
            'public_summary_fingerprint' => $publicSummaryFingerprint,
            'runtime_artifact_ref' => $runtimeArtifactRef,
        ];
    }

    private function resolveIndexabilityState(string $robotsPolicy): string
    {
        $tokens = array_map(
            static fn (string $token): string => trim(strtolower($token)),
            explode(',', $robotsPolicy)
        );

        return in_array('noindex', $tokens, true) ? 'noindex' : 'indexable';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(array $payload, ?string $title, ?string $description, ?string $url): array
    {
        return array_filter([
            'title' => $this->normalizeString($payload['title'] ?? null) ?? $title,
            'description' => $this->normalizeString($payload['description'] ?? null) ?? $description,
            'image' => $this->normalizeString($payload['image'] ?? null),
            'type' => $this->normalizeString($payload['type'] ?? null),
            'card' => $this->normalizeString($payload['card'] ?? null),
            'url' => $this->normalizeString($payload['url'] ?? null) ?? $url,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $map
     * @return array<string,string>
     */
    private function normalizeStringMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $key => $value) {
            $normalizedValue = $this->normalizeString($value);
            if ($normalizedValue === null) {
                continue;
            }

            $normalized[(string) $key] = $normalizedValue;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function extractStructuredDataKeys(mixed $value): array
    {
        $keys = [];
        $this->collectStructuredDataKeys($value, $keys);
        $keys = array_values(array_unique(array_filter($keys)));
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<int,string>  $keys
     */
    private function collectStructuredDataKeys(mixed $value, array &$keys): void
    {
        if (! is_array($value)) {
            return;
        }

        $type = $value['@type'] ?? null;
        if (is_string($type) && trim($type) !== '') {
            $keys[] = trim($type);
        } elseif (is_array($type)) {
            foreach ($type as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $keys[] = trim($item);
                }
            }
        }

        foreach ($value as $child) {
            $this->collectStructuredDataKeys($child, $keys);
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
