<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;

final class CurrentPublicUrlAuthoritySource implements UrlTruthInventorySource
{
    public function __construct(
        private readonly BackendAuthorityUrlTruthSource $backendAuthority,
        private readonly CareerDirectoryAuthorityService $careerAuthority,
        private readonly PageFamilyPolicyRegistry $policyRegistry,
    ) {}

    /** @return list<UrlTruthInventoryRecord> */
    public function candidates(): array
    {
        $records = [
            ...$this->backendAuthority->candidates(),
            ...$this->careerRecords(),
            ...$this->staticRecords(),
        ];
        $unique = [];
        foreach ($records as $record) {
            $unique[$record->locale.'|'.$record->canonicalUrlHash()] ??= $record;
        }
        ksort($unique);

        return array_values($unique);
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return [
            'source' => 'current_backend_cms_public_url_authority',
            'backend_authority' => $this->backendAuthority->metadata(),
            'career_authority_revision' => CareerDirectoryAuthorityService::AUTHORITY_VERSION,
            'page_family_policy_hash' => $this->policyRegistry->policyHash(),
            'sitemap_is_authority' => false,
            'llms_is_authority' => false,
            'runtime_http_is_authority' => false,
        ];
    }

    /** @return list<UrlTruthInventoryRecord> */
    private function careerRecords(): array
    {
        $records = [];
        foreach (['zh-CN', 'en'] as $locale) {
            foreach ($this->careerAuthority->indexableItems($locale, false) as $item) {
                $path = trim((string) ($item['canonical_path'] ?? ''));
                $slug = trim((string) ($item['slug'] ?? ''));
                if ($path === '' || $slug === '') {
                    continue;
                }
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
                        'authority_revision' => CareerDirectoryAuthorityService::AUTHORITY_VERSION,
                    ],
                    attributes: ['authority_revision' => CareerDirectoryAuthorityService::AUTHORITY_VERSION],
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
