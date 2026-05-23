<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class UrlTruthDriftIssueCandidateSource
{
    /**
     * @return array{candidates: list<DriftIssueCandidate>, metadata: array<string, mixed>, issues: list<string>}
     */
    public function candidates(?int $limit = null): array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (
                ! Schema::connection($connectionName)->hasTable('seo_urls')
                || ! Schema::connection($connectionName)->hasTable('seo_url_entities')
            ) {
                return $this->unavailable('url_truth_tables_missing');
            }

            $rows = DB::connection($connectionName)
                ->table('seo_urls')
                ->select([
                    'canonical_url_hash',
                    'locale',
                    'page_entity_type',
                    'entity_id_or_slug',
                    'cluster',
                    'source_authority',
                    'indexability_state',
                    'lastmod_at',
                    'is_private_flow',
                ])
                ->orderBy('canonical_url_hash')
                ->limit($limit === null ? 500 : max(1, $limit * 4))
                ->get();
        } catch (Throwable) {
            return $this->unavailable('url_truth_source_unavailable');
        }

        $candidates = [];
        $sourceAuthorities = [];

        foreach ($rows as $row) {
            $sourceAuthority = (string) ($row->source_authority ?? '');
            $sourceAuthorityKey = in_array($sourceAuthority, $this->allowedSourceAuthorities(), true)
                ? $sourceAuthority
                : 'forbidden_source_authority_redacted';
            $sourceAuthorities[$sourceAuthorityKey] = ($sourceAuthorities[$sourceAuthorityKey] ?? 0) + 1;

            foreach ($this->issuesForRow($connectionName, $row) as $candidate) {
                $candidates[$candidate->issueUid()] = $candidate;
            }
        }

        $candidates = array_values($candidates);
        usort($candidates, static fn (DriftIssueCandidate $a, DriftIssueCandidate $b): int => strcmp($a->issueUid(), $b->issueUid()));

        if ($limit !== null) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        ksort($sourceAuthorities);

        return [
            'candidates' => $candidates,
            'metadata' => [
                'source_tables' => ['seo_urls', 'seo_url_entities'],
                'target_tables' => ['seo_issue_queue'],
                'url_rows_seen' => $rows->count(),
                'source_authority_breakdown' => $sourceAuthorities,
                'node2_local_laravel_data_source' => false,
                'node2_local_db_data_source' => false,
                'frontend_fallback_data_source' => false,
                'static_sitemap_fallback_data_source' => false,
                'static_llms_fallback_data_source' => false,
                'external_api_calls_attempted' => false,
                'production_log_read_attempted' => false,
                'public_html_crawl_attempted' => false,
            ],
            'issues' => [],
        ];
    }

    /**
     * @return list<DriftIssueCandidate>
     */
    private function issuesForRow(string $connectionName, object $row): array
    {
        $issues = [];
        $canonicalUrlHash = $this->stringOrNull($row->canonical_url_hash ?? null);
        $locale = $this->stringOrNull($row->locale ?? null);
        $pageEntityType = $this->stringOrNull($row->page_entity_type ?? null);
        $entityIdOrSlug = $this->safeIdentifier($this->stringOrNull($row->entity_id_or_slug ?? null));
        $cluster = $this->stringOrNull($row->cluster ?? null);
        $sourceAuthority = (string) ($row->source_authority ?? '');
        $sourceAuthorityAllowed = in_array($sourceAuthority, $this->allowedSourceAuthorities(), true);
        $indexabilityState = (string) ($row->indexability_state ?? '');
        $isPrivateFlow = (bool) ($row->is_private_flow ?? false);
        $entityMappingCount = $this->entityMappingCount($connectionName, $canonicalUrlHash, $locale);
        $baseMetadata = [
            'source_authority' => $sourceAuthorityAllowed ? $sourceAuthority : 'forbidden_source_authority_redacted',
            'source_authority_allowed' => $sourceAuthorityAllowed,
            'indexability_state' => $indexabilityState,
            'entity_mapping_count' => $entityMappingCount,
            'raw_evidence_included' => false,
        ];

        if (! $sourceAuthorityAllowed) {
            $issues[] = $this->candidate(
                'forbidden_source_authority_detected',
                'high',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'URL Truth row uses a non-approved source authority.',
                'Review source authority wiring before expanding SEO Intelligence writes.',
                $baseMetadata,
            );
        }

        if ($pageEntityType === null || ! in_array($pageEntityType, $this->allowedPageEntityTypes(), true)) {
            $issues[] = $this->candidate(
                'unsupported_page_entity_type',
                'warning',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'URL Truth row uses an unsupported page entity type.',
                'Keep unsupported page types out of production SEO observations until explicitly approved.',
                $baseMetadata,
            );
        }

        if ($isPrivateFlow || in_array((string) $pageEntityType, $this->forbiddenPageEntityTypes(), true)) {
            $issues[] = $this->candidate(
                'forbidden_private_flow_indexable',
                'high',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'Private-flow page boundary appeared in URL Truth inventory.',
                'Confirm private flows remain non-indexable and excluded from public search channels.',
                $baseMetadata,
            );
        }

        if ($indexabilityState === '' || in_array($indexabilityState, ['unknown', 'unset'], true)) {
            $issues[] = $this->candidate(
                'missing_or_unknown_indexability_state',
                'warning',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'URL Truth row has missing or unknown indexability state.',
                'Set an explicit indexability state before using the row for downstream search-channel decisions.',
                $baseMetadata,
            );
        }

        if ($entityMappingCount === 0 && $entityIdOrSlug !== null && $entityIdOrSlug !== '') {
            $issues[] = $this->candidate(
                'missing_url_entity_mapping',
                'warning',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'URL Truth row has no matching URL entity mapping.',
                'Backfill or verify seo_url_entities before using this page in richer SEO dashboards.',
                $baseMetadata,
            );
        }

        if ($indexabilityState === 'indexable' && ($row->lastmod_at ?? null) === null) {
            $issues[] = $this->candidate(
                'missing_lastmod_for_indexable_url',
                'info',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'Indexable URL Truth row has no lastmod timestamp.',
                'Treat this as a low-severity observation until an approved backend lastmod source is wired.',
                $baseMetadata,
            );
        }

        if ($issues === [] && in_array($sourceAuthority, $this->allowedSourceAuthorities(), true)) {
            $issues[] = $this->candidate(
                'url_truth_canary_observation',
                'info',
                $canonicalUrlHash,
                $locale,
                $pageEntityType,
                $entityIdOrSlug,
                $cluster,
                'URL Truth row is available for bounded drift canary observation.',
                'Use this sanitized observation to validate seo_issue_queue write plumbing only.',
                $baseMetadata,
            );
        }

        return $issues;
    }

    private function entityMappingCount(string $connectionName, ?string $canonicalUrlHash, ?string $locale): int
    {
        if ($canonicalUrlHash === null || $locale === null) {
            return 0;
        }

        return DB::connection($connectionName)
            ->table('seo_url_entities')
            ->where('canonical_url_hash', $canonicalUrlHash)
            ->where('locale', $locale)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function candidate(
        string $issueType,
        string $severity,
        ?string $canonicalUrlHash,
        ?string $locale,
        ?string $pageEntityType,
        ?string $entityIdOrSlug,
        ?string $cluster,
        string $summary,
        string $recommendation,
        array $metadata,
    ): DriftIssueCandidate {
        return new DriftIssueCandidate(
            issueType: $issueType,
            severity: $severity,
            canonicalUrlHash: $canonicalUrlHash,
            locale: $locale,
            pageEntityType: $pageEntityType,
            entityIdOrSlug: $entityIdOrSlug,
            cluster: $cluster,
            summary: $summary,
            recommendation: $recommendation,
            metadata: $this->sanitizeMetadata($metadata),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $forbidden = $this->forbiddenFieldFragments();

        return array_filter(
            $metadata,
            static function (mixed $value, string $key) use ($forbidden): bool {
                $normalized = strtolower($key);

                foreach ($forbidden as $fragment) {
                    if (str_contains($normalized, $fragment)) {
                        return false;
                    }
                }

                return ! is_string($value) || ! preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    private function allowedPageEntityTypes(): array
    {
        return $this->stringList(config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
    }

    /**
     * @return list<string>
     */
    private function forbiddenPageEntityTypes(): array
    {
        return $this->stringList(config('seo_intel.url_truth_inventory.forbidden_page_entity_types', []));
    }

    /**
     * @return list<string>
     */
    private function allowedSourceAuthorities(): array
    {
        return $this->stringList(config('seo_intel.url_truth_inventory.allowed_source_authorities', []));
    }

    /**
     * @return list<string>
     */
    private function forbiddenFieldFragments(): array
    {
        return [
            'email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_cookie',
            'raw_ip',
            'raw_user_agent',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'token',
            'api_key',
            'secret',
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => (string) $item, $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function safeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $masked = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $value) ?? $value;
        $masked = preg_replace('/\b(?:order|attempt|payment|provider)[-_ ]?[A-Z0-9]{6,}\b/i', '[redacted]', $masked) ?? $masked;
        $masked = preg_replace('/\b[A-F0-9]{16,}\b/i', '[redacted]', $masked) ?? $masked;

        return $masked === '' ? null : $masked;
    }

    /**
     * @return array{candidates: list<DriftIssueCandidate>, metadata: array<string, mixed>, issues: list<string>}
     */
    private function unavailable(string $reason): array
    {
        return [
            'candidates' => [],
            'metadata' => [
                'source_tables' => ['seo_urls', 'seo_url_entities'],
                'target_tables' => ['seo_issue_queue'],
                'url_rows_seen' => 0,
                'source_authority_breakdown' => [],
                'node2_local_laravel_data_source' => false,
                'node2_local_db_data_source' => false,
                'frontend_fallback_data_source' => false,
                'static_sitemap_fallback_data_source' => false,
                'static_llms_fallback_data_source' => false,
                'external_api_calls_attempted' => false,
                'production_log_read_attempted' => false,
                'public_html_crawl_attempted' => false,
            ],
            'issues' => [$reason],
        ];
    }
}
