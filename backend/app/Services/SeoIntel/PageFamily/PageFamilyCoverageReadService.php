<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\PageFamily;

use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\SeoIntel\OpsDashboard\GscProductionCloseoutReadService;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Throwable;

final class PageFamilyCoverageReadService
{
    public function __construct(
        private readonly ?PageFamilyPolicyRegistry $registry = null,
        private readonly ?PageFamilyClassifier $classifier = null,
        private readonly ?BackendAuthorityUrlTruthSource $backendAuthority = null,
        private readonly ?CareerDirectoryAuthorityService $careerAuthority = null,
    ) {}

    /** @return array<string,mixed> */
    public function read(): array
    {
        $registry = $this->registry ?? new PageFamilyPolicyRegistry;
        $registry->assertValid();
        $classifier = $this->classifier ?? new PageFamilyClassifier($registry);
        [$authorities, $sourceState, $career] = $this->authorities();

        $counts = [
            'classified' => 0,
            'unclassified' => 0,
            'ambiguous' => 0,
            'private_excluded' => 0,
        ];
        $familyLocale = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $familyId) {
            $familyLocale[$familyId] = ['zh-CN' => 0, 'en' => 0, 'unknown' => 0];
        }
        $sourceDistribution = [];
        $unclassifiedGroups = [];

        foreach ($authorities as $authority) {
            $classification = $classifier->classify($authority);
            $status = (string) $classification['classification_status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $source = (string) ($authority['source_authority'] ?? 'unknown');
            $sourceDistribution[$source] = ($sourceDistribution[$source] ?? 0) + 1;

            if ($status === 'classified') {
                $familyId = (string) $classification['family_id'];
                $locale = in_array($classification['locale'], ['zh-CN', 'en'], true)
                    ? (string) $classification['locale']
                    : 'unknown';
                $familyLocale[$familyId][$locale]++;

                continue;
            }

            if (in_array($status, ['unclassified', 'ambiguous'], true)) {
                $groupKey = implode('|', [
                    $status,
                    (string) ($authority['page_entity_type'] ?? 'unknown'),
                    (string) ($authority['entity_source'] ?? 'unknown'),
                    (string) ($classification['locale'] ?? 'unknown'),
                    implode(',', (array) ($classification['blocking_reasons'] ?? [])),
                ]);
                $unclassifiedGroups[$groupKey] ??= [
                    'classification_status' => $status,
                    'page_entity_type' => (string) ($authority['page_entity_type'] ?? 'unknown'),
                    'entity_source' => (string) ($authority['entity_source'] ?? 'unknown'),
                    'source_authority' => $source,
                    'locale' => (string) ($classification['locale'] ?? 'unknown'),
                    'agent_risk_cap' => 'L0',
                    'allowed_action' => 'read_only_authority_registration_review',
                    'blocking_reasons' => (array) ($classification['blocking_reasons'] ?? []),
                    'count' => 0,
                ];
                $unclassifiedGroups[$groupKey]['count']++;
            }
        }

        ksort($sourceDistribution);
        ksort($unclassifiedGroups);
        $negativeSet = $this->negativeSetEvidence($registry, $classifier);
        $publicTotal = $counts['classified'] + $counts['unclassified'] + $counts['ambiguous'];

        return [
            'schema_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $registry->policyHash(),
            'registry_consumers' => ['detector', 'cms_lifecycle', 'agent', 'seo_operations'],
            'source_state' => $sourceState,
            'coverage' => [
                'current_public_authority_total' => $publicTotal,
                'exactly_one_public_family_count' => $counts['classified'],
                'unclassified_count' => $counts['unclassified'],
                'ambiguous_count' => $counts['ambiguous'],
                'private_excluded_authority_count' => $counts['private_excluded'],
                'complete' => $sourceState['backend_authority'] === 'available'
                    && $sourceState['career_authority'] === 'available'
                    && $counts['classified'] === $publicTotal,
            ],
            'family_locale_distribution' => $familyLocale,
            'authority_source_distribution' => $sourceDistribution,
            'private_negative_set' => $negativeSet,
            'career_authority' => $career,
            'url_truth_missing_handoff' => $this->urlTruthMissingHandoff(),
            'unclassified_read_only_queue' => array_values($unclassifiedGroups),
            'agent_risk_caps' => array_map(
                static fn (array $family): string => (string) $family['agent_risk_cap'],
                $registry->families(),
            ),
            'boundaries' => [
                'read_only' => true,
                'raw_query_emitted' => false,
                'raw_url_emitted' => false,
                'url_hash_emitted' => false,
                'private_path_example_emitted' => false,
                'url_truth_write_allowed' => false,
                'cms_publish_allowed' => false,
                'search_submission_allowed' => false,
                'canary_or_expansion_allowed_for_unclassified' => false,
            ],
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,string>,2:array<string,mixed>} */
    private function authorities(): array
    {
        $records = [];
        $sourceState = ['backend_authority' => 'unavailable', 'career_authority' => 'unavailable'];
        $career = [
            'authority_revision' => CareerDirectoryAuthorityService::AUTHORITY_VERSION,
            'localized_public_authority_count' => 0,
            'locale_distribution' => ['zh-CN' => 0, 'en' => 0],
            'sitemap_role' => 'consumer_consistency_only',
        ];

        try {
            $backend = $this->backendAuthority ?? app(BackendAuthorityUrlTruthSource::class);
            foreach ($backend->candidates() as $record) {
                if ($record instanceof UrlTruthInventoryRecord) {
                    $records[$record->canonicalUrlHash()] = $this->recordArray($record);
                }
            }
            foreach ($this->registeredStaticAuthorities() as $authority) {
                $records[hash('sha256', (string) $authority['canonical_url'])] = $authority;
            }
            $sourceState['backend_authority'] = 'available';
        } catch (Throwable) {
            $sourceState['backend_authority'] = 'unavailable';
        }

        try {
            $directory = $this->careerAuthority ?? app(CareerDirectoryAuthorityService::class);
            foreach (['zh-CN', 'en'] as $locale) {
                $items = $directory->indexableItems($locale, false);
                foreach ($items as $item) {
                    $path = trim((string) ($item['canonical_path'] ?? ''));
                    $slug = trim((string) ($item['slug'] ?? ''));
                    if ($path === '' || $slug === '') {
                        continue;
                    }
                    $canonical = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/').'/'.ltrim($path, '/');
                    $records[hash('sha256', $canonical)] = [
                        'canonical_url' => $canonical,
                        'locale' => $locale,
                        'page_entity_type' => 'career_job',
                        'entity_source' => 'career_directory_authority',
                        'source_authority' => 'career_runtime_publish_projection',
                        'authority_status' => 'published_approved',
                        'indexability_state' => 'indexable',
                        'is_private_flow' => false,
                    ];
                    $career['locale_distribution'][$locale]++;
                    $career['localized_public_authority_count']++;
                }
            }
            $sourceState['career_authority'] = 'available';
        } catch (Throwable) {
            $sourceState['career_authority'] = 'unavailable';
        }

        ksort($records);

        return [array_values($records), $sourceState, $career];
    }

    /** @return array<string,mixed> */
    private function recordArray(UrlTruthInventoryRecord $record): array
    {
        return [
            'canonical_url' => $record->canonicalUrl,
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_source' => $record->entitySource,
            'source_authority' => $record->sourceAuthority,
            'authority_status' => $record->authorityStatus,
            'indexability_state' => $record->indexabilityState,
            'is_private_flow' => $record->isPrivateFlow,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function registeredStaticAuthorities(): array
    {
        $base = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');
        $records = [];
        foreach ((new PageFamilyPolicyRegistry)->families() as $familyId => $family) {
            if (($family['public_family'] ?? false) !== true) {
                continue;
            }
            foreach ((array) data_get($family, 'authority.route_authority.exact_static_templates', []) as $path) {
                $path = (string) $path;
                $pageEntityType = match ($familyId) {
                    'tests' => 'test_hub',
                    'articles_topics' => str_contains($path, '/topics') ? 'topic_hub' : 'article_hub',
                    'career' => 'career_hub',
                    'personality' => 'personality_hub',
                    'trust_method_help' => 'support_hub',
                    'other_public' => in_array($path, ['/', '/en'], true) ? 'home' : 'business_hub',
                    default => null,
                };
                if ($pageEntityType === null) {
                    continue;
                }
                $records[] = [
                    'canonical_url' => $base.($path === '/' ? '/' : '/'.ltrim($path, '/')),
                    'locale' => $path === '/' || str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                    'page_entity_type' => $pageEntityType,
                    'entity_source' => $pageEntityType === 'home' ? 'backend_authority' : 'landing_surfaces',
                    'source_authority' => 'backend_public_surface',
                    'authority_status' => 'published_approved',
                    'indexability_state' => 'indexable',
                    'is_private_flow' => false,
                ];
            }
        }

        return $records;
    }

    /** @return array<string,mixed> */
    private function negativeSetEvidence(PageFamilyPolicyRegistry $registry, PageFamilyClassifier $classifier): array
    {
        $probes = $registry->negativeSetProbes();
        $leaks = 0;
        foreach ($probes as $probe) {
            if (($classifier->classify($probe)['classification_status'] ?? '') !== 'private_excluded') {
                $leaks++;
            }
        }

        return [
            'test_count' => count($probes),
            'public_family_leak_count' => $leaks,
            'all_permanently_excluded' => $leaks === 0,
        ];
    }

    /** @return array<string,mixed> */
    private function urlTruthMissingHandoff(): array
    {
        try {
            $closeout = app(GscProductionCloseoutReadService::class)->read();

            return [
                'target_task' => 'SEO-PLATFORM-05',
                'current_count' => (int) data_get($closeout, 'unmapped_classification.current_url_truth_missing_handoff_count', 0),
                'family_distribution' => (array) data_get($closeout, 'unmapped_classification.current_url_truth_missing_distribution.page_family', []),
                'locale_distribution' => (array) data_get($closeout, 'unmapped_classification.current_url_truth_missing_distribution.locale', []),
                'write_or_repair_performed' => false,
            ];
        } catch (Throwable) {
            return [
                'target_task' => 'SEO-PLATFORM-05',
                'current_count' => null,
                'family_distribution' => [],
                'locale_distribution' => [],
                'write_or_repair_performed' => false,
            ];
        }
    }
}
