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

    private const BOUNDED_EDGE_TYPES = [
        'variant_at_pair',
        'variant_to_comparison',
    ];

    private const EXCLUDED_BOUNDED_EDGE_TYPES = [
        'related_test',
    ];

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
        $boundary = $this->boundary($options);
        if ($write && $boundary === null) {
            $errors[] = [
                'field' => 'options',
                'code' => 'bounded_write_options_required',
                'message' => 'Writes require locale=en, page_type=variant, expected_rows=32 and expected_edges=64.',
            ];
        }

        $nodes = $this->nodes($graph);
        if ($boundary !== null) {
            $nodes = array_values(array_filter(
                $nodes,
                function (array $node) use ($boundary): bool {
                    $path = $this->normalizePath((string) ($node['path'] ?? $node['url'] ?? ''));
                    $identity = $this->identityForNode($node, $path);

                    return $identity !== null
                        && $identity['locale'] === $boundary['locale']
                        && $identity['page_type'] === $boundary['page_type'];
                }
            ));
        }

        $nodesByPath = [];
        foreach ($nodes as $node) {
            $path = $this->normalizePath((string) ($node['path'] ?? $node['url'] ?? ''));
            if ($path !== '') {
                $nodesByPath[$path] = $node;
            }
        }
        if ($boundary !== null) {
            $this->validateBoundedSourceInventory($nodesByPath, $errors);
        }

        $recommendedBySource = $this->groupEdges($graph, 'recommendedEdges');
        $blockedBySource = $this->groupEdges($graph, 'blockedEdges');
        $cohortPayloadSha256 = $boundary !== null
            ? $this->cohortPayloadSha256(
                $nodesByPath,
                $this->edges($graph, 'recommendedEdges'),
                $errors
            )
            : null;
        $privateRecommendedEdgeCount = 0;
        foreach ($this->edges($graph, 'recommendedEdges') as $edge) {
            $rawTarget = trim((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));
            if ($this->containsForbiddenRoutePattern($rawTarget)) {
                $privateRecommendedEdgeCount++;
                $errors[] = [
                    'field' => 'recommendedEdges',
                    'code' => 'forbidden_recommended_edge_target',
                    'message' => 'Recommended edge target contains a forbidden private route pattern.',
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

            $sourceRecommendedEdges = $recommendedBySource[$sourcePath] ?? [];
            $activeEdges = $boundary !== null
                ? $this->boundedEdgesForSource($sourcePath, $sourceRecommendedEdges, $identity, $errors)
                : $this->activeEdgesForSource($sourceRecommendedEdges);
            $blockedEdges = array_values($blockedBySource[$sourcePath] ?? []);
            $internalLinks = $this->internalLinks($activeEdges, $identity, $boundary !== null);

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
                ? $this->existingRevision(
                    $pageType,
                    $targetField,
                    $targetId,
                    $sourceSha256,
                    $cohortPayloadSha256,
                    $boundary
                )
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
                    $cohortPayloadSha256,
                    $boundary,
                    $activeEdges,
                    $blockedEdges,
                    $internalLinks
                ),
            ];
        }

        if ($boundary !== null) {
            $activeEdgeCount = array_sum(array_map(
                static fn (array $row): int => (int) ($row['active_internal_link_count'] ?? 0),
                $preparedRows
            ));
            $targetIds = array_values(array_filter(
                array_column($preparedRows, 'target_id'),
                static fn (mixed $targetId): bool => is_int($targetId)
            ));
            if (count($preparedRows) !== $boundary['expected_rows']) {
                $errors[] = [
                    'field' => 'bounded_scope.rows',
                    'code' => 'bounded_row_count_mismatch',
                    'message' => 'Expected exactly '.$boundary['expected_rows'].' bounded rows; found '.count($preparedRows).'.',
                ];
            }
            if ($activeEdgeCount !== $boundary['expected_edges']) {
                $errors[] = [
                    'field' => 'bounded_scope.edges',
                    'code' => 'bounded_edge_count_mismatch',
                    'message' => 'Expected exactly '.$boundary['expected_edges'].' bounded edges; found '.$activeEdgeCount.'.',
                ];
            }
            if (count($targetIds) !== $boundary['expected_rows']
                || count(array_unique($targetIds, SORT_REGULAR)) !== $boundary['expected_rows']) {
                $errors[] = [
                    'field' => 'bounded_scope.target_ids',
                    'code' => 'bounded_target_inventory_mismatch',
                    'message' => 'Bounded rows must resolve to exactly 32 distinct CMS variant target IDs.',
                ];
            }
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary(
                $graph,
                $sourceSha256,
                $write,
                $boundary,
                $cohortPayloadSha256
            ), [
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

        return array_merge($this->baseSummary(
            $graph,
            $sourceSha256,
            $write,
            $boundary,
            $cohortPayloadSha256
        ), [
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
    private function baseSummary(
        array $graph,
        string $sourceSha256,
        bool $write,
        ?array $boundary = null,
        ?string $cohortPayloadSha256 = null,
    ): array {
        return [
            'artifact' => 'MBTI64-CMS-INTERNAL-LINK-DRAFT-01',
            'source_version' => (string) ($graph['version'] ?? ''),
            'source_status' => (string) ($graph['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'cohort_payload_sha256' => $cohortPayloadSha256,
            'bounded_scope' => $boundary,
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
     * @param  array<string,mixed>  $options
     * @return array{locale:string,page_type:string,expected_rows:int,expected_edges:int}|null
     */
    private function boundary(array $options): ?array
    {
        $boundary = [
            'locale' => trim((string) ($options['locale'] ?? '')),
            'page_type' => trim((string) ($options['page_type'] ?? '')),
            'expected_rows' => (int) ($options['expected_rows'] ?? 0),
            'expected_edges' => (int) ($options['expected_edges'] ?? 0),
        ];
        if ($boundary === [
            'locale' => '',
            'page_type' => '',
            'expected_rows' => 0,
            'expected_edges' => 0,
        ]) {
            return null;
        }

        $expected = [
            'locale' => 'en',
            'page_type' => 'variant',
            'expected_rows' => 32,
            'expected_edges' => 64,
        ];
        if ($boundary !== $expected) {
            throw new \RuntimeException(
                'Bounded cohort requires locale=en, page_type=variant, expected_rows=32 and expected_edges=64.'
            );
        }

        return $boundary;
    }

    /**
     * @param  array<string,array<string,mixed>>  $nodesByPath
     * @param  list<array<string,string>>  $errors
     */
    private function validateBoundedSourceInventory(array $nodesByPath, array &$errors): void
    {
        $expectedPaths = [];
        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            foreach (['a', 't'] as $variantCode) {
                $expectedPaths[] = '/en/personality/'.strtolower($typeCode).'-'.$variantCode;
            }
        }

        $actualPaths = array_keys($nodesByPath);
        sort($expectedPaths, SORT_STRING);
        sort($actualPaths, SORT_STRING);

        if ($actualPaths !== $expectedPaths) {
            $errors[] = [
                'field' => 'bounded_scope.sources',
                'code' => 'bounded_source_inventory_mismatch',
                'message' => 'Bounded sources must exactly match the 32 canonical lowercase English MBTI variant paths.',
            ];
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $nodesByPath
     * @param  list<array<string,mixed>>  $recommendedEdges
     * @param  list<array<string,string>>  $errors
     */
    private function cohortPayloadSha256(
        array $nodesByPath,
        array $recommendedEdges,
        array &$errors,
    ): string {
        $sources = array_keys($nodesByPath);
        sort($sources, SORT_STRING);
        $sourceLookup = array_fill_keys($sources, true);
        $selectedEdges = [];

        foreach ($recommendedEdges as $edge) {
            $sourcePath = $this->normalizePath((string) ($edge['source_path'] ?? $edge['source_url'] ?? ''));
            if (! isset($sourceLookup[$sourcePath])
                || ! in_array((string) ($edge['edge_type'] ?? ''), self::BOUNDED_EDGE_TYPES, true)) {
                continue;
            }

            $node = $nodesByPath[$sourcePath] ?? [];
            $identity = $this->identityForNode($node, $sourcePath);
            if ($identity === null) {
                $errors[] = [
                    'field' => 'nodes.'.$sourcePath,
                    'code' => 'bounded_cohort_identity_missing',
                    'message' => 'Unable to resolve the bounded cohort identity for '.$sourcePath.'.',
                ];

                continue;
            }

            $selectedEdges[] = $this->withApprovedAnchorText($edge, $identity);
        }

        $payload = $this->canonicalize([
            'sources' => $sources,
            'edges' => $selectedEdges,
        ]);
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
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
            $rawTarget = trim((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));
            $target = $this->normalizePath($rawTarget);

            return ($edge['safe_public_route'] ?? null) === true
                && trim((string) ($edge['publish_blocker_if_any'] ?? '')) === ''
                && $target !== ''
                && ! $this->containsForbiddenRoutePattern($rawTarget)
                && ! $this->containsForbiddenRoutePattern($target);
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     * @param  array<string,string>  $identity
     * @param  list<array<string,string>>  $errors
     * @return list<array<string,mixed>>
     */
    private function boundedEdgesForSource(
        string $sourcePath,
        array $edges,
        array $identity,
        array &$errors,
    ): array {
        $allowed = [];
        $roleCounts = array_fill_keys(self::BOUNDED_EDGE_TYPES, 0);

        foreach ($edges as $edge) {
            $edgeType = (string) ($edge['edge_type'] ?? '');
            if (in_array($edgeType, self::EXCLUDED_BOUNDED_EDGE_TYPES, true)) {
                continue;
            }
            if (! in_array($edgeType, self::BOUNDED_EDGE_TYPES, true)) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'unsupported_bounded_edge_type',
                    'message' => 'Unsupported bounded edge type '.$edgeType.' for '.$sourcePath.'.',
                ];

                continue;
            }

            $rawTarget = trim((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));
            $target = $this->normalizePath($rawTarget);
            if ($this->containsForbiddenRoutePattern($rawTarget)) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'unsafe_bounded_edge',
                    'message' => 'Bounded edge raw target contains a forbidden route or query parameter.',
                ];

                continue;
            }
            if (($edge['locale'] ?? null) !== 'en' || ! str_starts_with($target, '/en/personality/')) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'bounded_edge_locale_mismatch',
                    'message' => 'Bounded English edge target must remain under /en/personality/: '.$target,
                ];

                continue;
            }

            $type = strtolower((string) $identity['canonical_type_code']);
            $expectedTarget = $edgeType === 'variant_at_pair'
                ? '/en/personality/'.$type.'-'.(strtolower((string) $identity['variant_code']) === 'a' ? 't' : 'a')
                : '/en/personality/'.$type.'-a-vs-'.$type.'-t';
            if ($target !== $expectedTarget) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'bounded_edge_target_mismatch',
                    'message' => 'Expected '.$edgeType.' target '.$expectedTarget.' for '.$sourcePath.'; found '.$target.'.',
                ];

                continue;
            }

            if (($edge['safe_public_route'] ?? null) !== true
                || trim((string) ($edge['publish_blocker_if_any'] ?? '')) !== ''
                || $target === ''
                || $this->containsForbiddenRoutePattern($target)) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'unsafe_bounded_edge',
                    'message' => 'Bounded edge must be a safe public route with no publish blocker: '.$target,
                ];

                continue;
            }

            $roleCounts[$edgeType]++;
            $allowed[] = $this->withApprovedAnchorText($edge, $identity);
        }

        foreach ($roleCounts as $edgeType => $count) {
            if ($count !== 1) {
                $errors[] = [
                    'field' => 'recommendedEdges.'.$sourcePath,
                    'code' => 'bounded_edge_role_count_mismatch',
                    'message' => 'Expected exactly one '.$edgeType.' edge for '.$sourcePath.'; found '.$count.'.',
                ];
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string,mixed>  $edge
     * @param  array<string,string>  $identity
     * @return array<string,mixed>
     */
    private function withApprovedAnchorText(array $edge, array $identity): array
    {
        $type = (string) $identity['canonical_type_code'];
        $edgeType = (string) ($edge['edge_type'] ?? '');
        $edge['anchor_text'] = $edgeType === 'variant_at_pair'
            ? 'Compare '.$type.'-A and '.$type.'-T'
            : $type.'-A vs '.$type.'-T';
        $target = $this->normalizePath((string) ($edge['target_path'] ?? $edge['target_url'] ?? ''));
        $edge['target_variant'] = strtoupper((string) strrchr($target, '-'));
        $edge['target_variant'] = ltrim($edge['target_variant'], '-');

        return $edge;
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     * @param  array<string,string>  $identity
     * @return list<array<string,mixed>>
     */
    private function internalLinks(array $edges, array $identity, bool $bounded): array
    {
        $links = [];
        foreach ($edges as $edge) {
            $links[] = [
                'href' => $this->normalizePath((string) ($edge['target_path'] ?? $edge['target_url'] ?? '')),
                'anchor_text' => $bounded
                    ? trim((string) ($edge['anchor_text'] ?? ''))
                    : trim((string) ($edge['anchor_text_suggestion'] ?? '')),
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
        ?string $cohortPayloadSha256,
        ?array $boundary,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $storedSha = (string) ($snapshot[self::SNAPSHOT_KEY]['source']['source_sha256'] ?? '');
            $storedCohortSha = (string) ($snapshot[self::SNAPSHOT_KEY]['source']['cohort_payload_sha256'] ?? '');
            $storedBoundary = $snapshot[self::SNAPSHOT_KEY]['source']['bounded_scope'] ?? null;
            if ($storedSha === $sourceSha256
                && ($boundary === null || (
                    $storedCohortSha === $cohortPayloadSha256
                    && $storedBoundary === $boundary
                ))) {
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
        ?string $cohortPayloadSha256,
        ?array $boundary,
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
                    'cohort_payload_sha256' => $cohortPayloadSha256,
                    'bounded_scope' => $boundary,
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
