<?php

namespace App\Services\Report;

class IdentityLayerBuilder
{
    /**
     * build layers.identity object
     *
     * @param string $contentPackageVersion
     * @param string $typeCode
     * @param array  $scoresPct  你现有的 pct 结构（宽松兼容）
     * @param array|null $borderlineNote buildBorderlineNote 的产物（可选）
     */
    public function build(
        string $contentPackageVersion,
        string $typeCode,
        array $scoresPct = [],
        ?array $borderlineNote = null
    ): array {
        $items = $this->loadReportAssetItems($contentPackageVersion, 'identity_layers.json');
        $base  = is_array($items[$typeCode] ?? null) ? $items[$typeCode] : null;

        if (!$base) {
            // ✅ fallback：保证 layers.identity 永远是对象
            $base = [
                'title' => $typeCode,
                'subtitle' => '',
                'one_liner' => '',
                'bullets' => [],
                'tags' => ['identity', 'fallback:true'],
            ];
        }

        // 统一字段规范化（避免前端崩）
        if (!is_string($base['title'] ?? null)) $base['title'] = $typeCode;
        if (!is_string($base['subtitle'] ?? null)) $base['subtitle'] = '';
        if (!is_string($base['one_liner'] ?? null)) $base['one_liner'] = '';
        if (!is_array($base['bullets'] ?? null)) $base['bullets'] = [];
        if (!is_array($base['tags'] ?? null)) $base['tags'] = [];

        // ✅ 微调 1 句（优先 borderline，其次 AT 强弱）
        $micro = $this->buildMicroLine($scoresPct, $borderlineNote);
        if ($micro) {
            $base['micro_line'] = $micro;
        } else {
            $base['micro_line'] = '';
        }

        // ✅ subtitle：做成肉眼可见的 A/T 差异（优先不覆盖已有 subtitle）
if (trim((string)($base['subtitle'] ?? '')) === '') {
    $at = $scoresPct['AT'] ?? null;
    $side  = $this->pickSide($at);   // A / T
    $pct   = $this->pickPct($at);    // 50..100
    $delta = $this->pickDelta($at);  // 0..50

    if ($side) {
        if ($delta !== null && $delta >= 20) {
            $base['subtitle'] = $side === 'A'
                ? "更稳、更自洽（A），压力下更能扛住。"
                : "更敏感、更自我要求（T），更在意细节与评价。";
        } else {
            $base['subtitle'] = $side === 'A'
                ? "略偏 A：倾向先稳住再推进。"
                : "略偏 T：倾向边走边校准。";
        }
    }
}
        
        // 附上 type_code，方便前端/埋点
        $base['type_code'] = $typeCode;

        return $base;
    }

    private function buildMicroLine(array $scoresPct, ?array $borderlineNote): ?string
    {
        // 1) 优先：borderline_note 命中（取第一条）
        if (is_array($borderlineNote) && is_array($borderlineNote['items'] ?? null) && count($borderlineNote['items']) > 0) {
            $it = $borderlineNote['items'][0];
            // 兼容不同结构：text / title / dim
            $txt = is_array($it) ? (string)($it['text'] ?? $it['title'] ?? '') : '';
            if ($txt !== '') {
                return "边界提示：{$txt}";
            }
        }

        // 2) 否则：按 AT 强弱加一句
        $at = $scoresPct['AT'] ?? null;
        $side = $this->pickSide($at);     // A / T
        $pct  = $this->pickPct($at);      // 50..100
        $delta = $this->pickDelta($at);   // 0..50

        if ($side && $pct !== null) {
            // 强阈值：可按你引擎的 clear/strong/very_strong 再调
            if ($delta !== null && $delta >= 20) {
                return $side === 'A'
                    ? "压力姿态：更偏 A（{$pct}%）——更稳、更敢拍板。"
                    : "压力姿态：更偏 T（{$pct}%）——更敏感、更会自省与校准。";
            }

            // 中等强度也给一句更“轻”的
            return $side === 'A'
                ? "压力姿态：略偏 A（{$pct}%）——倾向先稳住再推进。"
                : "压力姿态：略偏 T（{$pct}%）——倾向边走边校准。";
        }

        return null;
    }

    // ===== tolerant extractors (兼容你现在的 scoresPct 结构) =====

 private function pickSide($axis): ?string
{
    // ✅ 支持 int pct（你现在 results.scores_pct 就是这样）
    if (is_int($axis) || is_float($axis) || (is_string($axis) && is_numeric($axis))) {
        $raw = (int)$axis; // 0..100 或 1..99
        return $raw >= 50 ? 'A' : 'T';
    }

    if (is_array($axis)) {
        $s = $axis['side'] ?? $axis['letter'] ?? null;
        if (is_string($s) && $s !== '') return $s;

        if (isset($axis['A']) || isset($axis['T'])) {
            $a = (int)($axis['A'] ?? 0);
            $t = (int)($axis['T'] ?? 0);
            return $a >= $t ? 'A' : 'T';
        }
    }
    return null;
}

private function pickPct($axis): ?int
{
    // ✅ 支持 int pct：统一成 50..100（主侧强度）
    if (is_int($axis) || is_float($axis) || (is_string($axis) && is_numeric($axis))) {
        $raw = (int)$axis;
        return $raw >= 50 ? $raw : (100 - $raw);
    }

    if (is_array($axis)) {
        if (isset($axis['pct'])) return (int)$axis['pct'];
        if (isset($axis['percent'])) return (int)$axis['percent'];

        if ((isset($axis['A']) || isset($axis['T']))) {
            $a = (int)($axis['A'] ?? 0);
            $t = (int)($axis['T'] ?? 0);
            return max($a, $t);
        }
    }
    return null;
}

private function pickDelta($axis): ?int
{
    // ✅ delta 用 0..50
    $pct = $this->pickPct($axis);
    if ($pct !== null) return abs($pct - 50);
    return null;
}

    // ===== package loaders =====

    private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $key = $contentPackageVersion . '|' . $filename . '|RAW';
        if (isset($cache[$key])) return $cache[$key];

        $path = $this->resolvePackageFile($contentPackageVersion, $filename);
        if ($path === null) return $cache[$key] = [];

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return $cache[$key] = [];

        $json = json_decode($raw, true);
        if (!is_array($json)) return $cache[$key] = [];

        return $cache[$key] = $json;
    }

    private function loadReportAssetItems(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $key = $contentPackageVersion . '|' . $filename . '|ITEMS';
        if (isset($cache[$key])) return $cache[$key];

        $json = $this->loadReportAssetJson($contentPackageVersion, $filename);
        if (!is_array($json) || empty($json)) return $cache[$key] = [];

        $items = $json['items'] ?? $json;
        if (!is_array($items)) return $cache[$key] = [];

        return $cache[$key] = $items;
    }

    private function resolvePackageFile(string $contentPackageVersion, string $filename): ?string
    {
        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
        ]));

        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) return $p;
        }
        return null;
    }
}