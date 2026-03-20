<?php

namespace App\Services\Report\Composer;

use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerComposeFinalizeTrait
{
    private function composeBuildReportPayload(array $input): array
    {
        $contentPackageDir = (string) ($input['contentPackageDir'] ?? '');
        $contentPackId = (string) ($input['contentPackId'] ?? '');
        $profileVersion = (string) ($input['profileVersion'] ?? '');
        $contentPackageVersion = (string) ($input['contentPackageVersion'] ?? '');
        $reportEngineVersion = trim((string) ($input['reportEngineVersion'] ?? ''));
        $locale = (string) ($input['locale'] ?? '');
        $scores = is_array($input['scores'] ?? null) ? $input['scores'] : [];
        $scoresPct = is_array($input['scoresPct'] ?? null) ? $input['scoresPct'] : [];
        $axisStates = is_array($input['axisStates'] ?? null) ? $input['axisStates'] : [];
        $tags = is_array($input['tags'] ?? null) ? $input['tags'] : [];
        $profile = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $typeCode = (string) ($input['typeCode'] ?? '');
        $identityCard = is_array($input['identityCard'] ?? null) ? $input['identityCard'] : null;
        $highlights = is_array($input['highlights'] ?? null) ? $input['highlights'] : [];
        $borderlineNote = is_array($input['borderlineNote'] ?? null) ? $input['borderlineNote'] : [];
        $roleCard = is_array($input['roleCard'] ?? null) ? $input['roleCard'] : [];
        $strategyCard = is_array($input['strategyCard'] ?? null) ? $input['strategyCard'] : [];
        $identityLayer = is_array($input['identityLayer'] ?? null) ? $input['identityLayer'] : null;
        $sections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        $warnings = is_array($input['warnings'] ?? null) ? $input['warnings'] : [];
        $includeRecommendedReads = (bool) ($input['includeRecommendedReads'] ?? false);
        $recommendedReads = is_array($input['recommendedReads'] ?? null) ? $input['recommendedReads'] : [];
        $hlMetaBase = is_array($input['hlMetaBase'] ?? null) ? $input['hlMetaBase'] : [];
        $hlMetaFinal = is_array($input['hlMetaFinal'] ?? null) ? $input['hlMetaFinal'] : [];
        $wantExplainPayload = (bool) ($input['wantExplainPayload'] ?? false);
        $explainPayload = is_array($input['explainPayload'] ?? null) ? $input['explainPayload'] : null;
        $ovrExplain = $input['ovrExplain'] ?? null;
        $assemblerMetaSections = is_array($input['assemblerMetaSections'] ?? null) ? $input['assemblerMetaSections'] : [];
        $assemblerGlobalMeta = is_array($input['assemblerGlobalMeta'] ?? null) ? $input['assemblerGlobalMeta'] : null;

        $legacyContentPackageDir = $contentPackageDir;
        $realContentPackageDir = $this->packIdToDir($contentPackId);
        if ($this->shouldPreferAuthoredMbtiIdentityLayer($contentPackId, $contentPackageDir)) {
            $identityLayer = $this->hydrateIdentityLayerFromPackIfNeeded(
                $identityLayer,
                $contentPackId,
                $contentPackageDir,
                $typeCode
            );
        }

        $reportPayload = [
            'versions' => [
                'engine' => $reportEngineVersion !== '' ? $reportEngineVersion : 'v1.2',
                'profile_version' => $profileVersion,
                'content_package_version' => $contentPackageVersion,
                'content_pack_id' => $contentPackId,
                'dir_version' => $legacyContentPackageDir,
                'content_package_dir' => $realContentPackageDir,
                'legacy_dir' => $legacyContentPackageDir,
            ],
            'scores' => $scores,
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'tags' => $tags,
            'profile' => [
                'type_code' => $profile['type_code'] ?? $typeCode,
                'type_name' => $profile['type_name'] ?? null,
                'tagline' => $profile['tagline'] ?? null,
                'rarity' => $profile['rarity'] ?? null,
                'keywords' => $profile['keywords'] ?? [],
                'short_summary' => $profile['short_summary'] ?? null,
            ],
            'identity_card' => $identityCard,
            'highlights' => $highlights,
            'borderline_note' => $borderlineNote,
            'layers' => [
                'role_card' => $roleCard,
                'strategy_card' => $strategyCard,
                'identity' => $identityLayer,
            ],
            'sections' => $sections,
            'warnings' => $warnings,
        ];

        if ($includeRecommendedReads) {
            $reportPayload['recommended_reads'] = $recommendedReads;
        }

        $normsPayload = $this->buildNormsPayload($contentPackId, $scoresPct);
        if (is_array($normsPayload)) {
            $reportPayload['norms'] = $normsPayload;
        }

        $reportPayload['_meta'] = is_array($reportPayload['_meta'] ?? null) ? $reportPayload['_meta'] : [];
        if (is_array($assemblerGlobalMeta)) {
            $reportPayload['_meta']['section_assembler'] = $assemblerGlobalMeta;
        }

        $reportPayload['_meta']['highlights'] = is_array($reportPayload['_meta']['highlights'] ?? null)
            ? $reportPayload['_meta']['highlights']
            : [];

        $reportPayload['_meta']['highlights']['base_meta'] = $hlMetaBase;
        $reportPayload['_meta']['highlights']['finalize_meta'] = $hlMetaFinal;

        if ($wantExplainPayload && is_array($explainPayload)) {
            $reportPayload['_meta']['highlights']['explain'] = $explainPayload['highlights'] ?? null;
        } else {
            $reportPayload['_meta']['highlights']['explain'] = $reportPayload['_meta']['highlights']['explain'] ?? null;
        }

        if (!empty($assemblerMetaSections) && is_array($assemblerMetaSections)) {
            $reportPayload['_meta']['sections'] = array_replace_recursive(
                is_array($reportPayload['_meta']['sections'] ?? null) ? $reportPayload['_meta']['sections'] : [],
                $assemblerMetaSections
            );

            $reportPayload['_meta']['section_assembler'] = is_array($reportPayload['_meta']['section_assembler'] ?? null)
                ? $reportPayload['_meta']['section_assembler']
                : [];

            $reportPayload['_meta']['section_assembler'] = array_merge(
                [
                    'ok' => true,
                    'meta_fallback_used' => false,
                ],
                $reportPayload['_meta']['section_assembler']
            );
        } else {
            $reportPayload['_meta']['sections'] = is_array($reportPayload['_meta']['sections'] ?? null)
                ? $reportPayload['_meta']['sections']
                : [];
        }

        Log::info('[ASM] final_meta_sections', [
            'type' => gettype($reportPayload['_meta']['sections'] ?? null),
            'keys' => is_array($reportPayload['_meta']['sections'] ?? null) ? array_keys($reportPayload['_meta']['sections']) : null,
            'traits_policy' => data_get($reportPayload, '_meta.sections.traits.assembler.policy'),
            'traits_counts' => data_get($reportPayload, '_meta.sections.traits.assembler.counts'),
        ]);

        Log::info('[ASM] meta_sections_merged', [
            'sections_keys' => array_keys($reportPayload['_meta']['sections'] ?? []),
            'traits_node_keys' => is_array($reportPayload['_meta']['sections']['traits'] ?? null)
                ? array_keys($reportPayload['_meta']['sections']['traits'])
                : null,
        ]);

        $reportPayload['_meta'] = $reportPayload['_meta'] ?? [];
        $reportPayload['_meta']['highlights'] = $reportPayload['_meta']['highlights'] ?? [];
        $reportPayload['_meta']['highlights']['base_meta'] = $hlMetaBase;
        $reportPayload['_meta']['highlights']['finalize_meta'] = $hlMetaFinal;

        if ($wantExplainPayload && is_array($explainPayload)) {
            $reportPayload['_meta']['highlights']['explain'] = $explainPayload['highlights'] ?? null;
        } else {
            $reportPayload['_meta']['highlights']['explain'] = $reportPayload['_meta']['highlights']['explain'] ?? null;
        }

        if ($wantExplainPayload) {
            $ovrRoot = null;
            if (is_array($ovrExplain ?? null)) {
                $ovrRoot = is_array($ovrExplain['_explain'] ?? null) ? $ovrExplain['_explain'] : $ovrExplain;
            }

            Log::info('[DBG] explain_types', [
                'ovrExplain_type' => gettype($ovrExplain ?? null),
                'ovrRoot_type' => gettype($ovrRoot),
                'ovrRoot_highlights_type' => (is_array($ovrRoot) && array_key_exists('highlights', $ovrRoot))
                    ? gettype($ovrRoot['highlights'])
                    : null,
                'ovrRoot_reads_type' => (is_array($ovrRoot) && array_key_exists('reads', $ovrRoot))
                    ? gettype($ovrRoot['reads'])
                    : null,
            ]);

            $toArr = function ($x): ?array {
                if (is_array($x)) {
                    return $x;
                }
                if (is_object($x)) {
                    $a = json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true);
                    return is_array($a) ? $a : null;
                }
                return null;
            };

            $ovrRootArr = $toArr($ovrRoot);

            if (is_array($explainPayload) && is_array($ovrRootArr)) {
                $explainPayload['overrides'] = $ovrRootArr;
            }

            $reportPayload['_explain'] = is_array($explainPayload) ? $explainPayload : [];
        }

        if ($contentPackId !== '' || $contentPackageDir !== '') {
            $personalization = $this->mbtiResultPersonalizationService->buildForReportPayload($reportPayload, [
                'type_code' => $typeCode,
                'pack_id' => $contentPackId,
                'dir_version' => $contentPackageDir,
                'locale' => $locale,
                'org_id' => (int) ($input['org_id'] ?? 0),
                'user_id' => $input['user_id'] ?? null,
                'anon_id' => $input['anon_id'] ?? null,
                'attempt_id' => $input['attempt_id'] ?? null,
                'region' => (string) ($input['region'] ?? config('regions.default_region', 'CN_MAINLAND')),
                'engine_version' => $reportEngineVersion !== '' ? $reportEngineVersion : (string) data_get($reportPayload, 'versions.engine', 'v1.2'),
                'has_unlock' => in_array(
                    strtolower(trim((string) ($input['reportAccessLevel'] ?? ''))),
                    ['paid', 'full'],
                    true
                ) || strtolower(trim((string) ($input['variant'] ?? ''))) === 'full',
            ]);

            if ($personalization !== []) {
                $personalization['locale'] = $locale !== '' ? $locale : 'zh-CN';
                $reportPayload['_meta']['personalization'] = $personalization;
            }
        }

        return $reportPayload;
    }

    private function hydrateIdentityLayerFromPackIfNeeded(
        ?array $identityLayer,
        string $contentPackId,
        string $contentPackageDir,
        string $typeCode
    ): ?array {
        $current = is_array($identityLayer) ? $identityLayer : null;
        $hasFallbackTag = in_array('fallback:true', (array) ($current['tags'] ?? []), true);
        $hasContent = $current !== null
            && trim((string) ($current['title'] ?? '')) !== ''
            && trim((string) ($current['one_liner'] ?? '')) !== ''
            && ! $hasFallbackTag;

        if ($hasContent) {
            return $current;
        }

        $authored = $this->loadIdentityLayerFromContentPack($contentPackId, $contentPackageDir, $typeCode);
        if ($authored === null) {
            return $current;
        }

        if ($current !== null && trim((string) ($current['micro_line'] ?? '')) !== '') {
            $authored['micro_line'] = (string) $current['micro_line'];
        } else {
            $authored['micro_line'] = (string) ($authored['micro_line'] ?? '');
        }

        $authored['type_code'] = $typeCode;

        return $authored;
    }

    private function loadIdentityLayerFromContentPack(string $contentPackId, string $contentPackageDir, string $typeCode): ?array
    {
        foreach ($this->identityLayerPathCandidates($contentPackId, $contentPackageDir) as $path) {
            if (! is_file($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (! is_array($json)) {
                continue;
            }

            $items = is_array($json['items'] ?? null) ? $json['items'] : $json;
            $layer = is_array($items[$typeCode] ?? null) ? $items[$typeCode] : null;
            if (! is_array($layer)) {
                continue;
            }

            $layer['bullets'] = is_array($layer['bullets'] ?? null) ? array_values($layer['bullets']) : [];
            $layer['tags'] = is_array($layer['tags'] ?? null) ? array_values($layer['tags']) : [];

            return $layer;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function identityLayerPathCandidates(string $contentPackId, string $contentPackageDir): array
    {
        $root = rtrim((string) config('content_packs.root', ''), DIRECTORY_SEPARATOR);
        if ($root === '') {
            return [];
        }

        $parts = explode('.', $contentPackId);
        $region = strtoupper(str_replace('-', '_', (string) ($parts[1] ?? config('content_packs.default_region', 'CN_MAINLAND'))));
        $locale = (string) ($parts[2] ?? config('content_packs.default_locale', 'zh-CN'));

        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . $region . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $contentPackageDir . DIRECTORY_SEPARATOR . 'identity_layers.json',
        ];

        foreach (glob($root . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $contentPackageDir . DIRECTORY_SEPARATOR . 'identity_layers.json') ?: [] as $path) {
            $candidates[] = $path;
        }

        return array_values(array_unique($candidates));
    }

    private function shouldPreferAuthoredMbtiIdentityLayer(string $contentPackId, string $contentPackageDir): bool
    {
        $packScaleCode = strtoupper(trim((string) strtok($contentPackId, '.')));
        $normalizedDir = strtoupper(trim($contentPackageDir));

        return $packScaleCode === 'MBTI'
            || str_starts_with($normalizedDir, 'MBTI');
    }
}
