<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use Illuminate\Support\Collection;

final class SeoTechnicalAuditReadService extends AbstractSeoDashboardReadService
{
    private const CHECKS = [
        'sitemap',
        'robots',
        'canonical',
        'cms_indexability',
        'public_html',
        'index_evidence',
        'external_source',
    ];

    /** @return array<string,mixed> */
    public function read(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $issues = $this->activeIssues();
        $rows = $issues
            ->map(fn (object $row): array => $this->auditRow($row))
            ->sortByDesc(fn (array $row): string => (string) ($row['detected_at'] ?? ''))
            ->values();

        return [
            'schema_version' => 'ops-seo-technical-audit-readonly.v1',
            'mode' => 'read_only',
            'state' => $rows->isEmpty() ? 'empty' : 'connected',
            'total_count' => $rows->count(),
            'summary' => [
                'single_url' => $rows->where('scope', 'single_url')->count(),
                'template' => $rows->where('scope', 'template')->count(),
                'site' => $rows->where('scope', 'site')->count(),
                'external_disconnected' => $rows->where('scope', 'external_disconnected')->count(),
            ],
            'checks' => collect(self::CHECKS)->map(function (string $check) use ($rows): array {
                $checkRows = $rows->where('check', $check);
                $disconnected = $checkRows->isEmpty() && in_array($check, ['public_html', 'index_evidence', 'external_source'], true);

                return [
                    'check' => $check,
                    'state' => $disconnected ? 'disconnected' : ($checkRows->isEmpty() ? 'no_observed_issue' : 'issue_observed'),
                    'issue_count' => $disconnected ? null : $checkRows->count(),
                    'affected_url_count' => $disconnected ? null : $checkRows
                        ->pluck('canonical_url_hash')
                        ->filter()
                        ->unique()
                        ->count(),
                ];
            })->all(),
            'rows' => $rows->take($limit)->all(),
            'sources' => $this->sourceStates(),
            'boundaries' => [
                'cms_write_allowed' => false,
                'search_submission_allowed' => false,
                'external_calls_attempted' => false,
                'writes_attempted' => false,
            ],
        ];
    }

    /** @return Collection<int,object> */
    private function activeIssues(): Collection
    {
        return $this->table('seo_issue_queue')
            ->select([
                'issue_uid', 'issue_type', 'severity', 'source_system', 'source_engine',
                'canonical_url_hash', 'canonical_url', 'locale', 'page_entity_type',
                'status', 'lifecycle_state', 'summary', 'recommendation', 'evidence_hash',
                'metadata_json', 'detected_at', 'updated_at',
            ])
            ->whereNotIn('status', ['resolved', 'verified', 'closed', 'ignored'])
            ->whereNotIn('lifecycle_state', ['resolved', 'closed', 'ignored'])
            ->get();
    }

    /** @return array<string,mixed> */
    private function auditRow(object $row): array
    {
        $metadata = $this->decodeJson($row->metadata_json ?? null);
        $check = $this->check((string) ($row->issue_type ?? ''), $metadata);
        $scope = $this->scope($row, $metadata);

        return [
            'issue_uid' => (string) $row->issue_uid,
            'check' => $check,
            'scope' => $scope,
            'root_cause' => (string) ($metadata['root_cause'] ?? $row->issue_type ?? 'unknown'),
            'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
            'canonical_url_hash' => isset($row->canonical_url_hash) ? (string) $row->canonical_url_hash : null,
            'template' => isset($metadata['template']) ? (string) $metadata['template'] : null,
            'locale' => isset($row->locale) ? (string) $row->locale : null,
            'page_entity_type' => isset($row->page_entity_type) ? (string) $row->page_entity_type : null,
            'severity' => (string) ($row->severity ?? 'info'),
            'summary' => isset($row->summary) ? (string) $row->summary : null,
            'recommended_action' => isset($row->recommendation) ? (string) $row->recommendation : 'human_review_required',
            'evidence' => [
                'source_system' => (string) ($row->source_system ?? 'unknown'),
                'source_engine' => isset($row->source_engine) ? (string) $row->source_engine : null,
                'evidence_hash' => isset($row->evidence_hash) ? (string) $row->evidence_hash : null,
                'public_html_status' => $metadata['public_html_status'] ?? null,
                'observed_canonical' => $metadata['observed_canonical'] ?? null,
                'observed_robots' => $metadata['observed_robots'] ?? null,
                'index_evidence' => $metadata['index_evidence'] ?? null,
            ],
            'detected_at' => $this->normalizeTimestamp($row->detected_at ?? $row->updated_at ?? null),
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function check(string $issueType, array $metadata): string
    {
        $haystack = mb_strtolower(implode(' ', [
            $issueType,
            (string) ($metadata['root_cause'] ?? ''),
            (string) ($metadata['field'] ?? ''),
        ]), 'UTF-8');

        return match (true) {
            str_contains($haystack, 'sitemap') => 'sitemap',
            str_contains($haystack, 'robots') => 'robots',
            str_contains($haystack, 'canonical') => 'canonical',
            str_contains($haystack, 'cwv') || str_contains($haystack, 'crux') || str_contains($haystack, 'pagespeed') => 'external_source',
            str_contains($haystack, 'html') || str_contains($haystack, 'http') => 'public_html',
            str_contains($haystack, 'index') && str_contains($haystack, 'evidence') => 'index_evidence',
            default => 'cms_indexability',
        };
    }

    /** @param array<string,mixed> $metadata */
    private function scope(object $row, array $metadata): string
    {
        $declared = (string) ($metadata['affected_scope'] ?? $metadata['scope'] ?? '');
        if (in_array($declared, ['single_url', 'template', 'site', 'external_disconnected'], true)) {
            return $declared;
        }
        if (($row->source_system ?? null) === 'external_connector' && empty($row->canonical_url_hash)) {
            return 'external_disconnected';
        }
        if (! empty($metadata['template']) || ! empty($metadata['template_id'])) {
            return 'template';
        }
        if (! empty($row->canonical_url_hash) || ! empty($row->canonical_url)) {
            return 'single_url';
        }

        return 'site';
    }

    /** @return array<string,array<string,mixed>> */
    private function sourceStates(): array
    {
        $crawlerRows = $this->table('seo_crawler_log_daily_aggregates')->count();
        $gscRows = $this->table('seo_gsc_daily')->count();

        return [
            'url_truth' => ['state' => $this->table('seo_urls')->exists() ? 'connected' : 'empty'],
            'issue_queue' => ['state' => 'connected'],
            'crawler_logs' => ['state' => $crawlerRows > 0 ? 'connected' : 'disconnected', 'observed_rows' => $crawlerRows > 0 ? $crawlerRows : null],
            'gsc_search_analytics' => ['state' => $gscRows > 0 ? 'connected' : 'disconnected', 'observed_rows' => $gscRows > 0 ? $gscRows : null],
            'field_cwv' => [
                'state' => 'disconnected',
                'provider_required' => 'CrUX_or_PageSpeed_field_data',
                'metrics' => null,
                'lighthouse_lab_substitution_allowed' => false,
            ],
        ];
    }
}
