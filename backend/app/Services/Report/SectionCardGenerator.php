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

        // 用于“稳定打散”的种子（同一个用户/attempt 结果稳定，不同 attempt 会变化）
        // 如果上游传了 attempt_id（推荐），用它；否则退化为 tags hash
        $seed = $this->stableSeed($userSet, $axisInfo);

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

            $isAxis = $this->isAxisCardId($id);

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
                '_is_axis' => $isAxis,
                '_shuffle' => $this->stableShuffleKey($seed, $id),
            ];
        }

        // 排序（主排序：hit/priority；同分时做稳定打散；最后 id）
        usort($cands, function ($a, $b) {
            $ha = (int)($a['_hit'] ?? 0);
            $hb = (int)($b['_hit'] ?? 0);
            if ($ha !== $hb) return $hb <=> $ha;

            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;

            $sa = (int)($a['_shuffle'] ?? 0);
            $sb = (int)($b['_shuffle'] ?? 0);
            if ($sa !== $sb) return $sa <=> $sb;

            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        $out  = [];
        $seen = [];

        // A) 先拿 hit>0 的（更贴用户），但优先保证 non-axis 至少 1 张
        // 1) 先挑 hit>0 的 non-axis
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)($c['_hit'] ?? 0) <= 0) continue;
            if ((bool)($c['_is_axis'] ?? false) === true) continue;

            $id = (string)$c['id'];
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;

            unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
            $out[] = $c;

            // 先确保 1 张 non-axis 就够了（避免 non-axis 塞太多）
            if ($this->countNonAxis($out) >= 1) break;
        }

        // 2) 再挑 hit>0 的 axis / non-axis（按排序继续填）
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)($c['_hit'] ?? 0) <= 0) continue;

            $id = (string)$c['id'];
            if ($id === '' || isset($seen[$id])) continue;

            // axis 卡最多 2 张（你现在的体验目标就是 axis=2 + non_axis=1）
            if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= 2) {
                continue;
            }

            $seen[$id] = true;
            unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
            $out[] = $c;
        }

        // B) 不足时：强制补齐到 “axis=2 + non_axis=1”（优先补 non-axis）
        // 先补 non-axis 到至少 1
        if ($this->countNonAxis($out) < 1) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
                if ($this->countNonAxis($out) >= 1) break;
            }
        }

        // 再补 axis 到 2
        if ($this->countAxis($out) < 2) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) !== true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
                if ($this->countAxis($out) >= 2) break;
            }
        }

        // C) 仍不足：用 fallback_tags 补齐（优先 non-axis）
        if (count($out) < $minCards) {
            // 先补 non-axis 且带 fallbackTag
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                if (!$this->hasAnyTag($c['tags'] ?? [], $fallbackTags)) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }

            // 还不够再补 axis 且带 fallbackTag（但 axis 最多 2）
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) !== true) continue;
                if ($this->countAxis($out) >= 2) continue;

                if (!$this->hasAnyTag($c['tags'] ?? [], $fallbackTags)) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }
        }

        // D) 仍不足：随便补到 minCards（依然尽量维持 axis<=2）
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $minCards) break;

                $id = (string)$c['id'];
                if ($id === '' || isset($seen[$id])) continue;

                if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= 2) {
                    continue;
                }

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
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

    private function isAxisCardId(string $id): bool
    {
        return str_contains($id, '_axis_');
    }

    private function countAxis(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            if ($id !== '' && $this->isAxisCardId($id)) $n++;
        }
        return $n;
    }

    private function countNonAxis(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            if ($id !== '' && !$this->isAxisCardId($id)) $n++;
        }
        return $n;
    }

    private function hasAnyTag(array $tags, array $needles): bool
    {
        if (!is_array($tags) || empty($tags)) return false;
        foreach ($needles as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) return true;
        }
        return false;
    }

    private function stableSeed(array $userSet, array $axisInfo): int
    {
        // 如果上游愿意传 attempt_id 到 axisInfo['attempt_id']，这里会更稳定/更分散
        $attemptId = $axisInfo['attempt_id'] ?? null;
        if (is_string($attemptId) && $attemptId !== '') {
            return crc32($attemptId);
        }

        // 退化：用 tags + axes 做 hash
        $tags = array_keys($userSet);
        sort($tags);
        $axes = [];
        foreach ($axisInfo as $k => $v) {
            if (!is_array($v)) continue;
            $dim = (string)($k);
            $side = (string)($v['side'] ?? '');
            if ($dim !== '' && $side !== '') $axes[] = "{$dim}:{$side}";
        }
        sort($axes);

        return crc32(json_encode([$tags, $axes], JSON_UNESCAPED_UNICODE));
    }

    private function stableShuffleKey(int $seed, string $id): int
    {
        // 0..2^31-1
        return (int)(crc32($seed . '|' . $id) & 0x7fffffff);
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
     * 读取 JSON：多路径兜底（保持一致）
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