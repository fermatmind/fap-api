<?php

namespace App\Services\Report\Composer;

use App\Services\Content\ContentStore;
use App\Services\Report\HighlightBuilder;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportContentNormalizer;
use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerComposeBuildTrait
{
    private function composeBuildSectionsAndRules(array $input): array
    {
        $chain = $input['chain'];
        /** @var ContentStore $store */
        $store = $input['store'];
        $attemptId = (string) ($input['attemptId'] ?? '');
        $scores = is_array($input['scores'] ?? null) ? $input['scores'] : [];
        $wantExplainPayload = (bool) ($input['wantExplainPayload'] ?? false);
        $explainCollector = $input['explainCollector'] ?? null;
        $contentPackageDir = (string) ($input['contentPackageDir'] ?? '');
        $typeCode = (string) ($input['typeCode'] ?? '');
        $tags = is_array($input['tags'] ?? null) ? $input['tags'] : [];
        $scaleCode = (string) ($input['scaleCode'] ?? '');
        $region = (string) ($input['region'] ?? '');
        $locale = (string) ($input['locale'] ?? '');
        $axisStates = is_array($input['axisStates'] ?? null) ? $input['axisStates'] : [];
        $baseHighlights = is_array($input['baseHighlights'] ?? null) ? $input['baseHighlights'] : [];
        $reportForHL = is_array($input['reportForHL'] ?? null) ? $input['reportForHL'] : [];
        /** @var HighlightBuilder $builder */
        $builder = $input['builder'];
        $overridesDoc = is_array($input['overridesDoc'] ?? null) ? $input['overridesDoc'] : null;
        $overridesOrderBuckets = is_array($input['overridesOrderBuckets'] ?? null) ? $input['overridesOrderBuckets'] : [];
        $explainPayload = $input['explainPayload'] ?? null;
        $ovrCtx = is_array($input['ovrCtx'] ?? null) ? $input['ovrCtx'] : [];
        $variant = ReportAccess::normalizeVariant(
            is_string($input['variant'] ?? null) ? (string) $input['variant'] : null
        );
        $modulesAllowed = ReportAccess::normalizeModules(
            is_array($input['modulesAllowed'] ?? null) ? (array) $input['modulesAllowed'] : []
        );
        $modulesPreview = ReportAccess::normalizeModules(
            is_array($input['modulesPreview'] ?? null) ? (array) $input['modulesPreview'] : []
        );

        $sectionPoliciesDoc = $this->loadSectionPoliciesDocFromPackChain($chain);

        $wantCards = function (string $secKey) use ($sectionPoliciesDoc): int {
            $defaults = [
                'min_cards' => 4,
                'target' => 5,
                'max' => 7,
                'allow_fallback' => true,
            ];

            $p = $this->pickSectionPolicy($sectionPoliciesDoc, $secKey, $defaults);

            $min = (int) ($p['min_cards'] ?? $p['min'] ?? $defaults['min_cards']);
            $target = (int) ($p['target'] ?? $p['target_cards'] ?? $defaults['target']);
            $max = (int) ($p['max'] ?? $p['max_cards'] ?? $defaults['max']);

            if ($min < 0) {
                $min = 0;
            }
            if ($target < 0) {
                $target = 0;
            }
            if ($max <= 0) {
                $max = $defaults['max'];
            }
            if ($max < $min) {
                $max = $min;
            }

            return max($min, min($target, $max));
        };

        $axisInfoForCards = $scores;
        $axisInfoForCards['attempt_id'] = $attemptId;
        $axisInfoForCards['capture_explain'] = (bool) $wantExplainPayload;
        $axisInfoForCards['explain_collector'] = $explainCollector;

        $sections = [];

        foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {
            $baseCards = $this->cardGen->generateFromStore(
                $sectionKey,
                $store,
                $tags,
                $axisInfoForCards,
                $contentPackageDir,
                $wantCards($sectionKey),
                $variant,
                $modulesAllowed,
                $modulesPreview
            );

            Log::info('[CARDS] selected (base)', [
                'section' => $sectionKey,
                'count' => is_array($baseCards) ? count($baseCards) : -1,
                'ids' => is_array($baseCards)
                    ? array_slice(array_map(fn ($x) => $x['id'] ?? null, $baseCards), 0, 12)
                    : null,
            ]);

            $finalCards = $this->reportOverridesApplier->applyCards(
                $contentPackageDir,
                $typeCode,
                (string) $sectionKey,
                is_array($baseCards) ? $baseCards : [],
                $ovrCtx
            );

            $sectionModuleCode = ReportAccess::defaultModuleCodeForSection((string) $sectionKey);
            $sectionLocked = $sectionModuleCode !== ReportAccess::MODULE_CORE_FREE
                && !in_array($sectionModuleCode, $modulesAllowed, true);

            $sections[$sectionKey] = [
                'cards' => $finalCards,
                'module_code' => $sectionModuleCode,
                'locked' => $sectionLocked,
            ];
        }

        Log::info('[CARDS] generated_by_policy', [
            'want' => [
                'traits' => $wantCards('traits'),
                'career' => $wantCards('career'),
                'growth' => $wantCards('growth'),
                'relationships' => $wantCards('relationships'),
            ],
            'got' => [
                'traits' => is_array($sections['traits']['cards'] ?? null) ? count($sections['traits']['cards']) : null,
                'career' => is_array($sections['career']['cards'] ?? null) ? count($sections['career']['cards']) : null,
                'growth' => is_array($sections['growth']['cards'] ?? null) ? count($sections['growth']['cards']) : null,
                'relationships' => is_array($sections['relationships']['cards'] ?? null) ? count($sections['relationships']['cards']) : null,
            ],
        ]);

        $recommendedReadsEnabled = (bool) \App\Support\RuntimeConfig::value('CONTENT_GRAPH_ENABLED', false);
        $recommendedReads = $recommendedReadsEnabled
            ? $this->buildRecommendedReadsFromStaticDoc(
                $store->loadReads(),
                $typeCode,
                $tags,
                $scores
            )
            : [];
        $includeRecommendedReads = true;

        [$highlights, $sections, $recommendedReads, $ovrExplain] = $this->applyOverridesUnified(
            $chain,
            $contentPackageDir,
            $typeCode,
            $tags,
            $baseHighlights,
            $sections,
            $recommendedReads,
            $overridesDoc,
            $overridesOrderBuckets,
            false
        );

        $rulesDoc = $this->loadReportRulesDocFromPackChain($chain);

        $reCtxBase = [
            'type_code' => $typeCode,
            'content_package_dir' => $contentPackageDir,
            'tags' => $tags,
            'capture_explain' => (bool) $wantExplainPayload,
            'explain_collector' => $explainCollector,
        ];

        $highlights = app(\App\Services\RuleEngine\ReportRuleEngine::class)
            ->apply('highlights', $highlights, $rulesDoc, $reCtxBase, 'highlights');

        foreach ($sections as $sectionKey => &$sec) {
            $cards = is_array($sec['cards'] ?? null) ? $sec['cards'] : [];
            $sec['cards'] = app(\App\Services\RuleEngine\ReportRuleEngine::class)
                ->apply('cards', $cards, $rulesDoc, array_merge($reCtxBase, ['section_key' => (string) $sectionKey]), "cards:{$sectionKey}");
        }
        unset($sec);

        try {
            $hlReportForFinalize = $reportForHL;
            $hlReportForFinalize['tags'] = $tags;
            $hlReportForFinalize['capture_explain'] = (bool) $wantExplainPayload;
            $hlReportForFinalize['explain_collector'] = $explainCollector;

            $hlFinal = $builder->finalize(
                $highlights,
                $hlReportForFinalize,
                $store,
                3,
                4
            );

            if (is_array($hlFinal) && array_key_exists('items', $hlFinal)) {
                $highlights = is_array($hlFinal['items'] ?? null) ? $hlFinal['items'] : $highlights;
                $hlMetaFinal = is_array($hlFinal['_meta'] ?? null) ? $hlFinal['_meta'] : [];
            } else {
                $highlights = is_array($hlFinal) ? $hlFinal : $highlights;
                $hlMetaFinal = [
                    'compat' => true,
                    'note' => 'HighlightBuilder::finalize returned legacy list; consider returning items+_meta.',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[HL] finalize_failed', ['error' => $e->getMessage()]);
            $hlMetaFinal = [
                'error' => $e->getMessage(),
            ];
        }

        $force = trim((string) \App\Support\RuntimeConfig::value('FAP_DEBUG_FORCE_SHORT_SECTION', ''));

        if ($force !== '') {
            $secName = $force;
            $keepN = 1;

            if (str_contains($force, ':')) {
                [$secName, $n] = explode(':', $force, 2);
                $secName = trim((string) $secName);
                $n = trim((string) $n);
                if (is_numeric($n)) {
                    $keepN = max(0, (int) $n);
                }
            } else {
                $secName = trim($secName);
            }

            if ($secName !== '' && isset($sections[$secName]) && is_array($sections[$secName])) {
                $cards0 = is_array($sections[$secName]['cards'] ?? null) ? $sections[$secName]['cards'] : [];
                $before = count($cards0);
                $sections[$secName]['cards'] = array_slice($cards0, 0, $keepN);
                $after = count($sections[$secName]['cards']);

                Log::info('[DBG] force_short_section', [
                    'section' => $secName,
                    'keep' => $keepN,
                    'before' => $before,
                    'after' => $after,
                ]);
            }
        }

        $assemblerGlobalMeta = null;

        $tmpReport = [
            'sections' => $sections,
        ];

        $tmpReport = app(\App\Services\Report\SectionAssembler::class)
            ->apply($tmpReport, $store, [
                'capture_explain' => (bool) $wantExplainPayload,
            ]);

        $sections = (is_array($tmpReport['sections'] ?? null)) ? $tmpReport['sections'] : $sections;

        $rawMetaSections = (is_array($tmpReport['_meta']['sections'] ?? null))
            ? $tmpReport['_meta']['sections']
            : [];

        Log::info('[ASM] after_apply', [
            'tmp_meta_keys' => is_array($tmpReport['_meta'] ?? null) ? array_keys($tmpReport['_meta']) : null,
            'sections_meta_type' => gettype($tmpReport['_meta']['sections'] ?? null),
            'sections_meta_count' => is_array($tmpReport['_meta']['sections'] ?? null) ? count($tmpReport['_meta']['sections']) : null,
        ]);

        $assemblerMetaSections = $this->normalizeAssemblerMetaSections($rawMetaSections);

        if (empty($assemblerMetaSections)) {
            $assemblerMetaSections = $this->buildFallbackAssemblerMetaSections(
                $sections,
                $chain,
                $store,
                $contentPackageDir
            );

            $assemblerGlobalMeta = [
                'ok' => false,
                'reason' => 'assembler_did_not_emit_meta',
                'meta_fallback_used' => true,
            ];

            Log::error('[ASM] meta_missing_diagnostic_fallback_used', [
                'keys' => array_keys($assemblerMetaSections),
            ]);
        } else {
            $assemblerGlobalMeta = [
                'ok' => true,
                'meta_fallback_used' => false,
            ];
        }

        if ($wantExplainPayload && is_array($explainPayload)) {
            foreach ($assemblerMetaSections as $secKey => $node) {
                $secKey = (string) $secKey;
                $explainPayload['assembler']['cards'][$secKey] = is_array($node)
                    ? ($node['assembler'] ?? $node)
                    : [];
            }
        }

        return [
            'highlights' => $highlights,
            'sections' => $sections,
            'recommendedReads' => $recommendedReads,
            'includeRecommendedReads' => $includeRecommendedReads,
            'ovrExplain' => $ovrExplain ?? null,
            'hlMetaFinal' => $hlMetaFinal ?? [],
            'assemblerMetaSections' => $assemblerMetaSections,
            'assemblerGlobalMeta' => $assemblerGlobalMeta,
            'explainPayload' => $explainPayload,
        ];
    }

    private function buildRecommendedReadsFromStaticDoc(
        array $doc,
        string $typeCode,
        array $tags,
        array $scores
    ): array {
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];

        $fillOrder = is_array($rules['fill_order'] ?? null)
            ? array_values(array_filter($rules['fill_order'], fn ($x) => is_string($x) && trim($x) !== ''))
            : ['by_type', 'by_role', 'by_strategy', 'by_top_axis', 'fallback'];

        $bucketQuota = is_array($rules['bucket_quota'] ?? null) ? $rules['bucket_quota'] : [];
        $maxItems = max(0, (int) ($rules['max_items'] ?? 8));
        $minItems = max(0, (int) ($rules['min_items'] ?? 0));
        $defaults = is_array($rules['defaults'] ?? null) ? $rules['defaults'] : [];
        $sortMode = strtolower(trim((string) ($rules['sort'] ?? '')));

        $byType = is_array($items['by_type'] ?? null) ? $items['by_type'] : [];
        $byRole = is_array($items['by_role'] ?? null) ? $items['by_role'] : [];
        $byStrategy = is_array($items['by_strategy'] ?? null) ? $items['by_strategy'] : [];
        $byTopAxis = is_array($items['by_top_axis'] ?? null) ? $items['by_top_axis'] : [];
        $fallback = is_array($items['fallback'] ?? null) ? $items['fallback'] : [];

        $roleCode = $this->extractRecommendedReadsTagValue($tags, 'role:');
        $strategyCode = $this->extractRecommendedReadsTagValue($tags, 'strategy:');
        $topAxisKeyCandidates = $this->buildRecommendedReadsTopAxisKeyCandidates($rules, $scores);

        $bucketLists = [
            'by_type' => is_array($byType[$typeCode] ?? null) ? $byType[$typeCode] : [],
            'by_role' => is_array($byRole[$roleCode] ?? null) ? $byRole[$roleCode] : [],
            'by_strategy' => is_array($byStrategy[$strategyCode] ?? null) ? $byStrategy[$strategyCode] : [],
            'by_top_axis' => $this->pickRecommendedReadsTopAxisBucket($byTopAxis, $topAxisKeyCandidates),
            'fallback' => is_array($fallback) ? $fallback : [],
        ];

        foreach ($bucketLists as $bucketName => &$list) {
            $list = $this->normalizeRecommendedReadsBucket($list, $defaults, $sortMode);
        }
        unset($list);

        $out = [];
        $seen = [
            'id' => [],
            'canonical_id' => [],
            'canonical_url' => [],
            'url' => [],
        ];

        foreach ($fillOrder as $bucketName) {
            if ($maxItems > 0 && count($out) >= $maxItems) {
                break;
            }

            $list = $bucketLists[$bucketName] ?? [];
            if (! is_array($list) || $list === []) {
                continue;
            }

            $remaining = $maxItems > 0 ? max(0, $maxItems - count($out)) : count($list);
            $cap = $this->resolveRecommendedReadsBucketCap($bucketQuota[$bucketName] ?? $remaining, $remaining);
            if ($cap <= 0) {
                continue;
            }

            $taken = 0;
            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ($maxItems > 0 && count($out) >= $maxItems) {
                    break;
                }
                if ($taken >= $cap) {
                    break;
                }
                if ($this->isRecommendedReadDuplicate($item, $seen)) {
                    continue;
                }

                $this->markRecommendedReadSeen($item, $seen);
                $out[] = $item;
                $taken++;
            }
        }

        $targetCount = $maxItems > 0 ? min($maxItems, $minItems) : $minItems;
        if ($targetCount > 0 && count($out) < $targetCount) {
            foreach ($bucketLists['fallback'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (count($out) >= $targetCount) {
                    break;
                }
                if ($maxItems > 0 && count($out) >= $maxItems) {
                    break;
                }
                if ($this->isRecommendedReadDuplicate($item, $seen)) {
                    continue;
                }

                $this->markRecommendedReadSeen($item, $seen);
                $out[] = $item;
            }
        }

        if ($sortMode === 'priority_desc') {
            usort($out, fn (array $a, array $b) => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));
        }

        return array_values($out);
    }

    private function extractRecommendedReadsTagValue(array $tags, string $prefix): string
    {
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = trim($tag);
            if ($tag === '' || ! str_starts_with($tag, $prefix)) {
                continue;
            }

            return strtoupper(substr($tag, strlen($prefix)));
        }

        return '';
    }

    private function buildRecommendedReadsTopAxisKeyCandidates(array $rules, array $scores): array
    {
        $best = null;

        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $dim) {
            $node = is_array($scores[$dim] ?? null) ? $scores[$dim] : [];
            $delta = abs((int) ($node['delta'] ?? 0));
            $side = strtoupper(trim((string) ($node['side'] ?? '')));
            if ($side === '') {
                continue;
            }

            if ($best === null || $delta > $best['delta']) {
                $best = [
                    'dim' => $dim,
                    'delta' => $delta,
                    'side' => $side,
                ];
            }
        }

        if (! is_array($best)) {
            return [];
        }

        $plain = $best['dim'] . ':' . $best['side'];
        $prefixed = 'axis:' . $plain;
        $format = trim((string) ($rules['axis_key_format'] ?? ''));
        $formatted = $format !== ''
            ? str_replace(['${DIM}', '${SIDE}'], [$best['dim'], $best['side']], $format)
            : '';

        return array_values(array_unique(array_filter([$formatted, $prefixed, $plain], fn ($x) => is_string($x) && trim($x) !== '')));
    }

    private function pickRecommendedReadsTopAxisBucket(array $byTopAxis, array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($byTopAxis[$candidate] ?? null)) {
                return $byTopAxis[$candidate];
            }
        }

        return [];
    }

    private function normalizeRecommendedReadsBucket(array $list, array $defaults, string $sortMode): array
    {
        $out = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = ReportContentNormalizer::read(array_merge($defaults, $item));
            if ($normalized['id'] === '' || $normalized['title'] === '') {
                continue;
            }

            $out[] = $normalized;
        }

        if ($sortMode === 'priority_desc') {
            usort($out, fn (array $a, array $b) => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));
        }

        return $out;
    }

    private function resolveRecommendedReadsBucketCap(mixed $rawCap, int $remaining): int
    {
        if (is_string($rawCap)) {
            $normalized = strtolower(trim($rawCap));
            if (in_array($normalized, ['remaining', '*', 'all'], true)) {
                return $remaining;
            }
            if (is_numeric($rawCap)) {
                return max(0, (int) $rawCap);
            }

            return 0;
        }

        if (is_int($rawCap) || is_float($rawCap)) {
            return max(0, (int) $rawCap);
        }

        return $remaining;
    }

    private function isRecommendedReadDuplicate(array $item, array $seen): bool
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '' && isset($seen['id'][$id])) {
            return true;
        }

        foreach (['canonical_id', 'canonical_url', 'url'] as $key) {
            $value = $this->normalizeRecommendedReadDedupeValue($item[$key] ?? null, $key);
            if ($value === '') {
                continue;
            }
            if (isset($seen[$key][$value])) {
                return true;
            }
        }

        return false;
    }

    private function markRecommendedReadSeen(array $item, array &$seen): void
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            $seen['id'][$id] = true;
        }

        foreach (['canonical_id', 'canonical_url', 'url'] as $key) {
            $value = $this->normalizeRecommendedReadDedupeValue($item[$key] ?? null, $key);
            if ($value !== '') {
                $seen[$key][$value] = true;
            }
        }
    }

    private function normalizeRecommendedReadDedupeValue(mixed $value, string $kind): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        if (! in_array($kind, ['canonical_url', 'url'], true)) {
            return $normalized;
        }

        $parts = parse_url($normalized);
        if (! is_array($parts)) {
            return $normalized;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($path === '') {
            return $normalized;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $qs = [];
        if ($query !== '') {
            parse_str($query, $qs);
        }

        $filtered = [];
        if (array_key_exists('id', $qs) && $qs['id'] !== '' && $qs['id'] !== null) {
            $filtered['id'] = $qs['id'];
        }

        ksort($filtered);
        $queryString = http_build_query($filtered);
        $key = $queryString !== '' ? ($path . '?' . $queryString) : $path;

        return $host !== '' ? ($host . $key) : $key;
    }
}
