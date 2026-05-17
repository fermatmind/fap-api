<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SeoIssueSummaryService
{
    public function __construct(
        private readonly SeoIssueSanitizer $sanitizer = new SeoIssueSanitizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    public function summarize(array $issues): array
    {
        $safeIssues = array_map(fn (array $issue): array => $this->sanitizer->sanitize($issue), $issues);

        return [
            'cms_summary_read_only' => true,
            'cms_mutation_allowed' => false,
            'auto_publish_allowed' => false,
            'auto_pseo_allowed' => false,
            'raw_evidence_included' => false,
            'issue_count' => count($safeIssues),
            'issue_type_counts' => $this->counts($safeIssues, 'issue_type'),
            'severity_counts' => $this->counts($safeIssues, 'severity'),
            'lifecycle_counts' => $this->counts($safeIssues, 'lifecycle_state'),
            'source_system_counts' => $this->counts($safeIssues, 'source_system'),
            'summary_rows' => array_map(static fn (array $issue): array => [
                'issue_uid' => $issue['issue_uid'],
                'issue_type' => $issue['issue_type'],
                'severity' => $issue['severity'],
                'source_system' => $issue['source_system'],
                'source_engine' => $issue['source_engine'],
                'canonical_url_hash' => $issue['canonical_url_hash'],
                'locale' => $issue['locale'],
                'page_entity_type' => $issue['page_entity_type'],
                'cluster' => $issue['cluster'],
                'status' => $issue['status'],
                'lifecycle_state' => $issue['lifecycle_state'],
            ], $safeIssues),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
