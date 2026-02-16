<?php

namespace App\Services\Report\Composer;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\ContentStore;
use App\Services\Report\HighlightBuilder;
use App\Services\Report\ReportAccess;
use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerComposeEntryTrait
{
    private function composeInternal(Attempt $attempt, array $ctx = [], ?Result $result = null): array
    {
        $attemptId = (string) $attempt->id;
        $orgId = $this->resolveServerOrgId($ctx);

        $attempt = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();

        if (!$attempt) {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
                'status' => 404,
            ];
        }

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $orgId)
            ->first();

        if (!$result) {
            return [
                'ok' => false,
                'error' => 'RESULT_NOT_FOUND',
                'message' => 'result not found.',
                'status' => 404,
            ];
        }

        $profileVersion = $result->profile_version
            ?? ($ctx['defaultProfileVersion'] ?? 'mbti32-v2.5');

        $packId = (string) ($attempt->pack_id ?? $result->pack_id ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $result->dir_version ?? '');
        $contentPackageVersion = (string) ($attempt->content_package_version ?? $result->content_package_version ?? '');
        $scaleCode = (string) ($attempt->scale_code ?? $result->scale_code ?? '');
        $region = (string) ($attempt->region ?? '');
        $locale = (string) ($attempt->locale ?? '');

        if ($packId === '' || $dirVersion === '') {
            return [
                'ok' => false,
                'error' => 'REPORT_CONTEXT_MISSING',
                'message' => 'attempt pack context missing.',
                'status' => 500,
            ];
        }

        if ($packId !== '' && $dirVersion !== '') {
            $found = $this->packsIndex->find($packId, $dirVersion);
            if ($found['ok'] ?? false) {
                $item = $found['item'] ?? [];
                if ($contentPackageVersion === '') {
                    $contentPackageVersion = (string) ($item['content_package_version'] ?? '');
                }
                if ($scaleCode === '') {
                    $scaleCode = (string) ($item['scale_code'] ?? '');
                }
                if ($region === '') {
                    $region = (string) ($item['region'] ?? '');
                }
                if ($locale === '') {
                    $locale = (string) ($item['locale'] ?? '');
                }
            }
        }

        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($ctx['content_package_version'] ?? '');
        }
        if ($contentPackageVersion === ''
            && isset($ctx['currentContentPackageVersion'])
            && is_callable($ctx['currentContentPackageVersion'])
        ) {
            $contentPackageVersion = (string) ($ctx['currentContentPackageVersion'])();
        }
        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($this->normalizeRequestedVersion($dirVersion) ?? '');
        }
        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($this->normalizeRequestedVersion($packId) ?? '');
        }

        if ($scaleCode === '' && $packId !== '') {
            $scaleCode = (string) strtok($packId, '.');
        }
        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }
        if ($region === '') {
            $region = (string) config('content_packs.default_region', 'CN_MAINLAND');
        }
        if ($locale === '') {
            $locale = (string) config('content_packs.default_locale', 'zh-CN');
        }

        $variant = ReportAccess::normalizeVariant(
            is_string($ctx['variant'] ?? null) ? (string) $ctx['variant'] : null
        );

        $reportAccessLevel = ReportAccess::normalizeReportAccessLevel(
            is_string($ctx['report_access_level'] ?? null)
                ? (string) $ctx['report_access_level']
                : ($variant === ReportAccess::VARIANT_FREE
                    ? ReportAccess::REPORT_ACCESS_FREE
                    : ReportAccess::REPORT_ACCESS_FULL)
        );

        $modulesAllowed = ReportAccess::normalizeModules(
            is_array($ctx['modules_allowed'] ?? null) ? (array) $ctx['modules_allowed'] : []
        );

        $modulesPreview = ReportAccess::normalizeModules(
            is_array($ctx['modules_preview'] ?? null) ? (array) $ctx['modules_preview'] : []
        );

        $rp = $this->resolver->resolve($scaleCode, $region, $locale, $contentPackageVersion, $dirVersion);
        $chain = $this->toContentPackChain($rp);

        $pack = $chain[0];
        $contentPackageVersion = (string) $pack->version();
        $contentPackId = (string) $pack->packId();

        $contentPackageDir = $dirVersion !== '' ? $dirVersion : basename((string) ($rp->baseDir ?? ''));
        if ($dirVersion === '') {
            $dirVersion = $contentPackageDir;
        }

        $typeCode = (string) ($result->type_code ?? '');
        $store = new ContentStore($chain, $ctx, $contentPackageDir);

        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $warnings = [];

        $scoresPct = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        $axisStates = is_array($result->axis_states ?? null) ? $result->axis_states : [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresPct)) {
                $warnings[] = "scores_pct_missing:$d";
                $scoresPct[$d] = 50;
            }
            if (!array_key_exists($d, $axisStates)) {
                $warnings[] = "axis_states_missing:$d";
                $axisStates[$d] = 'moderate';
            }
        }

        $scores = $this->buildScoresValueObject($scoresPct, $dims);

        $axisStates = [];
        foreach ($dims as $d) {
            $axisStates[$d] = (string) ($scores[$d]['state'] ?? 'moderate');
        }

        $profile = $this->loadTypeProfileFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);
        $identityCard = $this->loadIdentityCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);

        $roleCard = $this->loadRoleCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);
        $strategyCard = $this->loadStrategyCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);

        $tags = $this->tagBuilder->build($scores, [
            'type_code' => $typeCode,
            'role_card' => $roleCard,
            'strategy_card' => $strategyCard,
        ]);

        $wantExplainPayload = app()->environment('local') && (
            (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN_PAYLOAD', false) || (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN', false)
        );

        $explainPayload = null;
        $explainCollector = null;

        if ($wantExplainPayload) {
            $empty = function (string $target, string $ctxName) use ($tags) {
                $ctxTags = is_array($tags) ? array_values(array_filter($tags, fn ($x) => is_string($x) && $x !== '')) : [];
                return [
                    'target' => $target,
                    'ctx' => $ctxName,
                    'context_tags' => $ctxTags,
                    'selected' => [],
                    'rejected' => [],
                ];
            };

            $explainPayload = [
                'reads' => $empty('reads', 'reads'),
                'highlights' => $empty('highlights', 'highlights'),
                'cards' => [
                    'traits' => $empty('cards', 'cards:traits'),
                    'career' => $empty('cards', 'cards:career'),
                    'growth' => $empty('cards', 'cards:growth'),
                    'relationships' => $empty('cards', 'cards:relationships'),
                ],
                'overrides' => $empty('overrides', 'overrides'),
                'assembler' => [
                    'cards' => [
                        'traits' => [],
                        'career' => [],
                        'growth' => [],
                        'relationships' => [],
                    ],
                ],
            ];

            $explainCollector = function (string $ctxName, array $payload) use (&$explainPayload) {
                if (!is_array($explainPayload)) {
                    return;
                }
                if ($ctxName === 'reads' || str_starts_with($ctxName, 'reads:')) {
                    $explainPayload['reads'] = $payload;
                    return;
                }
                if ($ctxName === 'highlights' || str_starts_with($ctxName, 'highlights:')) {
                    $explainPayload['highlights'] = $payload;
                    return;
                }
                if (str_starts_with($ctxName, 'cards:')) {
                    $sec = explode(':', $ctxName, 2)[1] ?? '';
                    if ($sec !== '' && isset($explainPayload['cards'][$sec])) {
                        $explainPayload['cards'][$sec] = $payload;
                    }
                }
            };
        }

        if ($wantExplainPayload) {
            $GLOBALS['__re_explain_collector__'] = $explainCollector;
        } else {
            unset($GLOBALS['__re_explain_collector__']);
        }

        $reportForHL = [
            'profile' => ['type_code' => $typeCode],
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'tags' => $tags,
        ];

        $hlTemplatesDoc = $store->loadHighlights();
        $builder = new HighlightBuilder();
        $selectRules = $store->loadSelectRules();
        $hlBuild = $builder->buildFromTemplatesDoc($reportForHL, $hlTemplatesDoc, 3, 10, $selectRules);

        $baseHighlights = [];
        $hlMetaBase = [];

        if (is_array($hlBuild) && array_key_exists('items', $hlBuild)) {
            $baseHighlights = is_array($hlBuild['items'] ?? null) ? $hlBuild['items'] : [];
            $hlMetaBase = is_array($hlBuild['_meta'] ?? null) ? $hlBuild['_meta'] : [];
        } else {
            $baseHighlights = is_array($hlBuild) ? $hlBuild : [];
            $hlMetaBase = [
                'compat' => true,
                'note' => 'HighlightBuilder returned legacy list; consider returning items+_meta.',
            ];
        }

        Log::info('[HL] generated', [
            'stage' => 'base_from_templates_doc',
            'schema' => $hlTemplatesDoc['schema'] ?? null,
            'count' => is_array($baseHighlights) ? count($baseHighlights) : -1,
            'sample' => array_slice($baseHighlights ?? [], 0, 2),
        ]);

        Log::info('[HL] base_highlights', [
            'pack_id' => $contentPackId,
            'version' => $contentPackageVersion,
            'count' => count($baseHighlights),
            'ids' => array_slice(array_map(fn ($x) => $x['id'] ?? null, $baseHighlights), 0, 10),
        ]);

        $borderlineNote = $this->loadBorderlineNoteFromPackChain(
            $chain,
            $scoresPct,
            $ctx,
            $contentPackageDir
        );

        if (!is_array($borderlineNote['items'] ?? null)) {
            $borderlineNote['items'] = [];
        }

        $identityLayer = $this->identityLayerBuilder->build(
            $contentPackageDir,
            $typeCode,
            $scoresPct,
            $borderlineNote
        );

        $overridesDoc = $store->loadOverrides();
        $overridesOrderBuckets = $store->overridesOrderBuckets();
        $this->reportOverridesApplier->resetExplain();

        $unifiedOverridesDoc = $this->buildUnifiedOverridesDocForApplierFromPackChain($chain, $overridesDoc);
        $ovrCaptureExplain = app()->environment('local') && (
            (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN_PAYLOAD', false) || (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN', false)
        );

        $ovrCtx = [
            'report_overrides_doc' => $unifiedOverridesDoc,
            'overrides_debug' => (bool) \App\Support\RuntimeConfig::value('FAP_OVR_DEBUG', false),
            'tags' => $tags,
            'capture_explain' => (bool) $ovrCaptureExplain,
            'explain_collector' => $ovrCaptureExplain ? ($GLOBALS['__re_explain_collector__'] ?? null) : null,
        ];

        $flow = $this->composeBuildSectionsAndRules([
            'chain' => $chain,
            'store' => $store,
            'attemptId' => $attemptId,
            'scores' => $scores,
            'wantExplainPayload' => $wantExplainPayload,
            'explainCollector' => $explainCollector,
            'contentPackageDir' => $contentPackageDir,
            'typeCode' => $typeCode,
            'tags' => $tags,
            'scaleCode' => $scaleCode,
            'region' => $region,
            'locale' => $locale,
            'axisStates' => $axisStates,
            'baseHighlights' => $baseHighlights,
            'reportForHL' => $reportForHL,
            'builder' => $builder,
            'overridesDoc' => $overridesDoc,
            'overridesOrderBuckets' => $overridesOrderBuckets,
            'ctx' => $ctx,
            'explainPayload' => $explainPayload,
            'ovrCtx' => $ovrCtx,
            'variant' => $variant,
            'reportAccessLevel' => $reportAccessLevel,
            'modulesAllowed' => $modulesAllowed,
            'modulesPreview' => $modulesPreview,
        ]);

        $reportPayload = $this->composeBuildReportPayload([
            'contentPackageDir' => $contentPackageDir,
            'contentPackId' => $contentPackId,
            'profileVersion' => $profileVersion,
            'contentPackageVersion' => $contentPackageVersion,
            'scores' => $scores,
            'scoresPct' => $scoresPct,
            'axisStates' => $axisStates,
            'tags' => $tags,
            'profile' => $profile,
            'typeCode' => $typeCode,
            'identityCard' => $identityCard,
            'highlights' => $flow['highlights'] ?? [],
            'borderlineNote' => $borderlineNote,
            'roleCard' => $roleCard,
            'strategyCard' => $strategyCard,
            'identityLayer' => $identityLayer,
            'sections' => $flow['sections'] ?? [],
            'warnings' => $warnings,
            'includeRecommendedReads' => (bool) ($flow['includeRecommendedReads'] ?? false),
            'recommendedReads' => is_array($flow['recommendedReads'] ?? null) ? $flow['recommendedReads'] : [],
            'hlMetaBase' => $hlMetaBase,
            'hlMetaFinal' => is_array($flow['hlMetaFinal'] ?? null) ? $flow['hlMetaFinal'] : [],
            'wantExplainPayload' => $wantExplainPayload,
            'explainPayload' => $flow['explainPayload'] ?? $explainPayload,
            'ovrExplain' => $flow['ovrExplain'] ?? null,
            'assemblerMetaSections' => is_array($flow['assemblerMetaSections'] ?? null) ? $flow['assemblerMetaSections'] : [],
            'assemblerGlobalMeta' => $flow['assemblerGlobalMeta'] ?? null,
            'variant' => $variant,
            'reportAccessLevel' => $reportAccessLevel,
            'modulesAllowed' => $modulesAllowed,
            'modulesPreview' => $modulesPreview,
        ]);

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'type_code' => $typeCode,
            'report' => $reportPayload,
        ];
    }
}
