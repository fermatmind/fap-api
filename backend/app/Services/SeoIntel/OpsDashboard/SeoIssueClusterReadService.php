<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\GscDataQualityGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

final class SeoIssueClusterReadService extends AbstractSeoDashboardReadService
{
    private const SEVERITY_RANK = [
        'info' => 1,
        'low' => 2,
        'warning' => 3,
        'medium' => 3,
        'high' => 4,
        'critical' => 5,
    ];

    public function __construct(
        ?string $connectionName = null,
        private readonly SeoIssuePriorityScorer $priorityScorer = new SeoIssuePriorityScorer,
        private readonly GscDataQualityGate $gscDataQualityGate = new GscDataQualityGate,
    ) {
        parent::__construct($connectionName);
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{total_count:int,page:int,per_page:int,last_page:int,summary:array<string,int>,rows:list<array<string,mixed>>}
     */
    public function read(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $issues = $this->issueRows($filters);
        $clusters = $this->clusters($filters, $issues)->values();
        $total = $clusters->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'total_count' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'summary' => $this->decisionSummary($issues, $clusters),
            'rows' => $clusters->forPage($page, $perPage)->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,object>  $issues
     * @param  Collection<int,array<string,mixed>>  $clusters
     * @return array{affected_url_count:int,index_blocker_url_count:int,high_priority_cluster_count:int,overdue_task_count:int}
     */
    private function decisionSummary(Collection $issues, Collection $clusters): array
    {
        $activeIssues = $issues->filter(fn (object $row): bool => $this->isActiveIssue($row));

        return [
            'affected_url_count' => $activeIssues
                ->map(fn (object $row): ?string => $this->urlIdentity($row))
                ->filter()
                ->unique()
                ->count(),
            'index_blocker_url_count' => $activeIssues
                ->filter(fn (object $row): bool => $this->isIndexBlocker($row))
                ->map(fn (object $row): ?string => $this->urlIdentity($row))
                ->filter()
                ->unique()
                ->count(),
            'high_priority_cluster_count' => $clusters
                ->filter(fn (array $cluster): bool => ($cluster['status'] ?? null) === 'open')
                ->filter(fn (array $cluster): bool => in_array(($cluster['severity'] ?? null), ['high', 'critical'], true))
                ->filter(fn (array $cluster): bool => (bool) data_get($cluster, 'priority.ranking_eligible', false))
                ->count(),
            'overdue_task_count' => $activeIssues
                ->filter(fn (object $row): bool => $this->isOverdue($row))
                ->count(),
        ];
    }

    private function isActiveIssue(object $row): bool
    {
        return ! in_array($this->normalizeWorkflowStatus((string) ($row->status ?? '')), ['resolved', 'closed', 'ignored'], true)
            && ! in_array((string) ($row->lifecycle_state ?? ''), ['resolved', 'closed', 'ignored'], true);
    }

    private function isIndexBlocker(object $row): bool
    {
        $axes = $this->axes($row);
        $haystack = implode(' ', [
            (string) ($row->issue_type ?? ''),
            $axes['root_cause'],
            $axes['field'],
        ]);

        foreach (['index', 'noindex', 'robots', 'canonical', 'sitemap'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isOverdue(object $row): bool
    {
        $dueAt = $row->sla_due_at ?? null;
        if (! is_string($dueAt) || trim($dueAt) === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($dueAt)->isPast();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{cluster_uid:string,total_count:int,page:int,per_page:int,last_page:int,rows:list<array<string,mixed>>}
     */
    public function urls(string $clusterUid, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $members = $this->issueRows($filters)
            ->filter(fn (object $row): bool => $this->clusterUid($this->axes($row)) === $clusterUid)
            ->sortByDesc(fn (object $row): string => (string) ($row->detected_at ?? $row->updated_at ?? ''))
            ->values();
        $total = $members->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'cluster_uid' => $clusterUid,
            'total_count' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'rows' => $members->forPage($page, $perPage)
                ->map(fn (object $row): array => $this->urlRow($row))
                ->values()
                ->all(),
        ];
    }

    /**
     * Complete filtered export assembled from one authoritative issue snapshot.
     *
     * @param  array<string,string>  $filters
     * @return array{clusters:list<array<string,mixed>>,urls:array<string,list<array<string,mixed>>>}
     */
    public function export(array $filters = []): array
    {
        $issues = $this->issueRows($filters);
        $clusters = $this->clusters($filters, $issues)->values();
        $urls = [];

        foreach ($clusters as $cluster) {
            $clusterUid = (string) $cluster['cluster_uid'];
            $urls[$clusterUid] = $issues
                ->filter(fn (object $row): bool => $this->clusterUid($this->axes($row)) === $clusterUid)
                ->sortByDesc(fn (object $row): string => (string) ($row->detected_at ?? $row->updated_at ?? ''))
                ->map(fn (object $row): array => $this->urlRow($row))
                ->values()
                ->all();
        }

        return ['clusters' => $clusters->all(), 'urls' => $urls];
    }

    /**
     * @param  array<string,string>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function clusters(array $filters, ?Collection $issues = null): Collection
    {
        $gscContext = $this->gscContext();

        return ($issues ?? $this->issueRows($filters))
            ->groupBy(fn (object $row): string => $this->clusterUid($this->axes($row)))
            ->map(function (Collection $members, string $clusterUid) use ($gscContext): array {
                $cluster = $this->clusterRow($clusterUid, $members);
                $cluster['priority'] = $this->priorityScorer->score(
                    $cluster,
                    $members,
                    $gscContext['metrics'],
                    $gscContext['quality_passed'],
                );

                return $cluster;
            })
            ->sort(function (array $left, array $right) use ($filters): int {
                $sort = (string) ($filters['sort'] ?? 'priority');
                if ($sort === 'impact') {
                    $impact = ((float) data_get($right, 'priority.impact.total', 0)) <=> ((float) data_get($left, 'priority.impact.total', 0));
                    if ($impact !== 0) {
                        return $impact;
                    }
                } elseif ($sort === 'affected_urls') {
                    $affected = ((int) $right['affected_url_count']) <=> ((int) $left['affected_url_count']);
                    if ($affected !== 0) {
                        return $affected;
                    }
                } elseif ($sort === 'newest') {
                    $newest = strcmp((string) ($right['last_detected_at'] ?? ''), (string) ($left['last_detected_at'] ?? ''));
                    if ($newest !== 0) {
                        return $newest;
                    }
                }
                $priority = ((float) data_get($right, 'priority.score', 0)) <=> ((float) data_get($left, 'priority.score', 0));

                return $priority !== 0
                    ? $priority
                    : strcmp((string) $left['cluster_uid'], (string) $right['cluster_uid']);
            });
    }

    /** @return array{quality_passed:bool,metrics:array<string,array{clicks:int,impressions:int}>} */
    private function gscContext(): array
    {
        $rows = $this->table('seo_gsc_daily')
            ->select([
                'report_date',
                'canonical_url_hash',
                'query_hash',
                'source_engine',
                'clicks',
                'impressions',
                'metadata_json',
            ])
            ->where('source_engine', 'google')
            ->get()
            ->map(fn (object $row): array => [
                'report_date' => (string) $row->report_date,
                'canonical_url_hash' => (string) ($row->canonical_url_hash ?? ''),
                'query_hash' => (string) ($row->query_hash ?? ''),
                'source_engine' => (string) $row->source_engine,
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'metadata_json' => $this->decodeJson($row->metadata_json ?? null),
            ]);
        $gate = $this->gscDataQualityGate->evaluate($rows->all());
        if (! (bool) ($gate['opportunity_queue_eligible'] ?? false)) {
            return ['quality_passed' => false, 'metrics' => []];
        }

        $metrics = [];
        foreach ($rows as $row) {
            $hash = (string) ($row['canonical_url_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $metrics[$hash]['clicks'] = ($metrics[$hash]['clicks'] ?? 0) + (int) $row['clicks'];
            $metrics[$hash]['impressions'] = ($metrics[$hash]['impressions'] ?? 0) + (int) $row['impressions'];
        }

        return ['quality_passed' => true, 'metrics' => $metrics];
    }

    /** @param array<string,string> $filters */
    private function issueRows(array $filters): Collection
    {
        $query = $this->table('seo_issue_queue')->select([
            'issue_uid',
            'issue_type',
            'severity',
            'source_system',
            'source_engine',
            'canonical_url_hash',
            'canonical_url',
            'locale',
            'page_entity_type',
            'status',
            'lifecycle_state',
            'owner_admin_user_id',
            'sla_due_at',
            'operator_note',
            'ignore_reason',
            'ignore_until',
            'verified_at',
            'verified_by_admin_user_id',
            'verification_note',
            'lock_version',
            'detected_at',
            'created_at',
            'updated_at',
            'summary',
            'recommendation',
            'metadata_json',
        ]);

        foreach (['issue_type', 'severity', 'source_system', 'source_engine', 'locale', 'page_entity_type', 'status', 'lifecycle_state'] as $column) {
            $value = trim((string) ($filters[$column] ?? ''));
            if ($value !== '' && $value !== 'all') {
                $query->where($column, $value);
            }
        }

        return $query->get();
    }

    /** @return array{root_cause:string,content_type:string,template:string,field:string,source_system:string,source_engine:string} */
    private function axes(object $row): array
    {
        $metadata = $this->decodeJson($row->metadata_json ?? null);
        $issueType = $this->normalizeAxis((string) ($row->issue_type ?? 'unknown'));
        $contentType = $this->normalizeAxis((string) ($row->page_entity_type ?? 'unknown'));

        return [
            'root_cause' => $this->normalizeAxis((string) ($metadata['root_cause'] ?? $issueType)),
            'content_type' => $contentType,
            'template' => $this->normalizeAxis((string) ($metadata['template'] ?? $metadata['template_id'] ?? $contentType)),
            'field' => $this->normalizeAxis((string) ($metadata['field'] ?? $metadata['field_name'] ?? $this->inferField($issueType))),
            'source_system' => $this->normalizeAxis((string) ($row->source_system ?? 'unknown')),
            'source_engine' => $this->normalizeAxis((string) ($row->source_engine ?? 'none')),
        ];
    }

    /** @param array<string,string> $axes */
    private function clusterUid(array $axes): string
    {
        ksort($axes);

        return 'seo-cluster:'.hash('sha256', json_encode($axes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param Collection<int,object> $members */
    private function clusterRow(string $clusterUid, Collection $members): array
    {
        $first = $members->first();
        $axes = $this->axes($first);
        $severity = $members
            ->map(fn (object $row): string => $this->normalizeSeverity((string) ($row->severity ?? 'info')))
            ->sortByDesc(fn (string $value): int => self::SEVERITY_RANK[$value] ?? 0)
            ->first() ?? 'info';
        $statuses = $members->map(fn (object $row): string => $this->normalizeWorkflowStatus((string) ($row->status ?? 'open')));
        $openCount = $statuses->filter(fn (string $status): bool => ! in_array($status, ['resolved', 'verified', 'closed', 'ignored'], true))->count();
        $ignoredCount = $statuses->filter(fn (string $status): bool => $status === 'ignored')->count();
        $urlIdentities = $members
            ->map(fn (object $row): ?string => $this->urlIdentity($row))
            ->filter()
            ->unique()
            ->values();
        $evidence = $members
            ->map(fn (object $row): array => [
                'fingerprint' => $this->evidenceFingerprint($row),
                'summary' => isset($row->summary) ? (string) $row->summary : null,
                'source' => $this->sourceSignal($row),
            ])
            ->unique('fingerprint')
            ->values();

        return [
            'cluster_uid' => $clusterUid,
            ...$axes,
            'issue_type' => (string) ($first->issue_type ?? 'unknown'),
            'severity' => $severity,
            'affected_url_count' => $urlIdentities->count(),
            'issue_count' => $members->count(),
            'evidence_count' => $evidence->count(),
            'evidence' => $evidence->take(3)->all(),
            'summary' => $this->representativeText($members, 'summary'),
            'recommendation' => $this->representativeText($members, 'recommendation'),
            'source' => $this->sourceSignal($first),
            'status' => $openCount > 0 ? 'open' : ($ignoredCount === $members->count() ? 'ignored' : 'resolved'),
            'lifecycle_state' => $openCount > 0 ? 'active' : ($ignoredCount === $members->count() ? 'ignored' : 'resolved'),
            'first_detected_at' => $this->timestampMin($members, ['created_at', 'detected_at']),
            'last_detected_at' => $this->timestampMax($members, ['detected_at', 'updated_at']),
            'evidence_changed_at' => $this->timestampMax($members, ['updated_at', 'detected_at']),
        ];
    }

    private function urlRow(object $row): array
    {
        return [
            'issue_uid' => (string) $row->issue_uid,
            'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
            'canonical_url_hash' => isset($row->canonical_url_hash) ? (string) $row->canonical_url_hash : null,
            'locale' => isset($row->locale) ? (string) $row->locale : null,
            'page_entity_type' => isset($row->page_entity_type) ? (string) $row->page_entity_type : null,
            'severity' => $this->normalizeSeverity((string) ($row->severity ?? 'info')),
            'status' => $this->normalizeWorkflowStatus((string) ($row->status ?? 'open')),
            'lifecycle_state' => (string) ($row->lifecycle_state ?? 'open'),
            'owner_admin_user_id' => isset($row->owner_admin_user_id) ? (int) $row->owner_admin_user_id : null,
            'sla_due_at' => $this->normalizeTimestamp($row->sla_due_at ?? null),
            'operator_note' => isset($row->operator_note) ? (string) $row->operator_note : null,
            'ignore_reason' => isset($row->ignore_reason) ? (string) $row->ignore_reason : null,
            'ignore_until' => $this->normalizeTimestamp($row->ignore_until ?? null),
            'verified_at' => $this->normalizeTimestamp($row->verified_at ?? null),
            'verified_by_admin_user_id' => isset($row->verified_by_admin_user_id) ? (int) $row->verified_by_admin_user_id : null,
            'verification_note' => isset($row->verification_note) ? (string) $row->verification_note : null,
            'lock_version' => (int) ($row->lock_version ?? 0),
            'summary' => isset($row->summary) ? (string) $row->summary : null,
            'recommendation' => isset($row->recommendation) ? (string) $row->recommendation : null,
            'source' => $this->sourceSignal($row),
            'evidence_fingerprint' => $this->evidenceFingerprint($row),
            'detected_at' => $this->normalizeTimestamp($row->detected_at ?? null),
            'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
        ];
    }

    private function evidenceFingerprint(object $row): string
    {
        $metadata = $this->decodeJson($row->metadata_json ?? null);
        unset($metadata['ops_workflow']);

        return hash('sha256', json_encode([
            'issue_uid' => (string) ($row->issue_uid ?? ''),
            'summary' => (string) ($row->summary ?? ''),
            'recommendation' => (string) ($row->recommendation ?? ''),
            'metadata' => $this->canonicalize($metadata),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function urlIdentity(object $row): ?string
    {
        foreach ([$row->canonical_url_hash ?? null, $row->canonical_url ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function sourceSignal(object $row): string
    {
        $source = (string) ($row->source_system ?? 'unknown');
        $engine = trim((string) ($row->source_engine ?? ''));

        return $engine === '' ? $source : $source.':'.$engine;
    }

    /** @param Collection<int,object> $members */
    private function representativeText(Collection $members, string $column): ?string
    {
        $value = $members
            ->map(fn (object $row): string => trim((string) ($row->{$column} ?? '')))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return is_string($value) ? $value : null;
    }

    /** @param Collection<int,object> $members @param list<string> $columns */
    private function timestampMin(Collection $members, array $columns): ?string
    {
        return $this->timestampExtreme($members, $columns, false);
    }

    /** @param Collection<int,object> $members @param list<string> $columns */
    private function timestampMax(Collection $members, array $columns): ?string
    {
        return $this->timestampExtreme($members, $columns, true);
    }

    /** @param Collection<int,object> $members @param list<string> $columns */
    private function timestampExtreme(Collection $members, array $columns, bool $maximum): ?string
    {
        $values = $members->flatMap(function (object $row) use ($columns): array {
            return array_values(array_filter(array_map(
                static fn (string $column): mixed => $row->{$column} ?? null,
                $columns,
            )));
        });
        $value = $maximum ? $values->max() : $values->min();

        return $this->normalizeTimestamp($value);
    }

    private function inferField(string $issueType): string
    {
        foreach (['canonical', 'robots', 'title', 'description', 'hreflang', 'lastmod', 'sitemap', 'indexability', 'schema', 'content'] as $field) {
            if (str_contains($issueType, $field)) {
                return $field;
            }
        }

        return 'general';
    }

    private function normalizeSeverity(string $severity): string
    {
        return $severity === 'warning' ? 'medium' : $this->normalizeAxis($severity);
    }

    private function normalizeWorkflowStatus(string $status): string
    {
        return match ($status) {
            'new' => 'open',
            'assigned' => 'in_progress',
            'fixed' => 'resolved',
            'verified' => 'closed',
            default => $status,
        };
    }

    private function normalizeAxis(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?? '';

        return trim($value, '_') !== '' ? trim($value, '_') : 'unknown';
    }
}
