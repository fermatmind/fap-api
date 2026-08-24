<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\PageFamily;

final class PageFamilyClassifier
{
    /** @var list<string> */
    private const STATIC_PAGE_ENTITY_TYPES = [
        'test_hub',
        'article_hub',
        'topic_hub',
        'career_hub',
        'personality_hub',
        'support_hub',
        'home',
        'business_hub',
    ];

    public function __construct(
        private readonly ?PageFamilyPolicyRegistry $registry = null,
    ) {}

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    public function classify(array $authority): array
    {
        $registry = $this->registry ?? new PageFamilyPolicyRegistry;
        $path = $this->canonicalPath($authority);
        $pageEntityType = strtolower(trim((string) ($authority['page_entity_type'] ?? '')));
        $entitySource = strtolower(trim((string) ($authority['entity_source'] ?? '')));
        $sourceAuthority = strtolower(trim((string) ($authority['source_authority'] ?? '')));
        $authorityStatus = strtolower(trim((string) ($authority['authority_status'] ?? 'published_approved')));
        $indexabilityState = strtolower(trim((string) ($authority['indexability_state'] ?? 'indexable')));
        $locale = $this->normalizeLocale((string) ($authority['locale'] ?? ''), $path);

        $privateReasons = $this->privateReasons($authority, $path, $pageEntityType, $authorityStatus, $indexabilityState, $registry);
        if ($privateReasons !== []) {
            return $this->result('private_excluded', 'private_excluded', [], $locale, $privateReasons, $registry);
        }

        $staticAuthority = $this->staticAuthorityDefaults($registry, $pageEntityType, $path);
        $missingReasons = [];
        if ($entitySource === '') {
            if ($staticAuthority !== null) {
                $entitySource = $staticAuthority['entity_source'];
            } else {
                $missingReasons[] = 'missing_entity_source';
            }
        }
        if ($sourceAuthority === '') {
            if ($staticAuthority !== null) {
                $sourceAuthority = $staticAuthority['source_authority'];
            } else {
                $missingReasons[] = 'missing_source_authority';
            }
        }
        if ($missingReasons !== []) {
            return $this->result('unclassified', 'unclassified', [], $locale, $missingReasons, $registry);
        }

        $matches = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $familyId) {
            $family = $registry->families()[$familyId];
            if ($this->matchesFamily($familyId, $family, $pageEntityType, $entitySource, $sourceAuthority, $path)) {
                $matches[] = $familyId;
            }
        }

        if (count($matches) !== 1) {
            $status = count($matches) > 1 ? 'ambiguous' : 'unclassified';
            $reasons = count($matches) > 1
                ? ['multiple_page_family_matches']
                : ['no_registered_page_family_match'];

            return $this->result('unclassified', $status, $matches, $locale, $reasons, $registry);
        }

        return $this->result($matches[0], 'classified', $matches, $locale, [], $registry);
    }

    /** @param array<string,mixed> $family */
    private function matchesFamily(
        string $familyId,
        array $family,
        string $pageEntityType,
        string $entitySource,
        string $sourceAuthority,
        string $path,
    ): bool {
        $authority = (array) ($family['authority'] ?? []);
        $typeMatch = in_array($pageEntityType, (array) ($authority['page_entity_types'] ?? []), true);
        $entitySourceMatch = in_array($entitySource, (array) ($authority['entity_sources'] ?? []), true);
        $sourceAuthorityMatch = in_array($sourceAuthority, (array) ($authority['source_authorities'] ?? []), true);
        $registeredAuthorityMatch = $typeMatch && $entitySourceMatch && $sourceAuthorityMatch;
        if (in_array($pageEntityType, self::STATIC_PAGE_ENTITY_TYPES, true)) {
            return $registeredAuthorityMatch
                && in_array($path, (array) data_get($authority, 'route_authority.exact_static_templates', []), true);
        }

        if ($familyId === 'tests' && in_array($path, ['/en/tests', '/zh/tests'], true)) {
            return $registeredAuthorityMatch || $pageEntityType === 'landing_page';
        }

        if ($familyId === 'other_public' && in_array($path, ['/', '/en'], true)) {
            return $registeredAuthorityMatch || $pageEntityType === 'home';
        }

        return $registeredAuthorityMatch;
    }

    /** @return list<string> */
    private function privateReasons(
        array $authority,
        string $path,
        string $pageEntityType,
        string $authorityStatus,
        string $indexabilityState,
        PageFamilyPolicyRegistry $registry,
    ): array {
        $reasons = [];
        if (($authority['is_private_flow'] ?? false) === true) {
            $reasons[] = 'private_flow_flag';
        }
        if (in_array($pageEntityType, $registry->privatePageEntityTypes(), true)) {
            $reasons[] = 'private_page_entity_type';
        }
        $segments = array_values(array_filter(explode('/', strtolower($path)), static fn (string $segment): bool => $segment !== ''));
        if (array_intersect($segments, $registry->privatePathSegments()) !== []) {
            $reasons[] = 'private_path_segment';
        }
        if (in_array($authorityStatus, $registry->nonPublicAuthorityStatuses(), true)) {
            $reasons[] = 'non_public_authority_status';
        }
        if (in_array($indexabilityState, ['private', 'blocked_private'], true)) {
            $reasons[] = 'private_indexability_state';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string,mixed> */
    private function result(
        string $familyId,
        string $status,
        array $matches,
        string $locale,
        array $reasons,
        PageFamilyPolicyRegistry $registry,
    ): array {
        $policy = $registry->families()[$familyId];

        return [
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $registry->policyHash(),
            'family_id' => $familyId,
            'classification_status' => $status,
            'matched_family_ids' => $matches,
            'locale' => $locale,
            'agent_risk_cap' => (string) $policy['agent_risk_cap'],
            'blocking_reasons' => $reasons,
        ];
    }

    /** @param array<string,mixed> $authority */
    private function canonicalPath(array $authority): string
    {
        $value = trim((string) ($authority['canonical_path'] ?? $authority['canonical_url'] ?? '/'));
        $path = preg_match('#^https?://#i', $value) === 1 ? parse_url($value, PHP_URL_PATH) : $value;
        if (! is_string($path) || $path === '') {
            return '/';
        }

        return '/'.ltrim(rtrim($path, '/'), '/');
    }

    private function normalizeLocale(string $locale, string $path): string
    {
        $normalized = strtolower(trim($locale));
        if (in_array($normalized, ['zh', 'zh-cn', 'zh_cn'], true)) {
            return 'zh-CN';
        }
        if (in_array($normalized, ['en', 'en-us', 'en_us'], true)) {
            return 'en';
        }
        if ($path === '/' || str_starts_with($path, '/zh/')) {
            return 'zh-CN';
        }
        if ($path === '/en' || str_starts_with($path, '/en/')) {
            return 'en';
        }

        return 'unknown';
    }

    /**
     * @return array{entity_source:string,source_authority:string}|null
     */
    private function staticAuthorityDefaults(
        PageFamilyPolicyRegistry $registry,
        string $pageEntityType,
        string $path,
    ): ?array {
        if (! in_array($pageEntityType, self::STATIC_PAGE_ENTITY_TYPES, true)) {
            return null;
        }

        $matches = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $familyId) {
            $authority = (array) ($registry->families()[$familyId]['authority'] ?? []);
            if (in_array($pageEntityType, (array) ($authority['page_entity_types'] ?? []), true)
                && in_array($path, (array) data_get($authority, 'route_authority.exact_static_templates', []), true)) {
                $matches[] = $familyId;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return [
            'entity_source' => $pageEntityType === 'home' ? 'backend_authority' : 'landing_surfaces',
            'source_authority' => 'backend_public_surface',
        ];
    }
}
