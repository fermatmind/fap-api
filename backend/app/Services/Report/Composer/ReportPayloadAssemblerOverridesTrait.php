<?php

namespace App\Services\Report\Composer;

use App\Services\Content\ContentPack;
use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerOverridesTrait
{
    private function applyOverridesUnified(
        array $chain,
        string $contentPackageDir,
        string $typeCode,
        array $tags,
        array $baseHighlights,
        array $sections,
        array $recommendedReads,
        ?array $overridesDoc,
        array $overridesOrderBuckets,
        bool $applyReadsOverrides = true
    ): array {
        $unifiedDoc = $this->buildUnifiedOverridesDocForApplierFromPackChain($chain, $overridesDoc);

        $ovrCtx = [
            'report_overrides_doc' => $unifiedDoc,
            'overrides_debug' => (bool) env('FAP_OVR_DEBUG', false),
            'tags' => $tags,
            'capture_explain' => app()->environment('local') && (
                (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
            ),
            'explain_collector' => (app()->environment('local') && (
                (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
            )) ? ($GLOBALS['__re_explain_collector__'] ?? null) : null,
        ];

        $highlights = $baseHighlights;

        foreach ($overridesOrderBuckets as $bucket) {
            if ($bucket === 'highlights_legacy') {
                $highlights = $this->overridesApplier->apply($contentPackageDir, $typeCode, $highlights);
                continue;
            }
            if ($bucket === 'unified') {
                $highlights = $this->reportOverridesApplier->applyHighlights(
                    $contentPackageDir,
                    $typeCode,
                    $highlights,
                    $ovrCtx
                );
            }
        }

        if ($applyReadsOverrides) {
            Log::debug('[reads] before applyReads', [
                'count' => is_array($recommendedReads) ? count($recommendedReads) : -1,
                'first' => is_array($recommendedReads) ? ($recommendedReads[0]['id'] ?? null) : null,
            ]);

            $recommendedReads = $this->reportOverridesApplier->applyReads(
                $contentPackageDir,
                $typeCode,
                $recommendedReads,
                $ovrCtx
            );

            Log::debug('[reads] after applyReads', [
                'count' => is_array($recommendedReads) ? count($recommendedReads) : -1,
                'first' => is_array($recommendedReads) ? ($recommendedReads[0]['id'] ?? null) : null,
            ]);
        }

        $ovrExplain = $this->reportOverridesApplier->getExplain();

        return [$highlights, $sections, $recommendedReads, $ovrExplain];
    }

    private function buildUnifiedOverridesDocForApplierFromPackChain(array $chain, ?array $overridesDoc): ?array
    {
        $docs = [];

        if (is_array($overridesDoc)) {
            $docs[] = $overridesDoc;
        }

        if (empty($docs)) {
            return null;
        }

        $base = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($docs as $d) {
            if (!is_array($d)) {
                continue;
            }

            $rules = null;
            if (is_array($d['rules'] ?? null)) {
                $rules = $d['rules'];
            } elseif (is_array($d['overrides'] ?? null)) {
                $rules = $d['overrides'];
            }

            if (is_array($rules)) {
                foreach ($rules as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    if (!isset($r['__src']) && is_array($d['__src'] ?? null)) {
                        $r['__src'] = $d['__src'];
                    }
                    $base['rules'][] = $r;
                }
            }

            if (is_array($d['__src'] ?? null)) {
                $base['__src_chain'][] = $d['__src'];
            }
            if (is_array($d['__src_chain'] ?? null)) {
                foreach ($d['__src_chain'] as $src) {
                    if (is_array($src)) {
                        $base['__src_chain'][] = $src;
                    }
                }
            }
        }

        return $base;
    }

    private function loadOverridesDocsFromPackChain(array $chain, array $ctx, string $legacyContentPackageDir): array
    {
        $trace = (bool) env('FAP_OVR_TRACE', false);

        $docs = [];
        $idx = 0;

        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }

            $assetVal = $p->assets()['overrides'] ?? null;
            if (!is_array($assetVal) || $assetVal === []) {
                continue;
            }

            $orderedPaths = $this->getOverridesOrderedPaths($assetVal);

            foreach ($orderedPaths as $rel) {
                if (!is_string($rel) || trim($rel) === '') {
                    continue;
                }

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) {
                    continue;
                }

                $json = json_decode((string) file_get_contents($abs), true);
                if (!is_array($json)) {
                    continue;
                }

                if (!is_array($json['rules'] ?? null) && is_array($json['overrides'] ?? null)) {
                    $json['rules'] = $json['overrides'];
                }

                $src = [
                    'idx' => $idx,
                    'pack_id' => $p->packId(),
                    'version' => $p->version(),
                    'file' => basename($rel),
                    'rel' => $rel,
                    'path' => $abs,
                ];
                $json['__src'] = $src;

                if (is_array($json['rules'] ?? null)) {
                    foreach ($json['rules'] as &$r) {
                        if (is_array($r)) {
                            $r['__src'] = $src;
                        }
                    }
                    unset($r);
                }

                if ($trace) {
                    Log::info('[OVR] file_loaded', [
                        'idx' => $src['idx'],
                        'pack_id' => $src['pack_id'],
                        'version' => $src['version'],
                        'file' => $src['file'],
                        'rel' => $src['rel'],
                        'schema' => $json['schema'] ?? null,
                        'count' => is_array($json['rules'] ?? null) ? count($json['rules']) : 0,
                    ]);
                }

                $docs[] = $json;
                $idx++;
            }
        }

        if (empty($docs) && is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($legacyContentPackageDir, 'report_overrides.json');
            if (is_object($raw)) {
                $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            }
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) {
                    $doc['__src'] = [
                        'idx' => 0,
                        'pack_id' => 'LEGACY_CTX',
                        'version' => null,
                        'file' => 'report_overrides.json',
                        'rel' => null,
                        'path' => null,
                    ];
                    if (is_array($doc['rules'] ?? null)) {
                        foreach ($doc['rules'] as &$r) {
                            if (is_array($r)) {
                                $r['__src'] = $doc['__src'];
                            }
                        }
                        unset($r);
                    }
                    $docs[] = $doc;
                }
            }
        }

        return $docs;
    }

    private function getOverridesOrderedPaths(array $assetVal): array
    {
        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }

        $order = $assetVal['order'] ?? null;
        $out = [];

        if (is_array($order) && $order !== []) {
            foreach ($order as $bucket) {
                if (!is_string($bucket) || $bucket === '') {
                    continue;
                }
                $v = $assetVal[$bucket] ?? null;
                if (!is_array($v)) {
                    continue;
                }

                foreach ($v as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $out[] = $path;
                    }
                }
            }
            return array_values(array_unique($out));
        }

        foreach ($assetVal as $k => $v) {
            if ($k === 'order') {
                continue;
            }
            if (!is_array($v)) {
                continue;
            }
            foreach ($v as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $out[] = $path;
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function getOverridesOrderBucketsFromPackChain(array $chain): array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $assetVal = $p->assets()['overrides'] ?? null;
            if (!is_array($assetVal) || $assetVal === []) {
                continue;
            }
            return $this->getOverridesOrderBuckets($assetVal);
        }
        return ['highlights_legacy', 'unified'];
    }

    private function getOverridesOrderBuckets(array $assetVal): array
    {
        if ($this->isListArray($assetVal)) {
            return ['unified'];
        }

        $order = $assetVal['order'] ?? null;
        if (is_array($order) && $order !== []) {
            $out = [];
            foreach ($order as $x) {
                if (is_string($x) && trim($x) !== '') {
                    $out[] = $x;
                }
            }
            return $out ?: ['highlights_legacy', 'unified'];
        }

        $out = [];
        foreach ($assetVal as $k => $_) {
            if ($k === 'order') {
                continue;
            }
            if (is_string($k) && trim($k) !== '') {
                $out[] = $k;
            }
        }
        return $out ?: ['highlights_legacy', 'unified'];
    }

    private function mergeOverridesDocs(array $docs): ?array
    {
        if (empty($docs)) {
            return null;
        }

        $base = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($docs as $d) {
            if (!is_array($d)) {
                continue;
            }

            if (is_array($d['rules'] ?? null)) {
                foreach ($d['rules'] as $r) {
                    if (is_array($r)) {
                        $base['rules'][] = $r;
                    }
                }
            }

            if (is_array($d['__src'] ?? null)) {
                $base['__src_chain'][] = $d['__src'];
            }
        }

        return $base;
    }

    private function packIdToDir(string $packId): string
    {
        $s = trim($packId);
        if ($s === '') {
            return '';
        }

        if (substr_count($s, '.') >= 3) {
            $parts = explode('.', $s);
            $scale = $parts[0] ?? 'MBTI';
            $region = strtoupper($parts[1] ?? 'GLOBAL');
            $locale = $parts[2] ?? 'en';
            $ver = implode('.', array_slice($parts, 3));
            return "{$scale}/{$region}/{$locale}/{$ver}";
        }

        if (str_contains($s, '/')) {
            return trim($s, '/');
        }

        return $s;
    }
}
