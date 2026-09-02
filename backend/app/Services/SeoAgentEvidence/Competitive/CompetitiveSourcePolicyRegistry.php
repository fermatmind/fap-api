<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Carbon\CarbonImmutable;
use RuntimeException;

final class CompetitiveSourcePolicyRegistry
{
    public const POLICY_VERSION = 'seo.competitive_source_policy.v3';

    public function __construct(private readonly SeoEvidenceCanonicalHasher $hasher) {}

    /** @return array<string, mixed> */
    public function sourceRegistry(): array
    {
        $registry = $this->verifiedAsset('registries/seo.competitive_source_registry.v2.json', 'registry_hash');
        foreach ((array) ($registry['sources'] ?? []) as $source) {
            if (! is_array($source) || ! $this->exactPublicUrl((string) ($source['url'] ?? ''))) {
                throw new RuntimeException('COMPETITIVE_SOURCE_URL_INVALID');
            }
            if (($source['policy_state'] ?? null) === 'approved'
                && (! is_string($source['policy_id'] ?? null) || ! hash_equals(
                    'competitive.source.'.(string) $source['source_id'].'.v3',
                    (string) $source['policy_id'],
                ))) {
                throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_RELATION_INVALID');
            }
            if (($source['policy_state'] ?? null) !== 'approved'
                && (! $this->exactPublicUrl((string) ($source['review_url'] ?? ''))
                    || ! hash_equals((string) ($source['review_url_hash'] ?? ''), $this->hasher->hash((string) $source['review_url'])))) {
                throw new RuntimeException('COMPETITIVE_SOURCE_REVIEW_HASH_INVALID');
            }
        }

        return $registry;
    }

    /** @return array<string, mixed> */
    public function cohortRegistry(): array
    {
        $registry = $this->verifiedAsset('registries/seo.competitive_cohort_registry.v2.json', 'registry_hash');
        $sources = $this->sourceRegistry();
        $policies = $this->policies();
        if (($registry['source_registry_version'] ?? null) !== ($sources['version'] ?? null)
            || ! hash_equals((string) ($registry['source_registry_hash'] ?? ''), (string) ($sources['registry_hash'] ?? ''))
            || ! hash_equals((string) ($registry['source_policy_set_hash'] ?? ''), $this->policySetHash($policies))) {
            throw new RuntimeException('COMPETITIVE_REGISTRY_RELATION_INVALID');
        }
        foreach ((array) ($registry['cohorts'] ?? []) as $cohort) {
            if (! is_array($cohort) || ! is_string($cohort['cohort_hash'] ?? null)
                || ! hash_equals($this->hasher->hashWithout($cohort, 'cohort_hash'), (string) $cohort['cohort_hash'])) {
                throw new RuntimeException('COMPETITIVE_COHORT_HASH_INVALID');
            }
            $this->sourcesFor($cohort);
        }

        return $registry;
    }

    /** @return array<string, array<string, mixed>> */
    public function policies(): array
    {
        $registry = $this->sourceRegistry();
        $policies = [];
        foreach ((array) ($registry['sources'] ?? []) as $source) {
            if (! is_array($source) || ($source['policy_state'] ?? null) !== 'approved') {
                continue;
            }
            $sourceId = (string) ($source['source_id'] ?? '');
            $policy = $this->verifiedAsset('policies/'.$sourceId.'.v3.json', 'policy_hash');
            $this->assertPolicy($policy, $source, (string) ($registry['registry_revision'] ?? ''));
            $policies[$sourceId] = $policy;
        }
        ksort($policies, SORT_STRING);

        return $policies;
    }

    /** @param array<string, mixed> $cohort @return list<array<string, mixed>> */
    public function sourcesFor(array $cohort): array
    {
        $sourcesById = [];
        foreach ((array) ($this->sourceRegistry()['sources'] ?? []) as $source) {
            if (is_array($source)) {
                $sourcesById[(string) ($source['source_id'] ?? '')] = $source;
            }
        }
        $policies = $this->policies();
        $result = [];
        $domains = [];
        foreach ((array) ($cohort['source_ids'] ?? []) as $sourceId) {
            $source = $sourcesById[(string) $sourceId] ?? null;
            if (! is_array($source) || ($source['policy_state'] ?? null) !== 'approved' || ! isset($policies[(string) $sourceId])) {
                throw new RuntimeException('COMPETITIVE_COHORT_SOURCE_MISSING');
            }
            if (($source['source_class'] ?? null) === 'competitor_public') {
                $host = (string) parse_url((string) $source['url'], PHP_URL_HOST);
                $domain = $this->registrableDomain($host);
                if (isset($domains[$domain])) {
                    throw new RuntimeException('COMPETITIVE_COHORT_DOMAIN_DUPLICATE');
                }
                $domains[$domain] = true;
            }
            $result[] = $source;
        }
        $competitors = count(array_filter($result, static fn (array $source): bool => ($source['source_class'] ?? null) === 'competitor_public'));
        if ($competitors < (int) ($cohort['minimum_competitor_sources'] ?? 2)) {
            throw new RuntimeException('COMPETITIVE_COHORT_INSUFFICIENT');
        }

        return $result;
    }

    /** @param array<string, mixed> $cohort */
    public function installForControlledCli(array $cohort, string $environment, string $releaseSha): void
    {
        if (! app()->runningInConsole()
            || ! in_array($environment, ['staging', 'production'], true)
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || env('SEO_COMPETITIVE_EXTERNAL_READ_ENABLED') !== true
            || env('SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED') !== true
            || (array) config('seo_agent_evidence.allowed_sources', []) !== []
            || (bool) config('seo_agent_evidence.agent_external_egress', false)) {
            throw new RuntimeException('COMPETITIVE_POLICY_INSTALL_HELD');
        }
        $policies = $this->policies();
        $allowed = [];
        foreach ($this->sourcesFor($cohort) as $source) {
            $policy = $policies[(string) $source['source_id']];
            $allowed[(string) $source['source_id']] = $this->gatewayPolicy($policy);
        }
        config()->set('seo_agent_evidence.allowed_sources', $allowed);
        config()->set('seo_agent_evidence.external_fetch_enabled', true);
        config()->set('seo_agent_evidence.bundle_write_enabled', true);
    }

    /** @return array<string, mixed> */
    public function snapshot(string $cohortId): array
    {
        $source = $this->sourceRegistry();
        $cohortRegistry = $this->cohortRegistry();
        $cohort = null;
        foreach ((array) $cohortRegistry['cohorts'] as $candidate) {
            if (is_array($candidate) && ($candidate['cohort_id'] ?? null) === $cohortId) {
                $cohort = $candidate;
                break;
            }
        }
        if (! is_array($cohort)) {
            throw new RuntimeException('COMPETITIVE_COHORT_NOT_REGISTERED');
        }
        $this->sourcesFor($cohort);

        return [
            'source_policy_version' => self::POLICY_VERSION,
            'source_policy_set_hash' => $this->policySetHash($this->policies()),
            'source_registry_version' => $source['version'],
            'source_registry_hash' => $source['registry_hash'],
            'cohort_registry_version' => $cohortRegistry['version'],
            'cohort_registry_hash' => $cohortRegistry['registry_hash'],
            'cohort_id' => $cohort['cohort_id'],
            'cohort_hash' => $cohort['cohort_hash'],
        ];
    }

    /** @param array<string, array<string, mixed>> $policies */
    private function policySetHash(array $policies): string
    {
        $hashes = array_values(array_map(static fn (array $policy): string => (string) $policy['policy_hash'], $policies));
        sort($hashes, SORT_STRING);

        return $this->hasher->hash($hashes);
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $source */
    private function assertPolicy(array $policy, array $source, string $revision): void
    {
        $reviewed = CarbonImmutable::parse((string) ($policy['reviewed_at'] ?? ''));
        $expires = CarbonImmutable::parse((string) ($policy['expires_at'] ?? ''));
        $expectedProhibitions = ['redirects', 'non_https', 'login', 'paywall', 'captcha', 'raw_html_retention', 'competitor_text_retention', 'query_strings', 'arbitrary_mission_urls'];
        if (($policy['version'] ?? null) !== self::POLICY_VERSION
            || ($policy['policy_version'] ?? null) !== 3
            || ($policy['source_id'] ?? null) !== ($source['source_id'] ?? null)
            || ($policy['source_class'] ?? null) !== ($source['source_class'] ?? null)
            || ($policy['source_registry_revision'] ?? null) !== $revision
            || ($policy['exact_source_url'] ?? null) !== ($source['url'] ?? null)
            || ($policy['policy_id'] ?? null) !== ($source['policy_id'] ?? null)
            || ! hash_equals((string) ($source['url_hash'] ?? ''), $this->hasher->hash((string) $source['url']))
            || ($policy['collection_state'] ?? null) !== 'approved'
            || ($policy['terms_review'] ?? null) !== 'approved'
            || ($policy['robots_decision'] ?? null) !== 'approved'
            || ($policy['retention_scope'] ?? null) !== ['url_hash', 'content_hash', 'structural_projection', 'review_decision']
            || $reviewed->greaterThan(now('UTC'))
            || $expires->lessThanOrEqualTo(now('UTC'))
            || $reviewed->diffInSeconds($expires) > 2592000
            || (int) ($policy['max_validity_seconds'] ?? 0) > 2592000) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_INVALID');
        }
        $sourceParts = $this->strictUrlParts((string) $policy['exact_source_url']);
        $expectedOrigin = 'https://'.strtolower((string) $sourceParts['host']);
        if (! hash_equals($expectedOrigin, (string) ($policy['exact_origin'] ?? ''))
            || ! hash_equals((string) ($sourceParts['path'] ?? '/'), (string) ($policy['exact_path'] ?? ''))
            || ! in_array($expectedOrigin, (array) ($policy['allowed_origins'] ?? []), true)
            || ! in_array((string) ($policy['exact_path'] ?? ''), (array) ($policy['allowed_path_prefixes'] ?? []), true)) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_ORIGIN_PATH_INVALID');
        }
        foreach ($expectedProhibitions as $key) {
            if (($policy['prohibitions'][$key] ?? null) !== true) {
                throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_PROHIBITION_INVALID');
            }
        }
        foreach (['terms', 'license'] as $kind) {
            $url = (string) ($policy[$kind.'_url'] ?? '');
            $parts = $this->strictUrlParts($url);
            $origin = 'https://'.strtolower((string) $parts['host']);
            if (! hash_equals((string) ($policy[$kind.'_url_hash'] ?? ''), $this->hasher->hash($url))
                || ! in_array($origin, (array) $policy['allowed_origins'], true)
                || ! in_array((string) ($parts['path'] ?? '/'), (array) $policy['allowed_path_prefixes'], true)) {
                throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_URL_HASH_INVALID');
            }
        }
        $robotsParts = $this->strictUrlParts((string) ($policy['robots_url'] ?? ''));
        if (! hash_equals('/robots.txt', (string) ($robotsParts['path'] ?? '/'))
            || ! in_array('https://'.strtolower((string) $robotsParts['host']), (array) $policy['allowed_origins'], true)
            || ! in_array('/robots.txt', (array) $policy['allowed_path_prefixes'], true)) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_ROBOTS_INVALID');
        }
        if (($policy['terms_url'] ?? null) === ($policy['license_url'] ?? null)
            && ($policy['combined_terms_license_scope'] ?? false) !== true) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_LICENSE_AS_TERMS');
        }
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function gatewayPolicy(array $policy): array
    {
        $hosts = array_map(static fn (string $origin): string => (string) parse_url($origin, PHP_URL_HOST), $policy['allowed_origins']);
        $exactUrls = array_values(array_unique([
            (string) $policy['exact_source_url'],
            (string) $policy['terms_url'],
            (string) $policy['license_url'],
            (string) $policy['robots_url'],
        ]));

        return $policy + [
            'allowed_hosts' => $hosts,
            'exact_allowed_urls' => $exactUrls,
            'allowed_protocols' => ['https'],
            'allowed_ports' => [443],
            'redirect_policy' => 0,
            'robots_required' => true,
            'allowed_content_types' => ['text/html', 'text/plain'],
            'terms_status' => 'approved',
            'terms_reviewed_at' => $policy['reviewed_at'],
            'login_required' => false,
            'technical_restriction_state' => 'permitted',
            'license_class' => $policy['source_class'] === 'fermatmind_public' ? 'first_party' : 'public_fact_permitted',
            'allowed_saved_fields' => ['structured_facts'],
            'max_snippet_chars' => 1,
            'retention_class' => 'external_structured_fact',
            'data_usage_purpose' => 'competitive_evidence',
            'terms_evidence_hash' => $policy['terms_content_hash'],
            'license_evidence_hash' => $policy['license_content_hash'],
        ];
    }

    private function registrableDomain(string $host): string
    {
        $parts = explode('.', strtolower($host));

        return implode('.', array_slice($parts, -2));
    }

    private function exactPublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (int) ($parts['port'] ?? 443) === 443;
    }

    /** @return array<string, mixed> */
    private function strictUrlParts(string $url): array
    {
        if (! $this->exactPublicUrl($url)) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_URL_INVALID');
        }
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '/');
        if ($path !== rawurldecode($path) || str_contains($path, '//')
            || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1) {
            throw new RuntimeException('COMPETITIVE_SOURCE_POLICY_PATH_INVALID');
        }

        return $parts;
    }

    /** @return array<string, mixed> */
    private function verifiedAsset(string $relative, string $hashField): array
    {
        $path = resource_path('seo-agent/evidence/competitive/'.$relative);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_string($decoded[$hashField] ?? null)
            || ! hash_equals($this->hasher->hashWithout($decoded, $hashField), (string) $decoded[$hashField])) {
            throw new RuntimeException('COMPETITIVE_REGISTRY_HASH_INVALID');
        }

        return $decoded;
    }
}
