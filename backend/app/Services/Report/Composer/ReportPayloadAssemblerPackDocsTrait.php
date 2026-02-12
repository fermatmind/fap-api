<?php

namespace App\Services\Report\Composer;

use App\DTO\ResolvedPack;
use App\Services\Content\ContentPack;
use App\Services\Content\ContentStore;
use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerPackDocsTrait
{
    private function toContentPackChain(ResolvedPack $rp): array
    {
        $make = function (array $manifest, string $baseDir): ContentPack {
            return new ContentPack(
                packId: (string) ($manifest['pack_id'] ?? ''),
                scaleCode: (string) ($manifest['scale_code'] ?? ''),
                region: (string) ($manifest['region'] ?? ''),
                locale: (string) ($manifest['locale'] ?? ''),
                version: (string) ($manifest['content_package_version'] ?? ''),
                basePath: $baseDir,
                manifest: $manifest,
            );
        };

        $out = [];
        $out[] = $make($rp->manifest ?? [], (string) ($rp->baseDir ?? ''));

        $fbs = $rp->fallbackChain ?? [];
        if (is_array($fbs)) {
            foreach ($fbs as $fb) {
                if (!is_array($fb)) {
                    continue;
                }
                $m = is_array($fb['manifest'] ?? null) ? $fb['manifest'] : [];
                $d = (string) ($fb['base_dir'] ?? '');
                if ($m && $d !== '') {
                    $out[] = $make($m, $d);
                }
            }
        }

        return $out;
    }

    private function loadJsonDocFromPackChain(
        array $chain,
        string $assetKey,
        string $wantedBasename,
        array $ctx,
        string $legacyContentPackageDir
    ): ?array {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }

            $paths = $this->flattenAssetPaths($p->assets()[$assetKey] ?? null);

            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') {
                    continue;
                }
                if (basename($rel) !== $wantedBasename) {
                    continue;
                }

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) {
                    continue;
                }

                $json = json_decode((string) file_get_contents($abs), true);
                if (is_array($json)) {
                    Log::info('[PACK] json_loaded', [
                        'asset' => $assetKey,
                        'file' => $wantedBasename,
                        'pack_id' => $p->packId(),
                        'version' => $p->version(),
                        'path' => $abs,
                        'schema' => $json['schema'] ?? null,
                    ]);
                    return $json;
                }
            }
        }

        if (is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($legacyContentPackageDir, $wantedBasename);

            if (is_object($raw)) {
                $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            }
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) {
                    return $doc;
                }
            }
        }

        Log::warning('[PACK] json_not_found', [
            'asset' => $assetKey,
            'file' => $wantedBasename,
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return null;
    }

    private function loadReportRulesDocFromPackChain(array $chain): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }

            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_rules.json';
            if (!is_file($abs)) {
                continue;
            }

            $j = json_decode((string) file_get_contents($abs), true);
            if (!is_array($j)) {
                continue;
            }

            $j['__src'] = [
                'pack_id' => $p->packId(),
                'version' => $p->version(),
                'file' => 'report_rules.json',
                'rel' => 'report_rules.json',
                'path' => $abs,
            ];

            return $j;
        }

        return null;
    }

    private function flattenAssetPaths($assetVal): array
    {
        if (!is_array($assetVal)) {
            return [];
        }

        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }

        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') {
                continue;
            }
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') {
                    $out[] = $x;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeAssemblerMetaSections($sectionsMeta): array
    {
        if (!is_array($sectionsMeta) || $sectionsMeta === []) {
            return [];
        }

        $isList = array_keys($sectionsMeta) === range(0, count($sectionsMeta) - 1);

        if (!$isList) {
            $out = [];
            foreach ($sectionsMeta as $k => $v) {
                if (!is_string($k) || $k === '') {
                    continue;
                }
                if (is_array($v)) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }

        $out = [];
        foreach ($sectionsMeta as $node) {
            if (!is_array($node)) {
                continue;
            }

            $sec =
                (string) ($node['section'] ?? '') ?:
                (string) ($node['section_key'] ?? '') ?:
                (string) ($node['key'] ?? '') ?:
                (string) ($node['name'] ?? '');

            $sec = trim($sec);
            if ($sec === '') {
                continue;
            }

            if (isset($node['assembler']) && is_array($node['assembler'])) {
                $out[$sec] = $node;
            } else {
                $out[$sec] = ['assembler' => $node];
            }
        }

        return $out;
    }

    private function buildFallbackAssemblerMetaSections(
        array $sections,
        array $chain,
        ContentStore $store,
        string $legacyContentPackageDir
    ): array {
        $policyDoc = $this->loadSectionPoliciesDocFromPackChain($chain);

        $defaults = [
            'min_cards' => 4,
            'target' => 5,
            'max' => 7,
            'allow_fallback' => true,
        ];

        $out = [];

        foreach ($sections as $secKey => $secNode) {
            $secKey = (string) $secKey;
            $cards = is_array($secNode['cards'] ?? null) ? $secNode['cards'] : [];
            $final = count($cards);

            $policy = $this->pickSectionPolicy($policyDoc, $secKey, $defaults);

            if (!isset($policy['min']) && isset($policy['min_cards'])) {
                $policy['min'] = $policy['min_cards'];
            }
            if (!isset($policy['min_cards']) && isset($policy['min'])) {
                $policy['min_cards'] = $policy['min'];
            }

            $want = max((int) ($policy['min_cards'] ?? 0), (int) ($policy['target'] ?? 0));
            $max = (int) ($policy['max'] ?? 0);
            if ($max > 0) {
                $want = min($want, $max);
            }

            $out[$secKey] = [
                'assembler' => [
                    'ok' => false,
                    'reason' => 'composer_built_meta_fallback_because_assembler_meta_missing',
                    'meta_fallback' => true,
                    'policy' => array_merge($policy, [
                        'want_cards' => $want,
                    ]),
                    'counts' => [
                        'final' => $final,
                        'final_count' => $final,
                        'want' => $want,
                        'shortfall' => max(0, $want - $final),
                    ],
                ],
            ];
        }

        return $out;
    }

    private function loadSectionPoliciesDocFromPackChain(array $chain): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }

            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_section_policies.json';
            if (!is_file($abs)) {
                continue;
            }

            $j = json_decode((string) file_get_contents($abs), true);
            if (!is_array($j)) {
                continue;
            }

            $j['__src'] = [
                'pack_id' => $p->packId(),
                'version' => $p->version(),
                'file' => 'report_section_policies.json',
                'rel' => 'report_section_policies.json',
                'path' => $abs,
            ];
            return $j;
        }

        return null;
    }

    private function pickSectionPolicy(?array $doc, string $secKey, array $defaults): array
    {
        if (!is_array($doc)) {
            return $defaults;
        }

        $candidates = [
            $doc['items'][$secKey] ?? null,
            $doc['policies'][$secKey] ?? null,
            $doc['sections'][$secKey] ?? null,
            $doc[$secKey] ?? null,
        ];

        foreach ($candidates as $c) {
            if (!is_array($c)) {
                continue;
            }

            $min = $c['min_cards'] ?? $c['min'] ?? null;
            $target = $c['target'] ?? $c['target_cards'] ?? null;
            $max = $c['max'] ?? $c['max_cards'] ?? null;

            $out = $defaults;

            if (is_numeric($min)) {
                $out['min_cards'] = (int) $min;
            }
            if (is_numeric($target)) {
                $out['target'] = (int) $target;
            }
            if (is_numeric($max)) {
                $out['max'] = (int) $max;
            }

            if (array_key_exists('allow_fallback', $c)) {
                $out['allow_fallback'] = (bool) $c['allow_fallback'];
            }

            $out['min'] = $out['min_cards'];
            return $out;
        }

        return $defaults;
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }
}
