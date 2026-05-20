<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

final class SearchChannelQueueEligibilityEvaluator
{
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

        if (! $this->isValidCanonical($canonicalUrl)) {
            $reasonCodes[] = 'canonical_url_invalid';
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

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '';
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
