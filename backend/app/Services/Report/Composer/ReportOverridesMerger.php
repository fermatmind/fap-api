<?php

declare(strict_types=1);

namespace App\Services\Report\Composer;

class ReportOverridesMerger
{
    public function merge(array $docs, array $orderBuckets): ?array
    {
        if ($docs === []) {
            return null;
        }

        $bucketOrder = [];
        foreach (array_values($orderBuckets) as $idx => $bucket) {
            if (is_string($bucket) && $bucket !== '') {
                $bucketOrder[$bucket] = $idx;
            }
        }

        $normalizeBucket = static function (array $doc): string {
            $bucket = (string) ($doc['__bucket'] ?? '');
            if ($bucket !== '') {
                return $bucket;
            }

            $src = is_array($doc['__src'] ?? null) ? $doc['__src'] : [];
            $rel = strtolower((string) ($src['rel'] ?? $src['file'] ?? ''));
            if ($rel !== '' && str_contains($rel, 'highlight')) {
                return 'highlights_legacy';
            }

            return 'unified';
        };

        usort($docs, static function (array $a, array $b) use ($bucketOrder, $normalizeBucket): int {
            $aBucket = $normalizeBucket($a);
            $bBucket = $normalizeBucket($b);

            $aRank = $bucketOrder[$aBucket] ?? PHP_INT_MAX;
            $bRank = $bucketOrder[$bBucket] ?? PHP_INT_MAX;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            $aIdx = (int) ((is_array($a['__src'] ?? null) ? ($a['__src']['idx'] ?? 0) : 0));
            $bIdx = (int) ((is_array($b['__src'] ?? null) ? ($b['__src']['idx'] ?? 0) : 0));

            return $aIdx <=> $bIdx;
        });

        $base = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $rules = null;
            if (is_array($doc['rules'] ?? null)) {
                $rules = $doc['rules'];
            } elseif (is_array($doc['overrides'] ?? null)) {
                $rules = $doc['overrides'];
            }

            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    if (!isset($rule['__src']) && is_array($doc['__src'] ?? null)) {
                        $rule['__src'] = $doc['__src'];
                    }
                    $base['rules'][] = $rule;
                }
            }

            if (is_array($doc['__src'] ?? null)) {
                $base['__src_chain'][] = $doc['__src'];
            }

            if (is_array($doc['__src_chain'] ?? null)) {
                foreach ($doc['__src_chain'] as $src) {
                    if (is_array($src)) {
                        $base['__src_chain'][] = $src;
                    }
                }
            }
        }

        return $base;
    }

    public function apply(array $baseReport, ?array $overridesDoc, array $ctxMeta): array
    {
        if (!is_array($overridesDoc) || $overridesDoc === []) {
            return $baseReport;
        }

        $out = $baseReport;
        $out['_meta'] = is_array($out['_meta'] ?? null) ? $out['_meta'] : [];
        $out['_meta']['overrides'] = [
            '__src' => $overridesDoc['__src'] ?? null,
            '__src_chain' => $overridesDoc['__src_chain'] ?? [],
            'ctx' => $ctxMeta,
        ];

        return $out;
    }
}
