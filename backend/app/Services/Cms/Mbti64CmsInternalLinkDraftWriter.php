<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Support\Facades\DB;

final class Mbti64CmsInternalLinkDraftWriter
{
    private const SNAPSHOT_KEY = 'mbti64_internal_link_graph_v1';

    private const GRAPH_VERSION = 'mbti64.internal_link_graph.v1';

    private const FORBIDDEN_ROUTE_PATTERNS = [
        '#/results?(?:/|$)#i',
        '#/orders?(?:/|$)#i',
        '#/share(?:/|$)#i',
        '#/pay(?:/|$)#i',
        '#/payment(?:/|$)#i',
        '#/history(?:/|$)#i',
        '#/private(?:/|$)#i',
        '#/account(?:/|$)#i',
        '#[?&](?:token|session|user|result_id|report_id|order_no)=#i',
    ];

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $graph, string $sourceSha256, array $options = []): array
    {
        return $this->buildSummary($graph, $sourceSha256, false, $options);
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function write(array $graph, string $sourceSha256, array $options = []): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($graph, $sourceSha256, true, $options));
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildSummary(array $graph, string $sourceSha256, bool $write, array $options): array
    {
        $errors = $this->validateGraph($graph);
        $warnings = [];

        $nodes = $this->nodes($graph);
        $nodesByPath = [];
        foreach ($nodes as $node) {
            $path = $this->normalizePath((string) ($node['path'] ?? $node['url'] ?? ''));
            if ($path !== '') {
                $nodesByPath[$path] = $node;
            }
        }

        $recommendedBySource = $this->groupEdges($graph, 'recommendedEdges');
        $blockedBySource = $this->groupEdges($graph, 'blockedEdges');
        $privateRecommendedEdgeCount = 0;
        foreach ($this->edges($graph, 'recommendedEdges') as $edge) {
            $target = $this->normalizePath((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));
            if ($this->containsForbiddenRoutePattern($target)) {
                $privateRecommendedEdgeCount++;
                $errors[] = [
                    'field' => 'recommendedEdges',
                    'code' => 'forbidden_recommended_edge_target',
                    'message' => 'Recommended edge target contains a forbidden private route pattern: '.$target,
                ];
            }
        }

        $preparedRows = [];
        foreach ($nodesByPath as $sourcePath => $node) {
            $identity = $this->identityForNode($node, $sourcePath);
            if ($identity === null) {
                $errors[] = [
                    'field' => 'nodes.path',
                    'code' => 'unsupported_mbti64_node_path',
                    'message' => 'Unsupported MBTI64 graph node path: '.$sourcePath,
                ];

                continue;
            }

            $activeEdges = $this->activeEdgesForSource($recommendedBySource[$sourcePath] ?? []);
            $blockedEdges = array_values($blockedBySource[$sourcePath] ?? []);
            $internalLinks = $this->internalLinks($activeEdges);

            if ($internalLinks === []) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'no_active_internal_links_for_source',
                    'message' => 'No safe recommended internal links were available for '.$sourcePath,
                ];
            }

            $target = $this->targetRecord($identity);
            $targetId = $target['id'] ?? null;
            $pageType = (string) $identity['page_type'];
            $targetField = $pageType === 'comparison' ? 'profile_id' : 'personality_profile_variant_id';

            if (! is_int($targetId)) {
                $errors[] = [
                    'field' => 'nodes.'.$sourcePath,
                    'code' => 'target_not_found',
                    'message' => 'CMS target record was not found for MBTI64 internal-link source '.$sourcePath,
                ];
            }

            $existingRevision = is_int($targetId)
                ? $this->existingRevision($pageType, $targetField, $targetId, $sourceSha256)
                : null;
            $nextRevisionNo = is_int($targetId)
                ? $this->nextRevisionNo($pageType, $targetField, $targetId)
                : null;

            $preparedRows[] = [
                'url' => (string) ($node['url'] ?? $sourcePath),
                'path' => $sourcePath,
                'locale' => (string) $identity['locale'],
                'page_type' => $pageType,
                'identity' => $identity,
                'target_table' => $pageType === 'comparison'
                    ? 'personality_profile_revisions'
                    : 'personality_profile_variant_revisions',
                'target_id' => $targetId,
                'snapshot_key' => self::SNAPSHOT_KEY,
                'source_sha256' => $sourceSha256,
                'active_internal_link_count' => count($internalLinks),
                'blocked_edge_count' => count($blockedEdges),
                'existing_revision_id' => $existingRevision?->id !== null ? (int) $existingRevision->id : null,
                'existing_revision_no' => $existingRevision?->revision_no !== null ? (int) $existingRevision->revision_no : null,
                'next_revision_no' => $nextRevisionNo,
                'write_mode' => $write ? 'write_draft_revision' : 'dry_run',
                'action' => 'pending',
                'snapshot_preview' => $this->snapshotPayload(
                    $graph,
                    $node,
                    $identity,
                    $sourceSha256,
                    $activeEdges,
                    $blockedEdges,
                    $internalLinks
                ),
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($graph, $sourceSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($preparedRows),
                'variant_row_count' => $this->countRows($preparedRows, 'variant'),
                'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
                'private_recommended_edge_count' => $privateRecommendedEdgeCount,
                'rows' => $preparedRows,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        }

        $created = 0;
        $skippedExisting = 0;
        if ($write) {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'skipped_existing';
                    $skippedExisting++;

                    continue;
                }

                $revision = $this->createRevision($preparedRow);
                $preparedRow['action'] = 'created';
                $preparedRow['created_revision_id'] = (int) $revision->id;
                $preparedRow['created_revision_no'] = (int) $revision->revision_no;
                $created++;
            }
            unset($preparedRow);
        } else {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'would_skip_existing';
                    $skippedExisting++;

                    continue;
                }

                $preparedRow['action'] = 'would_create';
            }
            unset($preparedRow);
        }

        return array_merge($this->baseSummary($graph, $sourceSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($preparedRows),
            'variant_row_count' => $this->countRows($preparedRows, 'variant'),
            'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
            'created_revision_count' => $created,
            'skipped_existing_count' => $skippedExisting,
            'would_create_revision_count' => $write ? 0 : count($preparedRows) - $skippedExisting,
            'active_internal_link_count' => array_sum(array_map(
                static fn (array $row): int => (int) ($row['active_internal_link_count'] ?? 0),
                $preparedRows
            )),
            'blocked_edge_count' => count($this->edges($graph, 'blockedEdges')),
            'private_recommended_edge_count' => $privateRecommendedEdgeCount,
            'writes_committed' => $write && $created > 0,
            'rows' => $preparedRows,
            'errors' => [],
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    private function baseSummary(array $graph, string $sourceSha256, bool $write): array
    {
        return [
            'artifact' => 'MBTI64-CMS-INTERNAL-LINK-DRAFT-01',
            'source_version' => (string) ($graph['version'] ?? ''),
            'source_status' => (string) ($graph['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'snapshot_key' => self::SNAPSHOT_KEY,
            'dry_run' => ! $write,
            'write' => $write,
            'draft_only' => true,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'writes_committed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return list<array<string,string>>
     */
    private function validateGraph(array $graph): array
    {
        $errors = [];
        $summary = is_array($graph['summary'] ?? null) ? $graph['summary'] : [];

        if ((string) ($graph['version'] ?? '') !== self::GRAPH_VERSION) {
            $errors[] = [
                'field' => 'version',
                'code' => 'unsupported_graph_version',
                'message' => 'Expected graph version '.self::GRAPH_VERSION.'.',
            ];
        }

        if ((string) ($graph['status'] ?? '') !== 'pass') {
            $errors[] = [
                'field' => 'status',
                'code' => 'graph_status_not_pass',
                'message' => 'Graph artifact status must be pass.',
            ];
        }

        foreach ([
            'total_pages' => 96,
            'variant_pages' => 64,
            'comparison_pages' => 32,
            'unsafe_recommended_edges' => 0,
            'self_links' => 0,
        ] as $field => $expected) {
            if ((int) ($summary[$field] ?? -1) !== $expected) {
                $errors[] = [
                    'field' => 'summary.'.$field,
                    'code' => 'unexpected_graph_summary_count',
                    'message' => 'Expected '.$field.'='.$expected.'.',
                ];
            }
        }

        if (count($this->nodes($graph)) !== 96) {
            $errors[] = [
                'field' => 'nodes',
                'code' => 'unexpected_node_count',
                'message' => 'Expected exactly 96 MBTI64 graph nodes.',
            ];
        }

        if ($this->edges($graph, 'recommendedEdges') === []) {
            $errors[] = [
                'field' => 'recommendedEdges',
                'code' => 'missing_recommended_edges',
                'message' => 'Graph artifact must include recommendedEdges.',
            ];
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return list<array<string,mixed>>
     */
    private function nodes(array $graph): array
    {
        return array_values(array_filter(
            is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [],
            static fn (mixed $node): bool => is_array($node)
        ));
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return list<array<string,mixed>>
     */
    private function edges(array $graph, string $key): array
    {
        return array_values(array_filter(
            is_array($graph[$key] ?? null) ? $graph[$key] : [],
            static fn (mixed $edge): bool => is_array($edge)
        ));
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return array<string,list<array<string,mixed>>>
     */
    private function groupEdges(array $graph, string $key): array
    {
        $grouped = [];
        foreach ($this->edges($graph, $key) as $edge) {
            $sourcePath = $this->normalizePath((string) ($edge['source_path'] ?? $edge['source_url'] ?? ''));
            if ($sourcePath === '') {
                continue;
            }

            $grouped[$sourcePath] ??= [];
            $grouped[$sourcePath][] = $edge;
        }

        return $grouped;
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     * @return list<array<string,mixed>>
     */
    private function activeEdgesForSource(array $edges): array
    {
        return array_values(array_filter($edges, function (array $edge): bool {
            $target = $this->normalizePath((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));

            return ($edge['safe_public_route'] ?? null) === true
                && trim((string) ($edge['publish_blocker_if_any'] ?? '')) === ''
                && $target !== ''
                && ! $this->containsForbiddenRoutePattern($target);
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     * @return list<array<string,mixed>>
     */
    private function internalLinks(array $edges): array
    {
        $links = [];
        foreach ($edges as $edge) {
            $links[] = [
                'href' => $this->normalizePath((string) ($edge['target_path'] ?? $edge['target_url'] ?? '')),
                'anchor_text' => trim((string) ($edge['anchor_text_suggestion'] ?? '')),
                'role' => (string) ($edge['edge_type'] ?? ''),
                'safe_public_route' => true,
                'priority' => (string) ($edge['priority'] ?? ''),
                'source' => self::GRAPH_VERSION,
                'reason' => (string) ($edge['reason'] ?? ''),
            ];
        }

        return $links;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,string>|null
     */
    private function identityForNode(array $node, string $sourcePath): ?array
    {
        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $sourcePath, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);
            $variantCode = strtoupper((string) $matches['variant']);

            return [
                'url' => (string) ($node['url'] ?? $sourcePath),
                'path' => $sourcePath,
                'locale' => $locale,
                'page_type' => 'variant',
                'canonical_type_code' => $canonicalType,
                'variant_code' => $variantCode,
                'runtime_type_code' => $canonicalType.'-'.$variantCode,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-a-vs-\k<type>-t$#i', $sourcePath, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);

            return [
                'url' => (string) ($node['url'] ?? $sourcePath),
                'path' => $sourcePath,
                'locale' => $locale,
                'page_type' => 'comparison',
                'canonical_type_code' => $canonicalType,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,string>  $identity
     * @return array{id?:int}
     */
    private function targetRecord(array $identity): array
    {
        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', (string) $identity['locale'])
            ->where('canonical_type_code', (string) $identity['canonical_type_code'])
            ->first();

        if (! $profile instanceof PersonalityProfile) {
            return [];
        }

        if (($identity['page_type'] ?? null) === 'comparison') {
            return ['id' => (int) $profile->id];
        }

        $variant = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', (string) ($identity['runtime_type_code'] ?? ''))
            ->first();

        return $variant instanceof PersonalityProfileVariant ? ['id' => (int) $variant->id] : [];
    }

    private function existingRevision(
        string $pageType,
        string $targetField,
        int $targetId,
        string $sourceSha256,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $storedSha = (string) ($snapshot[self::SNAPSHOT_KEY]['source']['source_sha256'] ?? '');
            if ($storedSha === $sourceSha256) {
                return $revision;
            }
        }

        return null;
    }

    private function nextRevisionNo(string $pageType, string $targetField, int $targetId): int
    {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        return ((int) $query->max('revision_no')) + 1;
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     */
    private function createRevision(array $preparedRow): PersonalityProfileRevision|PersonalityProfileVariantRevision
    {
        $pageType = (string) ($preparedRow['page_type'] ?? '');
        $targetId = (int) ($preparedRow['target_id'] ?? 0);
        $revisionNo = (int) ($preparedRow['next_revision_no'] ?? 0);
        $snapshot = is_array($preparedRow['snapshot_preview'] ?? null) ? $preparedRow['snapshot_preview'] : [];
        $note = $pageType === 'comparison'
            ? 'mbti64 internal-link graph comparison draft: '.((string) ($preparedRow['path'] ?? ''))
            : 'mbti64 internal-link graph variant draft: '.((string) ($preparedRow['path'] ?? ''));

        if ($pageType === 'comparison') {
            return PersonalityProfileRevision::query()->create([
                'profile_id' => $targetId,
                'revision_no' => $revisionNo,
                'snapshot_json' => $snapshot,
                'note' => $note,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);
        }

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $targetId,
            'revision_no' => $revisionNo,
            'snapshot_json' => $snapshot,
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $node
     * @param  array<string,string>  $identity
     * @param  list<array<string,mixed>>  $activeEdges
     * @param  list<array<string,mixed>>  $blockedEdges
     * @param  list<array<string,mixed>>  $internalLinks
     * @return array<string,mixed>
     */
    private function snapshotPayload(
        array $graph,
        array $node,
        array $identity,
        string $sourceSha256,
        array $activeEdges,
        array $blockedEdges,
        array $internalLinks,
    ): array {
        return [
            self::SNAPSHOT_KEY => [
                'source' => [
                    'artifact' => 'MBTI64-INTERNAL-LINK-GRAPH-01',
                    'version' => (string) ($graph['version'] ?? ''),
                    'status' => (string) ($graph['status'] ?? ''),
                    'source_sha256' => $sourceSha256,
                ],
                'identity' => $identity,
                'first_class_draft_fields' => [
                    'url' => (string) ($node['url'] ?? $identity['path']),
                    'locale' => (string) $identity['locale'],
                    'page_type' => (string) $identity['page_type'],
                    'internal_links' => $internalLinks,
                ],
                'structured_metadata' => [
                    'recommended_edge_count' => count($activeEdges),
                    'blocked_edge_count_for_source' => count($blockedEdges),
                    'blocked_edges' => $blockedEdges,
                    'graph_summary' => is_array($graph['summary'] ?? null) ? $graph['summary'] : [],
                ],
                'safety_holds' => [
                    'draft_only' => true,
                    'publish_attempted' => false,
                    'index_attempted' => false,
                    'sitemap_llms_release_attempted' => false,
                    'search_release_attempted' => false,
                    'runtime_content_updated' => false,
                ],
                'raw_graph_node' => $node,
                'raw_recommended_edges' => $activeEdges,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function countRows(array $rows, string $pageType): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['page_type'] ?? null) === $pageType
        ));
    }

    private function localeFromPrefix(string $prefix): string
    {
        return strtolower($prefix) === 'zh' ? 'zh-CN' : 'en';
    }

    private function normalizePath(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?: $trimmed);
        if ($path === '') {
            return '';
        }

        $path = '/'.ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }

    private function containsForbiddenRoutePattern(string $value): bool
    {
        foreach (self::FORBIDDEN_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
