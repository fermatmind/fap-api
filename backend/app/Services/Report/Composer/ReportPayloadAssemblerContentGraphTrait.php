<?php

namespace App\Services\Report\Composer;

use App\Services\Content\ContentPack;
use Illuminate\Support\Facades\Log;

trait ReportPayloadAssemblerContentGraphTrait
{
    private function buildRecommendedReadsFromContentGraph(
        array $chain,
        string $scaleCode,
        string $region,
        string $locale,
        string $typeCode,
        array $scores,
        array $axisStates
    ): array {
        $pack = $this->resolveContentGraphPack($chain, $scaleCode, $region, $locale);
        if (!$pack instanceof ContentPack) {
            return [[], false];
        }

        if (!$this->packSupportsContentGraph($pack)) {
            return [[], false];
        }

        $doc = $this->loadContentGraphDoc($pack->basePath());
        if (!is_array($doc)) {
            return [[], false];
        }

        $nodes = $this->loadContentGraphNodes($pack->basePath());
        $items = $this->buildRecommendedReadsFromContentGraphDoc($doc, $nodes, $typeCode, $scores, $axisStates);

        return [is_array($items) ? $items : [], true];
    }

    private function resolveContentGraphPack(
        array $chain,
        string $scaleCode,
        string $region,
        string $locale
    ): ?ContentPack {
        $primary = $chain[0] ?? null;
        if (!$primary instanceof ContentPack) {
            return null;
        }

        $pin = trim((string) env('CONTENT_GRAPH_PACK_PIN', ''));
        if ($pin === '') {
            return $primary;
        }

        $pinVersion = $this->normalizeRequestedVersion($pin);
        if (!is_string($pinVersion) || $pinVersion === '') {
            return $primary;
        }

        try {
            $rp = $this->resolver->resolve($scaleCode, $region, $locale, $pinVersion);
            $pinChain = $this->toContentPackChain($rp);
            $pinPack = $pinChain[0] ?? null;
            if ($pinPack instanceof ContentPack) {
                return $pinPack;
            }
        } catch (\Throwable $e) {
            Log::warning('[content_graph] pack_pin_resolve_failed', [
                'pin' => $pin,
                'scale' => $scaleCode,
                'region' => $region,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
        }

        return $primary;
    }

    private function packSupportsContentGraph(ContentPack $pack): bool
    {
        $caps = $pack->capabilities();
        return (bool) ($caps['content_graph'] ?? false);
    }

    private function loadContentGraphDoc(string $baseDir): ?array
    {
        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'content_graph.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function loadContentGraphNodes(string $baseDir): array
    {
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

        return [
            'read' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'reads', 'read'),
            'role_card' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'role_cards', 'role_card'),
            'strategy_card' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'strategy_cards', 'strategy_card'),
        ];
    }

    private function loadContentGraphNodesFromDir(string $dir, string $type): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return [];
        }

        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false || trim($raw) === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }

            $nodeType = (string) ($json['type'] ?? '');
            if ($nodeType !== $type) {
                continue;
            }

            $id = (string) ($json['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $status = (string) ($json['status'] ?? '');
            if ($status !== 'active') {
                continue;
            }

            $out[$id] = [
                'id' => $id,
                'type' => $type,
                'title' => (string) ($json['title'] ?? ''),
                'slug' => (string) ($json['slug'] ?? ''),
                'status' => $status,
            ];
        }

        return $out;
    }

    private function buildRecommendedReadsFromContentGraphDoc(
        array $doc,
        array $nodes,
        string $typeCode,
        array $scores,
        array $axisStates
    ): array {
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];
        $typeRules = is_array($rules['type_code'] ?? null) ? $rules['type_code'] : [];
        $roleRules = is_array($typeRules['role_card'] ?? null) ? $typeRules['role_card'] : [];
        $readRules = is_array($typeRules['reads'] ?? null) ? $typeRules['reads'] : [];
        $axisRules = is_array($rules['axis_state_or_trait_bucket'] ?? null) ? $rules['axis_state_or_trait_bucket'] : [];

        $typeCodeNorm = strtoupper(trim($typeCode));
        if ($typeCodeNorm === '') {
            return [];
        }

        $traitBuckets = $this->deriveTraitBucketsFromScores($scores);
        $axisTokens = $this->deriveAxisStateTokens($scores, $axisStates);
        $axisStates = is_array($axisStates) ? $axisStates : [];

        $out = [];
        $seen = [];

        $append = function (string $id, string $type, string $why) use (&$out, &$seen, $nodes): bool {
            if ($id === '' || isset($seen[$id])) {
                return false;
            }
            if (!isset($nodes[$type][$id]) || !is_array($nodes[$type][$id])) {
                return false;
            }

            $node = $nodes[$type][$id];
            $out[] = [
                'id' => (string) ($node['id'] ?? $id),
                'type' => $type,
                'title' => (string) ($node['title'] ?? ''),
                'slug' => (string) ($node['slug'] ?? ''),
                'why' => $why,
                'show_order' => 0,
            ];
            $seen[$id] = true;
            return true;
        };

        foreach ($roleRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $tc = strtoupper(trim((string) ($rule['type_code'] ?? '')));
            if ($tc !== $typeCodeNorm) {
                continue;
            }

            foreach ($this->normalizeStringList($rule['ids'] ?? []) as $id) {
                if ($append($id, 'role_card', $this->formatContentGraphWhy($typeCodeNorm, '', ''))) {
                    break;
                }
            }
            break;
        }

        $readsCount = 0;

        foreach ($readRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $tc = strtoupper(trim((string) ($rule['type_code'] ?? '')));
            if ($tc !== $typeCodeNorm) {
                continue;
            }

            foreach ($this->normalizeStringList($rule['ids'] ?? []) as $id) {
                if ($readsCount >= 3) {
                    break;
                }
                if ($append($id, 'read', $this->formatContentGraphWhy($typeCodeNorm, '', ''))) {
                    $readsCount++;
                }
            }
            break;
        }

        $extraReads = [];
        $strategyCards = [];

        foreach ($axisRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $ruleTraitBuckets = $this->normalizeStringList($rule['trait_buckets'] ?? []);
            $ruleAxisStates = $this->normalizeStringList($rule['axis_states'] ?? []);

            $matchTrait = $this->firstMatch($ruleTraitBuckets, $traitBuckets);
            $matchAxis = $this->firstMatch($ruleAxisStates, $axisTokens);

            if ($matchTrait === null && $matchAxis === null) {
                continue;
            }

            $axisStateForWhy = $this->axisStateForWhy($matchAxis, $matchTrait, $axisStates);
            $why = $this->formatContentGraphWhy($typeCodeNorm, $matchTrait ?? '', $axisStateForWhy);

            foreach ($this->normalizeStringList($rule['read_ids'] ?? []) as $id) {
                $extraReads[] = ['id' => $id, 'why' => $why];
            }
            foreach ($this->normalizeStringList($rule['strategy_card_ids'] ?? []) as $id) {
                $strategyCards[] = ['id' => $id, 'why' => $why];
            }
        }

        foreach ($extraReads as $it) {
            if ($readsCount >= 3) {
                break;
            }
            if ($append((string) ($it['id'] ?? ''), 'read', (string) ($it['why'] ?? ''))) {
                $readsCount++;
            }
        }

        $strategyCount = 0;
        foreach ($strategyCards as $it) {
            if ($strategyCount >= 2) {
                break;
            }
            if ($append((string) ($it['id'] ?? ''), 'strategy_card', (string) ($it['why'] ?? ''))) {
                $strategyCount++;
            }
        }

        $out = array_slice($out, 0, 6);
        foreach ($out as $i => &$item) {
            $item['show_order'] = $i + 1;
        }
        unset($item);

        return $out;
    }

    private function normalizeStringList($list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '') {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    private function firstMatch(array $ruleValues, array $inputValues): ?string
    {
        if (empty($ruleValues) || empty($inputValues)) {
            return null;
        }

        $set = [];
        foreach ($inputValues as $v) {
            if (!is_string($v)) {
                continue;
            }
            $set[$v] = true;
        }

        foreach ($ruleValues as $v) {
            if (!is_string($v)) {
                continue;
            }
            if (isset($set[$v])) {
                return $v;
            }
        }
        return null;
    }

    private function deriveTraitBucketsFromScores(array $scores): array
    {
        $map = [
            'JP' => [
                'J' => 'high_conscientiousness',
                'P' => 'low_conscientiousness',
            ],
            'TF' => [
                'F' => 'high_empathy',
                'T' => 'low_empathy',
            ],
            'AT' => [
                'A' => 'high_resilience',
                'T' => 'low_resilience',
            ],
        ];

        $out = [];
        foreach ($map as $dim => $pairs) {
            $side = strtoupper((string) ($scores[$dim]['side'] ?? ''));
            if (isset($pairs[$side])) {
                $out[] = $pairs[$side];
            }
        }

        return $out;
    }

    private function deriveAxisStateTokens(array $scores, array $axisStates): array
    {
        $out = [];
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        foreach ($dims as $dim) {
            $side = strtoupper((string) ($scores[$dim]['side'] ?? ''));
            if ($side !== '') {
                $out[] = "{$dim}:{$side}";
            }

            $state = (string) ($axisStates[$dim] ?? '');
            if ($state !== '') {
                $out[] = "{$dim}:{$state}";
            }
        }

        return array_values(array_unique($out));
    }

    private function axisStateForWhy(?string $matchedAxis, ?string $matchedTrait, array $axisStates): string
    {
        if (is_string($matchedAxis) && $matchedAxis !== '') {
            return $matchedAxis;
        }

        if (is_string($matchedTrait) && $matchedTrait !== '') {
            $dim = $this->traitBucketToAxisDim($matchedTrait);
            if ($dim && is_string($axisStates[$dim] ?? null)) {
                return (string) $axisStates[$dim];
            }
        }

        return '';
    }

    private function traitBucketToAxisDim(string $traitBucket): ?string
    {
        return match ($traitBucket) {
            'high_conscientiousness', 'low_conscientiousness' => 'JP',
            'high_empathy', 'low_empathy' => 'TF',
            'high_resilience', 'low_resilience' => 'AT',
            default => null,
        };
    }

    private function formatContentGraphWhy(string $typeCode, string $traitBucket, string $axisState): string
    {
        $t = $typeCode !== '' ? $typeCode : '-';
        $tb = $traitBucket !== '' ? $traitBucket : '-';
        $as = $axisState !== '' ? $axisState : '-';
        return "type_code:{$t} / trait_bucket:{$tb} / axis_state:{$as}";
    }
}
