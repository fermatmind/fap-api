<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use RuntimeException;

final class CompetitiveSourceRegistry
{
    public function __construct(private readonly SeoEvidenceCanonicalHasher $hasher) {}

    /** @return array<string, mixed> */
    public function sourceRegistry(): array
    {
        return $this->verifiedAsset('seo.competitive_source_registry.v1.json', 'registry_hash');
    }

    /** @return array<string, mixed> */
    public function cohortRegistry(): array
    {
        return $this->verifiedAsset('seo.competitive_cohort_registry.v1.json', 'registry_hash');
    }

    /** @return array<string, mixed> */
    public function semanticRegistry(): array
    {
        return $this->verifiedAsset('seo.competitive_semantic_registry.v1.json', 'registry_hash');
    }

    /** @return array<string, mixed> */
    public function cohort(string $cohortId): array
    {
        foreach ((array) ($this->cohortRegistry()['cohorts'] ?? []) as $cohort) {
            if (is_array($cohort) && ($cohort['cohort_id'] ?? null) === $cohortId) {
                return $cohort;
            }
        }

        throw new RuntimeException('COMPETITIVE_COHORT_NOT_REGISTERED');
    }

    /** @return list<array<string, mixed>> */
    public function sourcesFor(array $cohort): array
    {
        $wanted = array_values((array) ($cohort['source_ids'] ?? []));
        $sources = [];
        foreach ((array) ($this->sourceRegistry()['sources'] ?? []) as $source) {
            if (is_array($source) && in_array($source['source_id'] ?? null, $wanted, true)) {
                $this->assertExactUrl((string) ($source['url'] ?? ''));
                $sources[(string) $source['source_id']] = $source;
            }
        }
        if (count($sources) !== count($wanted)) {
            throw new RuntimeException('COMPETITIVE_COHORT_SOURCE_MISSING');
        }

        return array_map(static fn (string $id): array => $sources[$id], $wanted);
    }

    private function assertExactUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (int) ($parts['port'] ?? 443) !== 443) {
            throw new RuntimeException('COMPETITIVE_SOURCE_URL_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function verifiedAsset(string $file, string $hashField): array
    {
        $path = resource_path('seo-agent/evidence/competitive/registries/'.$file);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_string($decoded[$hashField] ?? null)
            || ! hash_equals($this->hasher->hashWithout($decoded, $hashField), (string) $decoded[$hashField])) {
            throw new RuntimeException('COMPETITIVE_REGISTRY_HASH_INVALID');
        }

        return $decoded;
    }
}
