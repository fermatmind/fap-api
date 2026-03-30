<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\DataPage;
use App\Models\IntentRegistry;
use App\Models\SeoQualityAudit;
use Illuminate\Support\Facades\DB;

final class SeoQualityAuditService
{
    public function __construct(
        private readonly SeoOperationsService $seoOperationsService,
    ) {}

    public function latestCitationQa(DataPage $page): ?SeoQualityAudit
    {
        return SeoQualityAudit::query()
            ->withoutGlobalScopes()
            ->where('audit_type', SeoQualityAudit::TYPE_CITATION_QA)
            ->where('subject_type', $page->getMorphClass())
            ->where('subject_id', (int) $page->getKey())
            ->latest('audited_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{label:string,state:string,passed:bool,audited_at:?string,summary:?string}
     */
    public function citationQaState(DataPage $page): array
    {
        $latest = $this->latestCitationQa($page);
        if (! $latest instanceof SeoQualityAudit) {
            return [
                'label' => 'Missing',
                'state' => 'warning',
                'passed' => false,
                'audited_at' => null,
                'summary' => 'Run Citation QA before publishing this data page.',
            ];
        }

        $state = match ($latest->status) {
            SeoQualityAudit::STATUS_PASSED => 'success',
            SeoQualityAudit::STATUS_FAILED => 'danger',
            default => 'warning',
        };

        return [
            'label' => ucfirst((string) $latest->status),
            'state' => $state,
            'passed' => $latest->status === SeoQualityAudit::STATUS_PASSED,
            'audited_at' => optional($latest->audited_at)?->toDateTimeString(),
            'summary' => trim((string) data_get($latest->summary_json, 'summary', '')),
        ];
    }

    public function hasPassingCitationQa(DataPage $page): bool
    {
        return $this->latestCitationQa($page)?->status === SeoQualityAudit::STATUS_PASSED;
    }

    public function runCitationQa(DataPage $page, ?int $actorAdminId = null): SeoQualityAudit
    {
        $page->loadMissing(['seoMeta', 'governance']);

        $checks = [
            $this->question(
                'sample_size',
                'Is the sample size explicit?',
                filled($page->sample_size_label),
                filled($page->sample_size_label)
                    ? 'Sample size label is present.'
                    : 'Add sample_size_label so readers can quote the evidence base.'
            ),
            $this->question(
                'time_window',
                'Is the time window explicit?',
                filled($page->time_window_label),
                filled($page->time_window_label)
                    ? 'Time window label is present.'
                    : 'Add time_window_label so readers can date-bound the finding.'
            ),
            $this->question(
                'conclusion_direction',
                'Does the page state the conclusion direction?',
                filled($page->summary_statement_md),
                filled($page->summary_statement_md)
                    ? 'Summary statement is present.'
                    : 'Add summary_statement_md with the main directional conclusion.'
            ),
            $this->question(
                'applicable_boundary',
                'Does the page define the applicable boundary?',
                filled($page->methodology_md),
                filled($page->methodology_md)
                    ? 'Methodology block is present.'
                    : 'Add methodology_md to explain who and what the dataset covers.'
            ),
            $this->question(
                'group_vs_individual',
                'Does the page separate group findings from individual interpretation?',
                $this->hasGroupVsIndividualBoundary($page),
                $this->hasGroupVsIndividualBoundary($page)
                    ? 'Boundary language distinguishes aggregate results from individual interpretation.'
                    : 'Add aggregate-vs-individual boundary language to methodology_md or limitations_md.'
            ),
        ];

        $failedCount = collect($checks)->where('passed', false)->count();
        $status = $failedCount === 0 ? SeoQualityAudit::STATUS_PASSED : SeoQualityAudit::STATUS_FAILED;
        $summary = [
            'summary' => $failedCount === 0
                ? 'All five citation questions passed.'
                : 'Citation QA failed on '.$failedCount.' of 5 questions.',
            'passed_questions' => 5 - $failedCount,
            'failed_questions' => $failedCount,
            'page_type' => 'data',
            'page_title' => trim((string) $page->title),
        ];

        return SeoQualityAudit::query()
            ->withoutGlobalScopes()
            ->create([
                'org_id' => max(0, (int) $page->org_id),
                'audit_type' => SeoQualityAudit::TYPE_CITATION_QA,
                'subject_type' => $page->getMorphClass(),
                'subject_id' => (int) $page->getKey(),
                'scope_key' => 'data:'.(int) $page->getKey(),
                'status' => $status,
                'summary_json' => $summary,
                'findings_json' => $checks,
                'actor_admin_user_id' => $actorAdminId,
                'audited_at' => now(),
            ]);
    }

    public function latestMonthlyPatrol(array $currentOrgIds): ?SeoQualityAudit
    {
        return SeoQualityAudit::query()
            ->withoutGlobalScopes()
            ->where('audit_type', SeoQualityAudit::TYPE_MONTHLY_PATROL)
            ->where('scope_key', $this->monthlyPatrolScopeKey($currentOrgIds))
            ->latest('audited_at')
            ->latest('id')
            ->first();
    }

    public function runMonthlyPatrol(array $currentOrgIds, ?int $actorAdminId = null): SeoQualityAudit
    {
        $issueQueue = $this->seoOperationsService->buildIssueQueue($currentOrgIds, 'all', 'all');
        $issues = collect($issueQueue['items'] ?? []);
        $cannibalization = collect($this->cannibalizationFindings($currentOrgIds));
        $citationBacklog = collect($this->citationBacklogFindings());

        $summary = [
            'summary' => 'Monthly patrol completed for '.$this->monthlyPatrolScopeLabel($currentOrgIds).'.',
            'scope' => $this->monthlyPatrolScopeLabel($currentOrgIds),
            'month' => now()->format('Y-m'),
            'query_elapsed_ms' => (int) ($issueQueue['elapsed_ms'] ?? 0),
            'issue_count' => $issues->count(),
            'canonical_issue_count' => $issues->filter(fn (array $item): bool => in_array('canonical', $item['issue_codes'] ?? [], true))->count(),
            'schema_issue_count' => $issues->filter(fn (array $item): bool => in_array('schema', $item['issue_codes'] ?? [], true))->count(),
            'sitemap_issue_count' => $issues->filter(fn (array $item): bool => in_array('sitemap', $item['issue_codes'] ?? [], true))->count(),
            'cannibalization_count' => $cannibalization->count(),
            'citation_backlog_count' => $citationBacklog->count(),
        ];

        $status = $summary['issue_count'] === 0
            && $summary['cannibalization_count'] === 0
            && $summary['citation_backlog_count'] === 0
            ? SeoQualityAudit::STATUS_PASSED
            : SeoQualityAudit::STATUS_WARNING;

        $findings = [
            'seo_issue_queue' => $issues->values()->all(),
            'cannibalization' => $cannibalization->values()->all(),
            'citation_backlog' => $citationBacklog->values()->all(),
        ];

        return SeoQualityAudit::query()
            ->withoutGlobalScopes()
            ->create([
                'org_id' => $this->monthlyPatrolOrgId($currentOrgIds),
                'audit_type' => SeoQualityAudit::TYPE_MONTHLY_PATROL,
                'scope_key' => $this->monthlyPatrolScopeKey($currentOrgIds),
                'status' => $status,
                'summary_json' => $summary,
                'findings_json' => $findings,
                'actor_admin_user_id' => $actorAdminId,
                'audited_at' => now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cannibalizationFindings(array $currentOrgIds): array
    {
        $query = IntentRegistry::query()
            ->withoutGlobalScopes()
            ->select([
                'org_id',
                'page_type',
                'primary_query',
                DB::raw('COUNT(*) as duplicate_count'),
            ])
            ->whereNotNull('primary_query')
            ->where('primary_query', '!=', '');

        if ($currentOrgIds !== []) {
            $query->whereIn('org_id', $currentOrgIds);
        } else {
            $query->where('org_id', 0);
        }

        /** @var EloquentCollection<int, IntentRegistry> $duplicates */
        $duplicates = $query
            ->groupBy('org_id', 'page_type', 'primary_query')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicate_count')
            ->get();

        return $duplicates
            ->map(function (IntentRegistry $row): array {
                return [
                    'kind' => 'cannibalization',
                    'org_id' => (int) $row->org_id,
                    'page_type' => (string) $row->page_type,
                    'primary_query' => (string) $row->primary_query,
                    'duplicate_count' => (int) data_get($row, 'duplicate_count', 0),
                    'message' => 'Primary query maps to multiple URLs and needs consolidation.',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function citationBacklogFindings(): array
    {
        return DataPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->latest('updated_at')
            ->get()
            ->map(function (DataPage $page): ?array {
                $state = $this->citationQaState($page);
                if ($state['passed']) {
                    return null;
                }

                return [
                    'kind' => 'citation_backlog',
                    'page_id' => (int) $page->getKey(),
                    'title' => trim((string) $page->title),
                    'status' => $state['label'],
                    'summary' => $state['summary'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{key:string,question:string,passed:bool,status:string,detail:string}
     */
    private function question(string $key, string $question, bool $passed, string $detail): array
    {
        return [
            'key' => $key,
            'question' => $question,
            'passed' => $passed,
            'status' => $passed ? SeoQualityAudit::STATUS_PASSED : SeoQualityAudit::STATUS_FAILED,
            'detail' => trim($detail),
        ];
    }

    private function hasGroupVsIndividualBoundary(DataPage $page): bool
    {
        $text = trim(implode(' ', array_filter([
            (string) ($page->methodology_md ?? ''),
            (string) ($page->limitations_md ?? ''),
            (string) ($page->summary_statement_md ?? ''),
        ])));

        if ($text === '') {
            return false;
        }

        return preg_match('/\b(group|aggregate|population|sample|not individual|not diagnose|not predictive)\b/ui', $text) === 1
            || preg_match('/(群体|聚合|样本|不代表个体|不用于个体判断|非诊断)/u', $text) === 1;
    }

    private function monthlyPatrolScopeKey(array $currentOrgIds): string
    {
        return now()->format('Y-m').'|'.$this->monthlyPatrolScopeLabel($currentOrgIds);
    }

    private function monthlyPatrolScopeLabel(array $currentOrgIds): string
    {
        if ($currentOrgIds === []) {
            return 'org:global';
        }

        return 'org:'.implode('-', array_map(static fn (int $id): string => (string) $id, $currentOrgIds));
    }

    private function monthlyPatrolOrgId(array $currentOrgIds): int
    {
        return $currentOrgIds[0] ?? 0;
    }
}
