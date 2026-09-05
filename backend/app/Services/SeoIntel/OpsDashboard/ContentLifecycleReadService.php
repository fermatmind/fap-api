<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\Lifecycle\ContentLifecycleCandidateEvaluator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContentLifecycleReadService extends AbstractSeoDashboardReadService
{
    public const CONTRACT_VERSION = 'seo.content_lifecycle_read_model.v1';

    /** @return array<string, mixed> */
    public function read(int $page = 1, int $perPage = 25, ?string $locale = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $locale = in_array($locale, ['zh-CN', 'en'], true) ? $locale : null;

        try {
            if (! $this->schemaReady()) {
                return $this->unavailable($page, $perPage, $locale);
            }

            $latestIds = $this->authorityConnection()->table('content_material_decisions')
                ->where('org_id', 0)
                ->whereIn('family', ['article', 'career', 'personality'])
                ->selectRaw('MAX(id)')
                ->groupBy('family', 'locale', 'authority_subject_key');
            $query = $this->authorityConnection()->table('content_material_decisions')
                ->whereIn('id', $latestIds);
            if ($locale !== null) {
                $query->where('locale', $locale);
            }

            $total = (clone $query)->count();
            $decisions = $query
                ->orderByDesc('id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            $decisionIds = $decisions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $urls = $decisionIds === []
                ? collect()
                : $this->table('seo_urls')
                    ->whereIn('material_decision_id', $decisionIds)
                    ->get([
                        'material_decision_id', 'canonical_url_hash', 'material_fingerprint',
                        'material_lastmod_at', 'material_lastmod_source', 'material_authority_state',
                    ])
                    ->keyBy('material_decision_id');
            $hashes = $urls->pluck('canonical_url_hash')->filter()->values()->all();
            $candidates = $this->candidateMap($hashes);

            $rows = $decisions->map(function (object $decision) use ($urls, $candidates): array {
                $url = $urls->get((int) $decision->id);
                $canonicalHash = is_object($url) ? (string) ($url->canonical_url_hash ?? '') : '';
                $candidate = $canonicalHash === '' ? null : ($candidates[$canonicalHash] ?? null);
                $evidenceRef = trim((string) ($decision->evidence_ref ?? ''));

                return [
                    'authority_type' => (string) $decision->family,
                    'page_family' => $this->pageFamily((string) $decision->family),
                    'locale' => (string) $decision->locale,
                    'public_identity_hash' => hash('sha256', (string) $decision->public_identity),
                    'revision' => [
                        'kind' => (string) $decision->authority_revision_kind,
                        'value' => (string) $decision->authority_revision,
                    ],
                    'review' => [
                        'state' => $evidenceRef === '' ? 'unavailable' : 'evidence_bound',
                        'reviewed_at' => null,
                        'evidence_ref_hash' => $evidenceRef === '' ? null : hash('sha256', $evidenceRef),
                    ],
                    'fingerprint' => $this->validHash($decision->material_fingerprint ?? null),
                    'material_lastmod' => $this->normalizeTimestamp($url->material_lastmod_at ?? null),
                    'material_lastmod_source' => isset($url->material_lastmod_source) ? (string) $url->material_lastmod_source : null,
                    'material_authority_state' => isset($url->material_authority_state) ? (string) $url->material_authority_state : 'hold',
                    'candidate' => $candidate ?? [
                        'type' => null,
                        'status' => 'not_observed',
                        'recommended_action' => null,
                        'evidence_revision' => null,
                    ],
                    'recorded_at' => $this->normalizeTimestamp($decision->created_at ?? null),
                ];
            })->all();

            return [
                'schema_version' => self::CONTRACT_VERSION,
                'state' => 'production_proven',
                'source_state' => 'available',
                'filters' => ['locale' => $locale],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                'rows' => $rows,
                'boundaries' => $this->boundaries(),
            ];
        } catch (Throwable) {
            return $this->unavailable($page, $perPage, $locale);
        }
    }

    /** @param list<string> $canonicalHashes @return array<string, array<string, mixed>> */
    private function candidateMap(array $canonicalHashes): array
    {
        if ($canonicalHashes === []) {
            return [];
        }

        $rows = $this->table('seo_issue_queue')
            ->whereIn('canonical_url_hash', $canonicalHashes)
            ->whereIn('issue_type', ['review_overdue', 'content_decay_candidate'])
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->get(['canonical_url_hash', 'issue_type', 'status', 'lifecycle_state', 'metadata_json']);
        $result = [];
        foreach ($rows as $row) {
            $hash = (string) $row->canonical_url_hash;
            if (isset($result[$hash])) {
                continue;
            }
            $metadata = $this->decodeJson($row->metadata_json ?? null);
            $detector = is_array($metadata['detector_result'] ?? null) ? $metadata['detector_result'] : [];
            $requestedAction = (string) ($metadata['recommended_action'] ?? 'refresh');
            $action = in_array($requestedAction, ContentLifecycleCandidateEvaluator::ACTIONS, true)
                ? $requestedAction
                : 'refresh';
            $held = in_array((string) $row->lifecycle_state, ['held', 'measurement_hold'], true)
                || ($detector['outcome'] ?? null) === 'measurement_hold';

            $result[$hash] = [
                'type' => (string) $row->issue_type,
                'status' => $held ? 'hold' : ((string) $row->status === 'resolved' ? 'closed' : 'candidate'),
                'recommended_action' => $action,
                'evidence_revision' => $this->safeRevision($detector['authority_revision'] ?? null),
            ];
        }

        return $result;
    }

    private function authorityConnection(): ConnectionInterface
    {
        return DB::connection((string) config('database.default'));
    }

    private function schemaReady(): bool
    {
        return \App\Support\SchemaBaseline::tableExists('content_material_decisions', (string) config('database.default'))
            && \App\Support\SchemaBaseline::tableExists('seo_urls', $this->connection()->getName())
            && \App\Support\SchemaBaseline::tableExists('seo_issue_queue', $this->connection()->getName());
    }

    private function pageFamily(string $family): string
    {
        return match ($family) {
            'article' => 'articles_topics',
            'career' => 'career',
            'personality' => 'personality',
            default => 'unclassified',
        };
    }

    private function validHash(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private function safeRevision(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 128, 'UTF-8');
    }

    /** @return array<string, bool> */
    private function boundaries(): array
    {
        return [
            'read_only' => true,
            'raw_public_identity_emitted' => false,
            'raw_evidence_ref_emitted' => false,
            'private_content_emitted' => false,
            'automatic_publish' => false,
            'automatic_noindex' => false,
            'automatic_delete' => false,
            'automatic_merge' => false,
            'backfill_control_exposed' => false,
            'search_submission_exposed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(int $page, int $perPage, ?string $locale): array
    {
        return [
            'schema_version' => self::CONTRACT_VERSION,
            'state' => 'production_unproven',
            'source_state' => 'unavailable',
            'filters' => ['locale' => $locale],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => null, 'last_page' => null],
            'rows' => [],
            'boundaries' => $this->boundaries(),
        ];
    }
}
