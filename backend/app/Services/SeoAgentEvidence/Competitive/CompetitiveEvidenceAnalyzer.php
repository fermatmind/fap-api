<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;

final class CompetitiveEvidenceAnalyzer
{
    private const ROLE_ID = 'seo.expert.competitor_research';

    private const MODE_ID = 'competitive_evidence';

    public function __construct(
        private readonly CompetitiveEvidenceBoundaryGuard $guard,
        private readonly CompetitiveSourceRegistry $registry,
        private readonly SeoEvidenceCanonicalHasher $hasher,
    ) {}

    /**
     * @param  array{
     *     page_family:string,
     *     locale:string,
     *     projections:list<array<string,mixed>>,
     *     source_policies:list<array<string,mixed>>,
     *     authority:array<string,mixed>,
     *     measurement:array<string,mixed>,
     *     dependency_ingestion?:array{external_reads?:int}
     * }  $input
     * @return array<string, mixed>
     */
    public function analyze(array $input): array
    {
        $pageFamily = (string) ($input['page_family'] ?? '');
        $locale = (string) ($input['locale'] ?? '');
        $authority = (array) ($input['authority'] ?? []);
        $measurement = (array) ($input['measurement'] ?? []);
        $policies = $this->sortedRecords((array) ($input['source_policies'] ?? []), 'source_id');
        $allProjections = $this->verifiedProjections((array) ($input['projections'] ?? []));
        $competitors = array_values(array_filter(
            $allProjections,
            static fn (array $projection): bool => ($projection['source_class'] ?? null) === 'competitor_public'
        ));
        $fermatmind = array_values(array_filter(
            $allProjections,
            static fn (array $projection): bool => ($projection['source_class'] ?? null) === 'fermatmind_public'
        ));

        $freshness = $this->freshness($policies, $authority, $measurement);
        $conflict = ($authority['conflict'] ?? false) === true || ($measurement['conflict'] ?? false) === true;
        $requiredMissing = $pageFamily === ''
            || ! in_array($locale, ['en', 'zh-CN'], true)
            || count($competitors) < 2
            || count(array_filter($competitors, fn (array $projection): bool => $this->substantiveProjection($projection))) !== count($competitors)
            || count($fermatmind) < 2
            || ! $this->hash((string) ($authority['source_hash'] ?? ''))
            || ! $this->hash((string) ($measurement['source_hash'] ?? ''));
        if ($conflict) {
            $freshness = 'conflict';
        } elseif ($requiredMissing && $freshness === 'fresh') {
            $freshness = 'unknown';
        }

        $authorityModuleSequence = array_values(array_filter(
            array_map('strval', (array) ($authority['modules'] ?? [])),
            static fn (string $module): bool => $module !== '',
        ));
        $authorityModules = $this->strings($authorityModuleSequence);
        $authorityRelations = $this->relationKeys((array) ($authority['entity_relations'] ?? []));
        $authorityInformation = $this->strings((array) ($authority['information_ids'] ?? []));
        $semantic = $this->registry->semanticRegistry();
        $registeredEntities = $this->strings(array_values((array) ($semantic['entity_signals'] ?? [])));
        $registeredRelations = $this->strings(array_values((array) ($semantic['relation_signals'] ?? [])));

        $structureGaps = $this->structureGaps($competitors, $authorityModules, $authorityModuleSequence);
        $entityGaps = $this->entityGaps($competitors, $authorityRelations, $registeredEntities, $registeredRelations);
        $informationGain = $this->informationGain($entityGaps, $authorityInformation);
        $internalLinks = $this->internalLinkPatterns($competitors);
        $claims = $this->competitorClaims($competitors);
        $unknowns = $this->unknowns($competitors, $registeredEntities, $registeredRelations);

        $sourceCount = count(array_unique(array_column($competitors, 'source_id')));
        $demandWindows = $this->demandWindowCount($measurement);
        $multiSource = $sourceCount >= 2;
        $entityGap = $entityGaps !== [];
        $structureGap = $structureGaps !== [];
        $ownerGap = ($authority['owner_gap_confirmed'] ?? false) === true || $entityGap || $structureGap;
        $complete = ! $requiredMissing && ! $conflict && $freshness === 'fresh';
        $multiSourceJudgment = $structureGaps !== [] || $entityGaps !== [] || $informationGain !== [] || $internalLinks !== [];

        $necessity = $this->pageNecessity(
            $complete,
            $demandWindows,
            $multiSource,
            $ownerGap,
            $entityGap,
            $structureGap,
        );
        [$similarity, $components, $similarityComplete] = $this->templateSimilarity($fermatmind);
        $targetLocaleAdds = $this->targetLocaleAdds($fermatmind, $locale);
        $translationOnly = ! $complete || ! $similarityComplete
            ? 'unknown'
            : ($similarity >= 9000 && ! $targetLocaleAdds ? 'yes' : 'no');

        $holds = $this->holds($freshness, $requiredMissing, $conflict, $multiSource, $necessity);
        if (! $multiSourceJudgment) {
            $holds = $this->strings([...$holds, 'MULTI_SOURCE_FINDING_HOLD']);
        }
        $evidenceRefs = $this->evidenceRefs($allProjections, $authority, $measurement);

        $finding = [
            'version' => 'seo.competitive_evidence_finding.v1',
            'role_id' => self::ROLE_ID,
            'mode_id' => self::MODE_ID,
            'page_family' => $pageFamily,
            'locale' => $locale,
            'structure_gaps' => $structureGaps,
            'entity_gaps' => $entityGaps,
            'information_gain' => $informationGain,
            'internal_link_patterns' => $internalLinks,
            'competitor_claims' => $claims,
            'unknowns' => $unknowns,
            'holds' => $holds,
            'evidence_refs' => $evidenceRefs,
            'outreach_actions' => 0,
            'digital_pr_scope' => 'deferred_p2_manual',
            'execution_allowed' => false,
        ];
        $finding['finding_hash'] = $this->hasher->hash($finding);

        $handoff = [
            'version' => 'seo.competitive_11i_handoff.v1',
            'page_family' => $pageFamily,
            'locale' => $locale,
            'page_necessity' => $necessity,
            'page_necessity_basis' => [
                'demand_window_count' => $demandWindows,
                'owner_gap_confirmed' => $ownerGap,
                'entity_gap_confirmed' => $entityGap,
                'structure_gap_confirmed' => $structureGap,
                'multi_source_confirmed' => $multiSource,
            ],
            'template_similarity' => $similarity,
            'template_similarity_components' => $components,
            'translation_only' => $translationOnly,
            'source_freshness' => $freshness,
            'source_count' => $sourceCount,
            'measurement_window_count' => $demandWindows,
            'hold_reasons' => $holds,
            'outreach_actions' => 0,
            'digital_pr_scope' => 'deferred_p2_manual',
            'execution_allowed' => false,
        ];
        $handoff['handoff_hash'] = $this->hasher->hash($handoff);

        $status = $complete && $multiSourceJudgment && ! in_array($necessity, ['conditional', 'unknown'], true) ? 'READY' : 'HOLD';
        $output = [
            'version' => 'seo.competitive_evidence_output.v1',
            'status' => $status,
            'findings' => [$finding],
            '11i_handoff' => $handoff,
            'hold_reason' => $status === 'READY' ? null : ($holds[0] ?? 'COMPETITIVE_EVIDENCE_HOLD'),
            'dependency_ingestion' => [
                'external_reads' => max(0, min(64, (int) ($input['dependency_ingestion']['external_reads'] ?? 0))),
            ],
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'outreach_actions' => 0,
            'digital_pr_scope' => 'deferred_p2_manual',
            'execution_allowed' => false,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }

    /** @param list<array<string,mixed>> $projections @return list<array<string,mixed>> */
    private function verifiedProjections(array $projections): array
    {
        $verified = [];
        foreach ($projections as $projection) {
            if (! is_array($projection)
                || ($projection['version'] ?? null) !== 'seo.competitive_page_projection.v2'
                || ! $this->guard->projection($projection)) {
                continue;
            }
            $verified[] = $projection;
        }

        return $this->sortedRecords($verified, 'projection_hash');
    }

    /** @param array<string, mixed> $projection */
    private function substantiveProjection(array $projection): bool
    {
        $modules = array_values(array_filter(
            (array) data_get($projection, 'structure.modules', []),
            static fn (mixed $module): bool => is_array($module) && ($module['module_type'] ?? 'other_registered') !== 'other_registered',
        ));

        return $modules !== []
            && (array) data_get($projection, 'structure.entity_ids', []) !== []
            && (array) data_get($projection, 'structure.entity_relations', []) !== []
            && (array) data_get($projection, 'structure.internal_link_patterns', []) !== [];
    }

    /** @param list<array<string,mixed>> $policies @param array<string,mixed> $authority @param array<string,mixed> $measurement */
    private function freshness(array $policies, array $authority, array $measurement): string
    {
        $states = [(string) ($authority['freshness_state'] ?? 'unknown'), (string) ($measurement['freshness_state'] ?? 'unknown')];
        foreach ($policies as $policy) {
            $states[] = (string) ($policy['freshness_state'] ?? 'unknown');
        }
        foreach (['conflict', 'expired', 'stale', 'unknown'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return $states !== [] && count(array_filter($states, static fn (string $state): bool => $state === 'fresh')) === count($states)
            ? 'fresh'
            : 'unknown';
    }

    /** @param list<array<string,mixed>> $competitors @param list<string> $authorityModules @param list<string> $authoritySequence @return list<array<string,mixed>> */
    private function structureGaps(array $competitors, array $authorityModules, array $authoritySequence): array
    {
        $sources = [];
        $coverage = [];
        $orderPairs = [];
        foreach ($competitors as $projection) {
            $source = (string) $projection['source_id'];
            $sequence = $this->moduleTypes((array) ($projection['structure']['modules'] ?? []));
            $counts = array_count_values($sequence);
            foreach ($counts as $type => $count) {
                $coverage[$type][$source] = $count;
            }
            for ($index = 0, $end = count($sequence) - 1; $index < $end; $index++) {
                $pair = $sequence[$index].'|'.$sequence[$index + 1];
                $orderPairs[$pair][$source] = true;
            }
            foreach ((array) ($projection['structure']['modules'] ?? []) as $module) {
                $type = is_array($module) ? (string) ($module['module_type'] ?? '') : '';
                if ($type !== '') {
                    $sources[$type][$source] = true;
                }
            }
        }
        ksort($sources, SORT_STRING);
        $gaps = [];
        foreach ($sources as $type => $sourceSet) {
            if (count($sourceSet) < 2 || in_array($type, $authorityModules, true)) {
                continue;
            }
            $value = ['module_type' => $type, 'gap_kind' => 'missing', 'source_count' => count($sourceSet)];
            $value['evidence_hash'] = $this->hasher->hash($value);
            $gaps['missing|'.$type] = $value;
        }
        $authorityCounts = array_count_values($authoritySequence);
        ksort($coverage, SORT_STRING);
        foreach ($coverage as $type => $sourceCounts) {
            if (! in_array($type, $authorityModules, true) || count($sourceCounts) < 2) {
                continue;
            }
            $minimum = min($sourceCounts);
            if (($authorityCounts[$type] ?? 0) >= $minimum) {
                continue;
            }
            $value = ['module_type' => $type, 'gap_kind' => 'coverage', 'source_count' => count($sourceCounts)];
            $value['evidence_hash'] = $this->hasher->hash([$value, 'minimum_coverage' => $minimum]);
            $gaps['coverage|'.$type] = $value;
        }
        $positions = [];
        foreach ($authoritySequence as $position => $type) {
            $positions[$type] ??= $position;
        }
        ksort($orderPairs, SORT_STRING);
        foreach ($orderPairs as $pair => $sourceSet) {
            if (count($sourceSet) < 2) {
                continue;
            }
            [$left, $right] = explode('|', $pair, 2);
            if (! isset($positions[$left], $positions[$right]) || $positions[$left] < $positions[$right]) {
                continue;
            }
            $moduleType = $left.':before:'.$right;
            $value = ['module_type' => $moduleType, 'gap_kind' => 'ordering', 'source_count' => count($sourceSet)];
            $value['evidence_hash'] = $this->hasher->hash($value);
            $gaps['ordering|'.$moduleType] = $value;
        }

        ksort($gaps, SORT_STRING);

        return array_slice(array_values($gaps), 0, 64);
    }

    /** @param list<array<string,mixed>> $competitors @param list<string> $authorityRelations @param list<string> $entities @param list<string> $relations @return list<array<string,mixed>> */
    private function entityGaps(array $competitors, array $authorityRelations, array $entities, array $relations): array
    {
        $sources = [];
        $records = [];
        foreach ($competitors as $projection) {
            $source = (string) $projection['source_id'];
            foreach ((array) ($projection['structure']['entity_relations'] ?? []) as $relation) {
                if (! is_array($relation)) {
                    continue;
                }
                $entity = (string) ($relation['entity_id'] ?? '');
                $kind = (string) ($relation['relation'] ?? '');
                $key = $this->relationKey($relation);
                if ($key === '' || ! in_array($entity, $entities, true) || ! in_array($kind, $relations, true)) {
                    continue;
                }
                $sources[$key][$source] = true;
                $records[$key] = ['entity_id' => $entity, 'relation' => $kind];
            }
        }
        ksort($records, SORT_STRING);
        $gaps = [];
        foreach ($records as $key => $record) {
            if (count($sources[$key] ?? []) < 2 || in_array($key, $authorityRelations, true)) {
                continue;
            }
            $value = $record + ['gap_kind' => 'missing', 'source_count' => count($sources[$key])];
            $value['evidence_hash'] = $this->hasher->hash([$key, $value]);
            $gaps[] = $value;
        }

        return array_slice($gaps, 0, 128);
    }

    /** @param list<array<string,mixed>> $entityGaps @param list<string> $authorityInformation @return list<array<string,mixed>> */
    private function informationGain(array $entityGaps, array $authorityInformation): array
    {
        $result = [];
        foreach ($entityGaps as $gap) {
            $id = (string) $gap['entity_id'];
            if (in_array($id, $authorityInformation, true)) {
                continue;
            }
            $value = [
                'candidate_kind' => 'dimension',
                'registered_id' => $id,
                'relation' => 'clarifies',
                'source_count' => (int) $gap['source_count'],
            ];
            $value['evidence_hash'] = $this->hasher->hash($value);
            $result[] = $value;
        }

        return array_slice($result, 0, 128);
    }

    /** @param list<array<string,mixed>> $competitors @return list<array<string,mixed>> */
    private function internalLinkPatterns(array $competitors): array
    {
        $sources = [];
        $records = [];
        foreach ($competitors as $projection) {
            $source = (string) $projection['source_id'];
            foreach ((array) ($projection['structure']['internal_link_patterns'] ?? []) as $pattern) {
                if (! is_array($pattern) || ! $this->hash((string) ($pattern['pattern_hash'] ?? ''))) {
                    continue;
                }
                $key = implode('|', [
                    (string) ($pattern['from_family'] ?? ''),
                    (string) ($pattern['relation'] ?? ''),
                    (string) ($pattern['to_family'] ?? ''),
                    (string) ($pattern['count_bucket'] ?? ''),
                ]);
                $sources[$key][$source] = true;
                $records[$key] = [
                    'from_family' => (string) $pattern['from_family'],
                    'relation' => (string) $pattern['relation'],
                    'to_family' => (string) $pattern['to_family'],
                    'count_bucket' => (string) $pattern['count_bucket'],
                    'pattern_hash' => (string) $pattern['pattern_hash'],
                ];
            }
        }
        ksort($records, SORT_STRING);

        return array_slice(array_values(array_filter(
            $records,
            static fn (array $record, string $key): bool => count($sources[$key] ?? []) >= 2,
            ARRAY_FILTER_USE_BOTH,
        )), 0, 64);
    }

    /** @param list<array<string,mixed>> $competitors @return list<array<string,mixed>> */
    private function competitorClaims(array $competitors): array
    {
        $claims = [];
        foreach ($competitors as $projection) {
            foreach ((array) ($projection['structure']['claim_signals'] ?? []) as $claim) {
                if (! is_array($claim) || ! $this->hash((string) ($claim['claim_hash'] ?? ''))) {
                    continue;
                }
                $value = [
                    'claim_id' => (string) ($claim['claim_id'] ?? ''),
                    'claim_class' => 'competitor_claim',
                    'fact_upgrade_allowed' => false,
                    'source_ref' => (string) $projection['projection_hash'],
                    'claim_hash' => (string) $claim['claim_hash'],
                ];
                $claims[$value['source_ref'].'|'.$value['claim_hash']] = $value;
            }
        }
        ksort($claims, SORT_STRING);

        return array_slice(array_values($claims), 0, 64);
    }

    /** @param list<array<string,mixed>> $competitors @param list<string> $entities @param list<string> $relations @return list<string> */
    private function unknowns(array $competitors, array $entities, array $relations): array
    {
        $unknowns = [];
        foreach ($competitors as $projection) {
            foreach ((array) ($projection['structure']['entity_ids'] ?? []) as $entity) {
                if (! in_array((string) $entity, $entities, true)) {
                    $unknowns[] = 'UNKNOWN_ENTITY_SIGNAL';
                }
            }
            foreach ((array) ($projection['structure']['entity_relations'] ?? []) as $relation) {
                if (is_array($relation) && ! in_array((string) ($relation['relation'] ?? ''), $relations, true)) {
                    $unknowns[] = 'UNKNOWN_RELATION_SIGNAL';
                }
            }
        }

        return $this->strings($unknowns);
    }

    /** @param array<string,mixed> $measurement */
    private function demandWindowCount(array $measurement): int
    {
        $windows = (array) ($measurement['demand_windows'] ?? []);

        return min(3, count(array_filter($windows, static fn (mixed $window): bool => $window === true || $window === 'demand')));
    }

    private function pageNecessity(bool $complete, int $windows, bool $multiSource, bool $owner, bool $entity, bool $structure): string
    {
        if (! $complete) {
            return 'unknown';
        }
        if ($windows >= 2 && $multiSource && $owner && $entity && $structure) {
            return 'necessary';
        }
        if (! $multiSource) {
            return 'conditional';
        }
        if ($windows === 0 || (! $entity && ! $structure)) {
            return 'not_supported';
        }

        return 'conditional';
    }

    /** @param list<array<string,mixed>> $fermatmind @return array{0:int,1:array<string,int>,2:bool} */
    private function templateSimilarity(array $fermatmind): array
    {
        $byLocale = [];
        foreach ($fermatmind as $projection) {
            $byLocale[(string) $projection['locale']] = $projection;
        }
        if (! isset($byLocale['en'], $byLocale['zh-CN'])) {
            return [0, $this->emptyComponents(), false];
        }
        $left = (array) $byLocale['en']['structure'];
        $right = (array) $byLocale['zh-CN']['structure'];
        $leftModules = $this->moduleTypes((array) ($left['modules'] ?? []));
        $rightModules = $this->moduleTypes((array) ($right['modules'] ?? []));
        $components = [
            'module_set_bp' => $this->jaccard($leftModules, $rightModules, 4000),
            'module_order_bp' => $this->lcsScore($leftModules, $rightModules, 3000),
            'entity_relation_bp' => $this->jaccard($this->relationKeys((array) ($left['entity_relations'] ?? [])), $this->relationKeys((array) ($right['entity_relations'] ?? [])), 2000),
            'internal_link_pattern_bp' => $this->jaccard($this->patternKeys((array) ($left['internal_link_patterns'] ?? [])), $this->patternKeys((array) ($right['internal_link_patterns'] ?? [])), 1000),
        ];

        return [array_sum($components), $components, true];
    }

    /** @param list<array<string,mixed>> $fermatmind */
    private function targetLocaleAdds(array $fermatmind, string $targetLocale): bool
    {
        $byLocale = [];
        foreach ($fermatmind as $projection) {
            $byLocale[(string) $projection['locale']] = (array) ($projection['structure'] ?? []);
        }
        $sourceLocale = $targetLocale === 'zh-CN' ? 'en' : 'zh-CN';
        if (! isset($byLocale[$targetLocale], $byLocale[$sourceLocale])) {
            return true;
        }
        $targetEntities = $this->strings((array) ($byLocale[$targetLocale]['entity_ids'] ?? []));
        $sourceEntities = $this->strings((array) ($byLocale[$sourceLocale]['entity_ids'] ?? []));
        $targetRelations = $this->relationKeys((array) ($byLocale[$targetLocale]['entity_relations'] ?? []));
        $sourceRelations = $this->relationKeys((array) ($byLocale[$sourceLocale]['entity_relations'] ?? []));

        return array_diff($targetEntities, $sourceEntities) !== [] || array_diff($targetRelations, $sourceRelations) !== [];
    }

    /** @return array<string,int> */
    private function emptyComponents(): array
    {
        return ['module_set_bp' => 0, 'module_order_bp' => 0, 'entity_relation_bp' => 0, 'internal_link_pattern_bp' => 0];
    }

    /** @param list<string> $left @param list<string> $right */
    private function jaccard(array $left, array $right, int $weight): int
    {
        $left = array_values(array_unique($left));
        $right = array_values(array_unique($right));
        if ($left === [] && $right === []) {
            return $weight;
        }
        $union = array_values(array_unique(array_merge($left, $right)));

        return intdiv(count(array_intersect($left, $right)) * $weight, count($union));
    }

    /** @param list<string> $left @param list<string> $right */
    private function lcsScore(array $left, array $right, int $weight): int
    {
        $denominator = max(count($left), count($right));
        if ($denominator === 0) {
            return $weight;
        }
        $rows = array_fill(0, count($right) + 1, 0);
        foreach ($left as $leftValue) {
            $previous = 0;
            foreach ($right as $index => $rightValue) {
                $saved = $rows[$index + 1];
                $rows[$index + 1] = $leftValue === $rightValue
                    ? $previous + 1
                    : max($rows[$index], $rows[$index + 1]);
                $previous = $saved;
            }
        }

        return intdiv($rows[count($right)] * $weight, $denominator);
    }

    /** @return list<string> */
    private function holds(string $freshness, bool $missing, bool $conflict, bool $multiSource, string $necessity): array
    {
        $holds = [];
        if ($conflict) {
            $holds[] = 'SOURCE_CONFLICT_HOLD';
        }
        if ($freshness !== 'fresh') {
            $holds[] = match ($freshness) {
                'stale' => 'SOURCE_STALE_HOLD',
                'expired' => 'SOURCE_EXPIRED_HOLD',
                'conflict' => 'SOURCE_CONFLICT_HOLD',
                default => 'SOURCE_FRESHNESS_UNKNOWN',
            };
        }
        if ($missing) {
            $holds[] = 'REQUIRED_EVIDENCE_MISSING';
        }
        if (! $multiSource) {
            $holds[] = 'MULTI_SOURCE_EVIDENCE_HOLD';
        }
        if ($necessity === 'conditional') {
            $holds[] = 'PAGE_NECESSITY_CONDITIONAL';
        }

        return $this->strings($holds);
    }

    /** @param list<array<string,mixed>> $projections @param array<string,mixed> $authority @param array<string,mixed> $measurement @return list<string> */
    private function evidenceRefs(array $projections, array $authority, array $measurement): array
    {
        $refs = array_column($projections, 'projection_hash');
        $refs[] = (string) ($authority['source_hash'] ?? '');
        $refs[] = (string) ($measurement['source_hash'] ?? '');
        $refs = array_values(array_filter($refs, fn (mixed $ref): bool => is_string($ref) && $this->hash($ref)));
        $refs = $this->strings($refs);

        while (count($refs) < 3) {
            $refs[] = $this->hasher->hash(['missing-evidence', count($refs)]);
        }

        return array_slice($refs, 0, 32);
    }

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function sortedRecords(array $records, string $key): array
    {
        usort($records, static fn (array $left, array $right): int => strcmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? '')));

        return $records;
    }

    /** @param list<mixed> $values @return list<string> */
    private function strings(array $values): array
    {
        $result = array_values(array_unique(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== '')));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param list<array<string,mixed>> $modules @return list<string> */
    private function moduleTypes(array $modules): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $module): string => is_array($module) ? (string) ($module['module_type'] ?? '') : '',
            $modules,
        ), static fn (string $type): bool => $type !== ''));
    }

    /** @param list<array<string,mixed>> $relations @return list<string> */
    private function relationKeys(array $relations): array
    {
        return $this->strings(array_map(fn (mixed $relation): string => is_array($relation) ? $this->relationKey($relation) : '', $relations));
    }

    /** @param array<string,mixed> $relation */
    private function relationKey(array $relation): string
    {
        $parts = [(string) ($relation['entity_id'] ?? ''), (string) ($relation['relation'] ?? ''), (string) ($relation['target_id'] ?? '')];

        return in_array('', $parts, true) ? '' : implode('|', $parts);
    }

    /** @param list<array<string,mixed>> $patterns @return list<string> */
    private function patternKeys(array $patterns): array
    {
        return $this->strings(array_map(static function (mixed $pattern): string {
            if (! is_array($pattern)) {
                return '';
            }

            return implode('|', [
                (string) ($pattern['from_family'] ?? ''),
                (string) ($pattern['relation'] ?? ''),
                (string) ($pattern['to_family'] ?? ''),
                (string) ($pattern['count_bucket'] ?? ''),
            ]);
        }, $patterns));
    }

    private function hash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
