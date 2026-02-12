<?php

namespace App\Services\Report\Composer;

use App\Services\Content\ContentStore;
use App\Services\Report\HighlightBuilder;
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
                $wantCards($sectionKey)
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

            $sections[$sectionKey] = [
                'cards' => $finalCards,
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

        $contentGraphEnabled = (bool) env('CONTENT_GRAPH_ENABLED', false);
        $includeRecommendedReads = false;
        $recommendedReads = [];

        if ($contentGraphEnabled) {
            [$recommendedReads, $includeRecommendedReads] = $this->buildRecommendedReadsFromContentGraph(
                $chain,
                $scaleCode,
                $region,
                $locale,
                $typeCode,
                $scores,
                $axisStates
            );
        }

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

        if (!$includeRecommendedReads) {
            $recommendedReads = [];
        }

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

        $force = trim((string) env('FAP_DEBUG_FORCE_SHORT_SECTION', ''));

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
}
