<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;

final class CurrentPublicUrlAuthoritySource implements UrlTruthInventorySource
{
    public function __construct(
        private readonly BackendAuthorityUrlTruthSource $backendAuthority,
        private readonly CareerDirectoryAuthorityService $careerAuthority,
        private readonly PageFamilyPolicyRegistry $policyRegistry,
        private readonly CareerContentV3AuthorityPackage $careerCurrentAuthority,
        private readonly CareerContentV3CanonicalReader $careerCurrentReader,
    ) {}

    /** @return list<UrlTruthInventoryRecord> */
    public function candidates(): array
    {
        // Re-read the installed package on each maintenance/job invocation. The reader
        // is a singleton and may retain a prior manifest within a long-lived process.
        $this->careerCurrentReader->forgetLoadedAuthority();
        $index = $this->careerCurrentReader->authority();
        $records = [
            ...(config('seo_intel.enabled', false)
                ? $this->backendAuthority->completeCandidates()
                : $this->backendAuthority->candidates()),
            ...$this->careerRecords($index),
            ...$this->careerCurrentManifestRecords($index),
            ...$this->staticRecords(),
        ];

        return PublicAuthorityCandidateResolver::resolve($records);
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return [
            'source' => 'current_backend_cms_public_url_authority',
            'complete_authority_read' => (bool) config('seo_intel.enabled', false),
            'backend_authority' => $this->backendAuthority->metadata(),
            'career_authority_revision' => CareerDirectoryAuthorityService::AUTHORITY_VERSION,
            'career_public_revision_kind' => 'career_current_file_sha256.v1',
            'career_current_manifest_authority' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'page_family_policy_hash' => $this->policyRegistry->policyHash(),
            'sitemap_is_authority' => false,
            'llms_is_authority' => false,
            'runtime_http_is_authority' => false,
        ];
    }

    /** @return list<UrlTruthInventoryRecord> */
    private function careerRecords(array $index): array
    {
        $records = [];
        foreach (['zh-CN', 'en'] as $locale) {
            $hasBrowseAuthority = false;
            try {
                $items = $this->careerAuthority->indexableItems($locale, false);
                if ($items === [] && config('seo_intel.enabled', false)) {
                    $hasBrowseAuthority = $this->careerAuthority->browseItems($locale, false) !== [];
                }
            } catch (\Throwable $exception) {
                if (config('seo_intel.enabled', false)) {
                    throw new \RuntimeException('PUBLIC_AUTHORITY_CAREER_UNAVAILABLE', 0, $exception);
                }
                $items = [];
            }
            // A published locale can legitimately have browsable identities but no
            // indexable bodies. Cache/read failures still throw above; only a truly
            // empty browse authority is indeterminate.
            if ($items === [] && config('seo_intel.enabled', false) && ! $hasBrowseAuthority) {
                throw new \RuntimeException('PUBLIC_AUTHORITY_CAREER_UNDETERMINED');
            }
            foreach ($items as $item) {
                $path = trim((string) ($item['canonical_path'] ?? ''));
                $slug = trim((string) ($item['slug'] ?? ''));
                if ($path === '' || $slug === '') {
                    continue;
                }
                // Publication remains the directory's qualified identity. The physical
                // Current file must agree; a stale compiled body flag must fail closed
                // rather than silently shrink the public denominator.
                $page = $this->careerCurrentAuthority->pageFromIndexForRuntime($index, $slug, $locale);
                if (! CareerContentV3CanonicalReader::sourceHasPublicBody($page)) {
                    throw new \RuntimeException('PUBLIC_AUTHORITY_CAREER_BODY_MISMATCH');
                }
                $revision = $index['entries'][$slug][$locale]['sha256'];
                $records[] = new UrlTruthInventoryRecord(
                    canonicalUrl: $this->canonicalUrl($path),
                    locale: $locale,
                    pageEntityType: 'career_job',
                    entityIdOrSlug: $slug,
                    sourceAuthority: 'career_runtime_publish_projection',
                    indexabilityState: 'indexable',
                    lastmodSource: 'career_directory_authority_revision',
                    cluster: 'career',
                    entitySource: 'career_directory_authority',
                    authorityStatus: 'published_approved',
                    metadata: [
                        'publication_state' => 'published',
                        'robots' => 'index,follow',
                        'canonical_self' => true,
                        'sitemap_eligible' => true,
                        'llms_eligible' => true,
                        'authority_revision' => $revision,
                    ],
                    attributes: ['authority_revision' => $revision],
                );
            }
        }

        return $records;
    }

    /** @return list<UrlTruthInventoryRecord> */
    private function careerCurrentManifestRecords(array $index): array
    {
        $revision = (string) data_get($index, 'manifest.aggregate_sha256');
        $records = [];

        foreach ($index['slugs'] as $slug) {
            foreach (['zh-CN' => 'zh', 'en' => 'en'] as $locale => $segment) {
                $path = '/'.$segment.'/career/jobs/'.$slug;
                $records[] = new UrlTruthInventoryRecord(
                    canonicalUrl: $this->canonicalUrl($path),
                    locale: $locale,
                    pageEntityType: 'career_job',
                    entityIdOrSlug: $slug,
                    sourceAuthority: 'career_current_manifest',
                    indexabilityState: 'indexable',
                    lastmodSource: 'career_current_manifest_revision',
                    cluster: 'career',
                    entitySource: 'career_current_manifest',
                    authorityStatus: 'canonical_identity_current',
                    metadata: [
                        'publication_state' => 'current',
                        'robots' => 'index,follow',
                        'canonical_self' => true,
                        'authority_revision' => $revision,
                    ],
                    attributes: ['authority_revision' => $revision],
                );
            }
        }

        return $records;
    }

    /** @return list<UrlTruthInventoryRecord> */
    private function staticRecords(): array
    {
        $records = [];
        $revision = $this->policyRegistry->policyHash();
        foreach ($this->policyRegistry->families() as $familyId => $family) {
            if (($family['public_family'] ?? false) !== true) {
                continue;
            }
            foreach ((array) data_get($family, 'authority.route_authority.exact_static_templates', []) as $path) {
                $path = (string) $path;
                $type = match ($familyId) {
                    'tests' => 'test_hub',
                    'articles_topics' => str_contains($path, '/topics') ? 'topic_hub' : 'article_hub',
                    'career' => 'career_hub',
                    'personality' => 'personality_hub',
                    'trust_method_help' => 'support_hub',
                    'other_public' => in_array($path, ['/', '/en'], true) ? 'home' : 'business_hub',
                    default => null,
                };
                if ($type === null) {
                    continue;
                }
                $records[] = new UrlTruthInventoryRecord(
                    canonicalUrl: $this->canonicalUrl($path),
                    locale: $path === '/' || str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                    pageEntityType: $type,
                    entityIdOrSlug: $this->staticIdentity($path),
                    sourceAuthority: 'backend_public_surface',
                    indexabilityState: 'indexable',
                    lastmodSource: 'page_family_policy_revision',
                    cluster: $familyId,
                    entitySource: $type === 'home' ? 'backend_authority' : 'landing_surfaces',
                    authorityStatus: 'published_approved',
                    metadata: [
                        'publication_state' => 'published',
                        'robots' => 'index,follow',
                        'canonical_self' => true,
                        'authority_revision' => $revision,
                    ],
                    attributes: ['authority_revision' => $revision],
                );
            }
        }

        return $records;
    }

    private function canonicalUrl(string $path): string
    {
        $base = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');

        return $path === '/' ? $base.'/' : $base.'/'.ltrim($path, '/');
    }

    private function staticIdentity(string $path): string
    {
        $identity = preg_replace('#^/(?:en|zh)(?:/|$)#', '/', $path) ?: $path;
        $identity = trim($identity, '/');

        return $identity === '' ? 'home' : $identity;
    }
}
