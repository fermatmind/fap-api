<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\PageFamily;

final class PageFamilyClassifier
{
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
        if ($entitySource === '') {
            $entitySource = $this->inferEntitySource($pageEntityType);
        }
        $sourceAuthority = strtolower(trim((string) ($authority['source_authority'] ?? '')));
        if ($sourceAuthority === '') {
            $sourceAuthority = $this->inferSourceAuthority($pageEntityType);
        }
        $authorityStatus = strtolower(trim((string) ($authority['authority_status'] ?? 'published_approved')));
        $indexabilityState = strtolower(trim((string) ($authority['indexability_state'] ?? 'indexable')));
        $locale = $this->normalizeLocale((string) ($authority['locale'] ?? ''), $path);

        $privateReasons = $this->privateReasons($authority, $path, $pageEntityType, $authorityStatus, $indexabilityState, $registry);
        if ($privateReasons !== []) {
            return $this->result('private_excluded', 'private_excluded', [], $locale, $privateReasons, $registry);
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
        $staticTypes = ['test_hub', 'article_hub', 'topic_hub', 'career_hub', 'personality_hub', 'support_hub', 'home', 'business_hub'];
        if (in_array($pageEntityType, $staticTypes, true)) {
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
        $automatedActionsAllowed = $status === 'classified';

        return [
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $registry->policyHash(),
            'family_id' => $familyId,
            'classification_status' => $status,
            'matched_family_ids' => $matches,
            'locale' => $locale,
            'agent_risk_cap' => (string) $policy['agent_risk_cap'],
            'automated_publication_allowed' => $automatedActionsAllowed,
            'search_submission_allowed' => $automatedActionsAllowed,
            'canary_allowed' => $automatedActionsAllowed,
            'expansion_allowed' => $automatedActionsAllowed,
            'operations_queue_eligible' => $status !== 'private_excluded',
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

    private function inferEntitySource(string $pageEntityType): string
    {
        return match ($pageEntityType) {
            'test_detail' => 'scales_registry',
            'test_hub', 'home' => 'backend_authority',
            'article' => 'articles',
            'article_hub', 'topic_hub' => 'landing_surfaces',
            'topic' => 'topics',
            'career_job', 'career_directory' => 'career_directory_authority',
            'career_recommendation' => 'career_recommendations',
            'career_guide' => 'career_guides',
            'career_hub', 'personality_hub', 'support_hub', 'business_hub' => 'landing_surfaces',
            'personality', 'personality_profile_comparison' => 'personality_profiles',
            'personality_profile_variant' => 'personality_profile_variants',
            'personality_public_content_asset' => 'personality_public_content_assets',
            'research_report' => 'research_reports',
            'content_page', 'methodology', 'dataset' => 'content_pages',
            'support_article' => 'support_articles',
            'interpretation_guide' => 'interpretation_guides',
            'landing_page' => 'landing_surfaces',
            'foundation_public_record' => 'foundation_public_records',
            default => '',
        };
    }

    private function inferSourceAuthority(string $pageEntityType): string
    {
        return match ($pageEntityType) {
            'test_detail' => 'scale_catalog',
            'test_hub', 'home' => 'backend_public_surface',
            'article_hub', 'topic_hub', 'career_hub', 'personality_hub', 'support_hub', 'business_hub' => 'backend_public_surface',
            'career_job', 'career_directory' => 'career_runtime_publish_projection',
            default => 'backend_cms',
        };
    }
}
