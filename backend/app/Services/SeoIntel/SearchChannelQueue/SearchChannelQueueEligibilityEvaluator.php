<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyGuard;
use App\Support\CanonicalFrontendUrl;

final class SearchChannelQueueEligibilityEvaluator
{
    public function __construct(
        private readonly ?PageFamilyPolicyGuard $pageFamilyPolicyGuard = null,
    ) {}

    /** @var array<string, true> */
    private const PRIVATE_ROOTS = [
        'account' => true,
        'admin' => true,
        'api' => true,
        'checkout' => true,
        'history' => true,
        'login' => true,
        'me' => true,
        'ops' => true,
        'order' => true,
        'orders' => true,
        'pay' => true,
        'payment' => true,
        'payments' => true,
        'report' => true,
        'reports' => true,
        'result' => true,
        'results' => true,
        'share' => true,
        'shares' => true,
        'take' => true,
    ];

    /** @var array<string, true> */
    private const LOCALE_SEGMENTS = [
        'en' => true,
        'zh' => true,
        'zh-cn' => true,
        'zh-tw' => true,
    ];

    /**
     * @param  array<string, mixed>  $url
     */
    public function evaluate(array $url): SearchChannelQueueEligibilityResult
    {
        $reasonCodes = [];
        $metadata = $this->metadata($url);
        $pageType = (string) ($url['page_entity_type'] ?? '');
        $sourceAuthority = (string) ($url['source_authority'] ?? '');
        $indexabilityState = (string) ($url['indexability_state'] ?? '');
        $canonicalUrl = (string) ($url['canonical_url'] ?? '');
        $pageFamilyDecision = ($this->pageFamilyPolicyGuard ?? new PageFamilyPolicyGuard)->evaluate([
            'canonical_url' => $canonicalUrl,
            'locale' => (string) ($url['locale'] ?? ''),
            'page_entity_type' => $pageType,
            'entity_source' => (string) ($url['entity_source'] ?? $metadata['entity_source'] ?? $url['source_table'] ?? $metadata['source_table'] ?? ''),
            'source_authority' => $sourceAuthority,
            'authority_status' => (string) ($url['authority_status'] ?? $metadata['authority_status'] ?? 'published_approved'),
            'indexability_state' => $indexabilityState,
            'is_private_flow' => (bool) ($url['is_private_flow'] ?? $metadata['private_flow'] ?? false),
        ], 'L1');

        if (($pageFamilyDecision['family_policy_allowed'] ?? false) !== true) {
            $reasonCodes[] = 'page_family_policy_blocked';
        }

        if (! $this->isValidCanonical($canonicalUrl)) {
            $reasonCodes[] = 'canonical_url_invalid';
        } elseif (self::publicPathFromCanonicalUrl($canonicalUrl) === null) {
            $reasonCodes[] = 'canonical_url_not_public';
        }

        if (! in_array($sourceAuthority, $this->approvedSourceAuthorities(), true)) {
            $reasonCodes[] = 'source_authority_not_approved';
        }

        if (in_array($sourceAuthority, $this->forbiddenSourceAuthorities(), true)) {
            $reasonCodes[] = 'source_authority_forbidden';
        }

        if (! in_array($pageType, $this->allowedPageTypes(), true)) {
            $reasonCodes[] = 'page_entity_type_not_allowed';
        }

        if (in_array($pageType, $this->forbiddenPageTypes(), true)) {
            $reasonCodes[] = 'page_entity_type_forbidden';
        }

        if ((bool) ($url['is_private_flow'] ?? false) || (bool) ($metadata['private_flow'] ?? false) || (bool) ($metadata['is_private'] ?? false)) {
            $reasonCodes[] = 'private_flow';
        }

        if ($indexabilityState !== 'indexable' || (bool) ($metadata['noindex'] ?? false) || (string) ($metadata['robots'] ?? '') === 'noindex') {
            $reasonCodes[] = 'noindex';
        }

        if ((bool) ($metadata['is_draft'] ?? false) || (string) ($metadata['publication_state'] ?? '') === 'draft') {
            $reasonCodes[] = 'draft';
        }

        if ((bool) ($metadata['stale_slug'] ?? false) || (string) ($metadata['slug_state'] ?? '') === 'stale') {
            $reasonCodes[] = 'stale_slug';
        }

        $claimBoundaryState = $this->claimBoundaryState($metadata);
        if ($claimBoundaryState !== 'claim_safe') {
            $reasonCodes[] = 'claim_unsafe';
        }

        if ((bool) ($metadata['frontend_fallback'] ?? false)) {
            $reasonCodes[] = 'frontend_fallback_source';
        }

        if ((bool) ($metadata['static_sitemap_fallback'] ?? false)) {
            $reasonCodes[] = 'static_sitemap_fallback_source';
        }

        if ((bool) ($metadata['static_llms_fallback'] ?? false)) {
            $reasonCodes[] = 'static_llms_fallback_source';
        }

        if ((bool) ($metadata['node2_local_db'] ?? false) || (string) ($metadata['source'] ?? '') === 'node2_local_db') {
            $reasonCodes[] = 'node2_local_db_source';
        }

        if ((bool) ($metadata['crawler_log_source'] ?? false) || (string) ($metadata['source'] ?? '') === 'crawler_log_source') {
            $reasonCodes[] = 'crawler_log_source';
        }

        if ((bool) ($metadata['external_search_source'] ?? false) || (string) ($metadata['source'] ?? '') === 'external_search_source') {
            $reasonCodes[] = 'external_search_source';
        }

        $reasonCodes = array_values(array_unique($reasonCodes));

        return new SearchChannelQueueEligibilityResult(
            eligible: $reasonCodes === [],
            eligibilityState: $reasonCodes === [] ? 'eligible' : 'blocked',
            claimBoundaryState: $claimBoundaryState,
            reasonCodes: $reasonCodes,
        );
    }

    /**
     * @return list<string>
     */
    private function allowedPageTypes(): array
    {
        return array_values(config('seo_intel.search_channel_queue.allowed_page_entity_types', []));
    }

    /**
     * @return list<string>
     */
    private function forbiddenPageTypes(): array
    {
        return array_values(config('seo_intel.search_channel_queue.forbidden_page_entity_types', []));
    }

    /**
     * @return list<string>
     */
    private function approvedSourceAuthorities(): array
    {
        return array_values(config('seo_intel.search_channel_queue.approved_source_authorities', []));
    }

    /**
     * @return list<string>
     */
    private function forbiddenSourceAuthorities(): array
    {
        return array_values(config('seo_intel.search_channel_queue.forbidden_source_authorities', []));
    }

    private function isValidCanonical(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && is_string($host) && $host !== '';
    }

    public static function normalizePublicPath(?string $value): ?string
    {
        $path = trim((string) $value);
        if ($path === ''
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/%(?:2e|2f|5c)/i', $path) === 1
            || preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $segments = array_values(array_filter(
            explode('/', strtolower($path)),
            static fn (string $segment): bool => $segment !== ''
        ));

        if (array_intersect($segments, ['.', '..']) !== []) {
            return null;
        }

        $rootIndex = isset($segments[0]) && isset(self::LOCALE_SEGMENTS[$segments[0]]) ? 1 : 0;
        $root = $segments[$rootIndex] ?? '';
        if ($root !== '' && isset(self::PRIVATE_ROOTS[$root])) {
            return null;
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public static function publicPathFromCanonicalUrl(?string $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! self::isOwnedHost($parts['host'])) {
            return null;
        }

        return self::normalizePublicPath((string) ($parts['path'] ?? '/'));
    }

    private static function isOwnedHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $configuredHost = parse_url(
            (string) config('seo_intel.public_canonical_host', CanonicalFrontendUrl::APEX_URL),
            PHP_URL_HOST
        );
        $configuredHost = is_string($configuredHost) ? strtolower(rtrim($configuredHost, '.')) : '';
        $allowedHosts = array_filter([$configuredHost]);

        if (in_array($configuredHost, ['fermatmind.com', 'www.fermatmind.com'], true)) {
            $allowedHosts[] = 'fermatmind.com';
            $allowedHosts[] = 'www.fermatmind.com';
        }

        return in_array($host, array_values(array_unique($allowedHosts)), true);
    }

    /**
     * @param  array<string, mixed>  $url
     * @return array<string, mixed>
     */
    private function metadata(array $url): array
    {
        $metadata = $url['metadata_json'] ?? [];

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function claimBoundaryState(array $metadata): string
    {
        if (($metadata['claim_safe'] ?? null) === false) {
            return 'claim_unsafe';
        }

        $state = (string) ($metadata['claim_boundary_state'] ?? 'claim_safe');

        return in_array($state, ['claim_safe', 'safe', 'approved'], true) ? 'claim_safe' : 'claim_unsafe';
    }
}
