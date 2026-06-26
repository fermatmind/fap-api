<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PersonalityAgentApprovalQueueReadModel
{
    private const ALLOWED_FRAMEWORKS = ['mbti64', 'big_five', 'enneagram'];

    private const ALLOWED_APPROVAL_STATES = ['pending', 'approved', 'rejected', 'all'];

    /**
     * @return array<string,mixed>
     */
    public function read(?string $framework = null, string $approvalState = 'pending', int $limit = 50): array
    {
        $framework = $framework !== null ? trim($framework) : null;
        $approvalState = trim($approvalState) !== '' ? trim($approvalState) : 'pending';

        if ($framework !== null && $framework !== '' && ! in_array($framework, self::ALLOWED_FRAMEWORKS, true)) {
            throw new InvalidArgumentException('Unsupported framework filter: '.$framework);
        }

        if (! in_array($approvalState, self::ALLOWED_APPROVAL_STATES, true)) {
            throw new InvalidArgumentException('Unsupported approval state filter: '.$approvalState);
        }

        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('--limit must be between 1 and 200.');
        }

        $baseQuery = $this->baseQuery($framework, $approvalState);

        $items = (clone $baseQuery)
            ->orderByDesc('items.created_at')
            ->orderByDesc('items.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->formatItem($row))
            ->values()
            ->all();

        return [
            'artifact' => 'PERSONALITY-AGENT-OPS-REVIEW-SURFACE-01',
            'status' => 'pass',
            'ok' => true,
            'read_only' => true,
            'filters' => [
                'framework' => $framework !== '' ? $framework : null,
                'approval_state' => $approvalState,
                'limit' => $limit,
            ],
            'summary' => [
                'matched_item_count' => (int) (clone $baseQuery)->count(),
                'returned_item_count' => count($items),
                'by_framework' => $this->countsBy($framework, $approvalState, 'items.framework'),
                'by_approval_state' => $this->countsBy($framework, $approvalState, 'items.approval_state'),
                'by_qa_decision' => $this->countsBy($framework, $approvalState, 'items.qa_decision'),
                'by_blocked_reason' => $this->countsBy($framework, $approvalState, 'items.blocked_reason'),
            ],
            'items' => $items,
            'safety_boundary' => $this->safetyBoundary(),
            'warnings' => [],
            'errors' => [],
        ];
    }

    private function baseQuery(?string $framework, string $approvalState): Builder
    {
        $query = DB::table('personality_agent_approval_items as items')
            ->join('personality_agent_approval_batches as batches', 'batches.id', '=', 'items.batch_id')
            ->select([
                'items.id',
                'items.batch_id',
                'items.framework',
                'items.target_url',
                'items.path',
                'items.locale',
                'items.page_type',
                'items.recommendation_id',
                'items.recommendation_sha256',
                'items.qa_decision',
                'items.approval_state',
                'items.approved_at',
                'items.rejected_at',
                'items.blocked_reason',
                'items.recommendation_json',
                'items.qa_json',
                'items.created_at',
                'items.updated_at',
                'batches.status as batch_status',
                'batches.source_artifact',
                'batches.source_artifact_path',
                'batches.source_package_sha256',
                'batches.qa_artifact',
                'batches.qa_artifact_path',
                'batches.qa_sha256',
            ]);

        if ($framework !== null && $framework !== '') {
            $query->where('items.framework', $framework);
        }

        if ($approvalState !== 'all') {
            $query->where('items.approval_state', $approvalState);
        }

        return $query;
    }

    /**
     * @return array<string,int>
     */
    private function countsBy(?string $framework, string $approvalState, string $column): array
    {
        $query = DB::table('personality_agent_approval_items as items')
            ->join('personality_agent_approval_batches as batches', 'batches.id', '=', 'items.batch_id');

        if ($framework !== null && $framework !== '') {
            $query->where('items.framework', $framework);
        }

        if ($approvalState !== 'all') {
            $query->where('items.approval_state', $approvalState);
        }

        return $query
            ->select([
                DB::raw($column.' as bucket'),
                DB::raw('count(*) as aggregate'),
            ])
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->mapWithKeys(static function (object $row): array {
                $bucket = (string) ($row->bucket ?? 'none');
                if ($bucket === '') {
                    $bucket = 'none';
                }

                return [$bucket => (int) $row->aggregate];
            })
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function formatItem(object $row): array
    {
        $recommendation = $this->jsonObject($row->recommendation_json ?? null);
        $qa = $this->jsonObject($row->qa_json ?? null);

        return [
            'id' => (int) $row->id,
            'batch_id' => (int) $row->batch_id,
            'batch_status' => (string) $row->batch_status,
            'framework' => (string) $row->framework,
            'target_url' => (string) $row->target_url,
            'path' => (string) $row->path,
            'locale' => (string) $row->locale,
            'page_type' => (string) $row->page_type,
            'recommendation_id' => (string) $row->recommendation_id,
            'recommendation_sha256' => (string) $row->recommendation_sha256,
            'qa_decision' => (string) $row->qa_decision,
            'approval_state' => (string) $row->approval_state,
            'approved_at' => $row->approved_at !== null ? (string) $row->approved_at : null,
            'rejected_at' => $row->rejected_at !== null ? (string) $row->rejected_at : null,
            'blocked_reason' => $row->blocked_reason !== null ? (string) $row->blocked_reason : null,
            'risk_reasons' => $this->riskReasons($qa, $row->blocked_reason !== null ? (string) $row->blocked_reason : null),
            'recommendation_summary' => $this->recommendationSummary($recommendation),
            'qa_summary' => $this->qaSummary($qa),
            'source_artifact' => (string) $row->source_artifact,
            'source_artifact_path' => (string) $row->source_artifact_path,
            'source_package_sha256' => (string) $row->source_package_sha256,
            'qa_artifact' => (string) $row->qa_artifact,
            'qa_artifact_path' => (string) $row->qa_artifact_path,
            'qa_sha256' => (string) $row->qa_sha256,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendationSummary(array $recommendation): array
    {
        $payload = is_array($recommendation['recommendations'] ?? null)
            ? $recommendation['recommendations']
            : [];

        return [
            'has_title' => array_key_exists('title', $payload),
            'has_description' => array_key_exists('description', $payload),
            'has_h1' => array_key_exists('h1', $payload),
            'has_quick_answer' => array_key_exists('quick_answer', $payload),
            'faq_count' => is_array($payload['faq'] ?? null) ? count($payload['faq']) : 0,
            'internal_link_count' => is_array($payload['internal_links'] ?? null) ? count($payload['internal_links']) : 0,
            'differentiation_note_count' => is_array($payload['differentiation_notes'] ?? null)
                ? count($payload['differentiation_notes'])
                : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaSummary(array $qa): array
    {
        return [
            'decision' => (string) ($qa['decision'] ?? $qa['status'] ?? $qa['qa_status'] ?? ''),
            'blocker_count' => is_array($qa['blockers'] ?? null) ? count($qa['blockers']) : 0,
            'failed_gate_count' => is_array($qa['failed_gates'] ?? null) ? count($qa['failed_gates']) : 0,
            'eligible_for_approval_queue' => (bool) ($qa['eligible_for_approval_queue'] ?? false),
            'eligible_for_cms_draft_path' => (bool) ($qa['eligible_for_cms_draft_path'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function riskReasons(array $qa, ?string $blockedReason): array
    {
        $reasons = [];
        if ($blockedReason !== null && $blockedReason !== '') {
            $reasons[] = $blockedReason;
        }

        foreach (['blockers', 'failed_gates', 'risk_reasons'] as $field) {
            if (! is_array($qa[$field] ?? null)) {
                continue;
            }

            foreach ($qa[$field] as $reason) {
                $value = trim((string) $reason);
                if ($value !== '') {
                    $reasons[] = $value;
                }
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,bool>
     */
    private function safetyBoundary(): array
    {
        return [
            'read_only' => true,
            'approval_state_mutation_attempted' => false,
            'cms_write_attempted' => false,
            'cms_mutation_attempted' => false,
            'cms_live_promotion_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
        ];
    }
}
