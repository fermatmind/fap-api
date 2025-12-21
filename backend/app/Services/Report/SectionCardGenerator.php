<?php

namespace App\Services\Report;

final class SectionCardGenerator
{
    /**
     * @param string $section traits/career/growth/relationships
     * @param string $contentPackageVersion e.g. MBTI-CN-v0.2.1-TEST
     * @param array  $userTags TagBuilder 输出
     * @param array  $axisInfo 建议传：getReport() 里 $scores（report.scores）即可，用 delta 校验 min_delta
     * @return array[] cards（稳定字段结构）
     */
    public function generate(string $section, string $contentPackageVersion, array $userTags, array $axisInfo = []): array
    {
        $file = "report_cards_{$section}.json";
        $json = $this->loadReportAssetJson($contentPackageVersion, $file);

        $items = is_array($json['items'] ?? null) ? $json['items'] : [];
        $rules = is_array($json['rules'] ?? null) ? $json['rules'] : [];

        // 规则：至少 2 张
        $minCards    = max(2, (int)($rules['min_cards'] ?? 2));
        $targetCards = (int)($rules['target_cards'] ?? 3);
        $maxCards    = (int)($rules['max_cards'] ?? 6);
        if ($targetCards < $minCards) $targetCards = $minCards;
        if ($maxCards < $targetCards) $maxCards = $targetCards;

        $fallbackTags = $rules['fallback_tags'] ?? ['fallback', 'kind:core'];
        if (!is_array($fallbackTags)) $fallbackTags = ['fallback', 'kind:core'];

        // assets 不存在：直接返回兜底（最少 2 张）
        if (empty($items)) {
            return $this->fallbackCards($section, $minCards);
        }

        // normalize userTags set
        $userSet = [];
        foreach ($userTags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t !== '') $userSet[$t] = true;
        }

        // normalize + score
        $cands = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            $tags = is_array($it['tags'] ?? null) ? $it['tags'] : [];
            $tags = array_values(array_filter($tags, fn($x) => is_string($x) && trim($x) !== ''));
            $prio = (int)($it['priority'] ?? 0);

            // 先做 match.axis 的硬门槛（如果存在）
            if (!$this->passesAxisMatch($it, $userSet, $axisInfo)) {
                continue;
            }

            // 交集分
            $hit = 0;
            foreach ($tags as $t) {
                if (isset($userSet[$t])) $hit++;
            }

            $cands[] = [
                'id'       => $id,
                'section'  => (string)($it['section'] ?? $section),
                'title'    => (string)($it['title'] ?? ''),
                'desc'     => (string)($it['desc'] ?? ''),
                'bullets'  => is_array($it['bullets'] ?? null) ? array_values($it['bullets']) : [],
                'tips'     => is_array($it['tips'] ?? null) ? array_values($it['tips']) : [],
                'tags'     => $tags,
                'priority' => $prio,
                'match'    => $it['match'] ?? null,

                // internal score for sorting
                '_hit'     => $hit,
            ];
        }

        // 排序：先 hit（交集）-> priority -> id
        usort($cands, function ($a, $b) {
            $ha = (int)($a['_hit'] ?? 0);
            $hb = (int)($b['_hit'] ?? 0);
            if ($ha !== $hb) return $hb <=> $ha;

            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;

            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        // 先拿 hit>0 的（更贴用户）
        $out = [];
        $seen = [];
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)$c['_hit'] <= 0) continue;

            $id = (string)$c['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;

            unset($c['_hit']);
            $out[] = $c;
        }

        // 不足：用 fallback_tags 补齐
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;

                $ok = false;
                foreach ($fallbackTags as $ft) {
                    if (in_array($ft, $c['tags'] ?? [], true)) { $ok = true; break; }
                }
                if (!$ok) continue;

                $seen[$id] = true;
                unset($c['_hit']);
                $out[] = $c;
            }
        }

        // 仍不足：随便补到 minCards
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $minCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;

                $seen[$id] = true;
                unset($c['_hit']);
                $out[] = $c;
            }
        }

        // 最后兜底：assets 太少时直接造卡
        if (count($out) < $minCards) {
            $out = array_merge($out, $this->fallbackCards($section, $minCards - count($out)));
        }

        // 截断到 max
        return array_slice(array_values($out), 0, $maxCards);
    }

    private function passesAxisMatch(array $card, array $userSet, array $axisInfo): bool
    {
        $match = is_array($card['match'] ?? null) ? $card['match'] : null;
        if (!$match) return true;

        $ax = is_array($match['axis'] ?? null) ? $match['axis'] : null;
        if (!$ax) return true;

        $dim = (string)($ax['dim'] ?? '');
        $side = (string)($ax['side'] ?? '');
        $minDelta = (int)($ax['min_delta'] ?? 0);

        if ($dim === '' || $side === '') return false;

        // 必须命中 axis tag
        if (!isset($userSet["axis:{$dim}:{$side}"])) return false;

        // min_delta 校验（用 report.scores.delta 口径：0..50）
        $delta = 0;
        if (isset($axisInfo[$dim]) && is_array($axisInfo[$dim])) {
            $delta = (int)($axisInfo[$dim]['delta'] ?? 0);
        }
        if ($delta < $minDelta) return false;

        return true;
    }

    private function fallbackCards(string $section, int $need): array
    {
        $out = [];
        for ($i = 1; $i <= $need; $i++) {
            $out[] = [
                'id'       => "{$section}_fallback_{$i}",
                'section'  => $section,
                'title'    => '通用建议',
                'desc'     => '内容库暂未命中更贴合的卡片，先给你一条通用但可靠的建议。',
                'bullets'  => ['把优势沉淀成流程/模板', '关键场景加一次反向校验', '每周一次复盘：保留有效、删除无效'],
                'tips'     => ['先写下第一反应，再补一个备选', '用清单降低临场消耗'],
                'tags'     => ['fallback'],
                'priority' => 0,
                'match'    => null,
            ];
        }
        return $out;
    }

    /**
     * 读取 JSON：复制你 controller 的多路径兜底（保持一致）
     */
    private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $cacheKey = $contentPackageVersion . '|' . $filename . '|RAW';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("../content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
        ]));

        $path = null;
        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) {
                $path = $p;
                break;
            }
        }

        if ($path === null) {
            return $cache[$cacheKey] = [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $cache[$cacheKey] = [];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $cache[$cacheKey] = [];
        }

        return $cache[$cacheKey] = $json;
    }
}