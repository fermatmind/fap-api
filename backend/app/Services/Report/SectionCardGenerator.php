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
            $out = $this->fallbackCards($section, $minCards);
            // 体验层：确保至少 1 张 non-axis（fallback 本身就是 non-axis，保险起见仍跑一次）
            $seen = [];
            $out  = $this->ensureNonAxisCard($section, $out, [], $seen, $targetCards);
            return array_slice(array_values($out), 0, $maxCards);
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
            if ((int)($c['_hit'] ?? 0) <= 0) continue;

            $id = (string)$c['id'];
            if ($id === '' || isset($seen[$id])) continue;

            $seen[$id] = true;
            unset($c['_hit']);
            $out[] = $c;
        }

        // 不足：用 fallback_tags 补齐（仍优先 hit/priority 排过序的候选）
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

        // ✅体验层硬规则：至少 1 张 non-axis（否则报告看起来“全是分数模板拼接”）
        $out = $this->ensureNonAxisCard($section, $out, $cands, $seen, $targetCards);

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

    /**
     * ✅体验层修复：
     * - 每个 section 至少 1 张 non-axis（id 不含 "_axis_"）
     * - 优先从候选里找 non-axis 替换最后一张（保持长度不变）
     * - 若候选也没有 non-axis，则用“topic 非轴向兜底卡”替换最后一张
     */
    private function ensureNonAxisCard(string $section, array $out, array $cands, array &$seen, int $targetCards): array
    {
        $out = array_values(array_filter($out, fn($x) => is_array($x)));
        if (count($out) === 0) return $out;

        // 已经有 non-axis 就不动
        foreach ($out as $c) {
            $id = (string)($c['id'] ?? '');
            if ($id !== '' && !$this->isAxisId($id)) {
                return $out;
            }
        }

        // 1) 先尝试从候选里找一张 non-axis（优先 hit/priority 高的，因为 cands 已排序）
        $pick = null;
        foreach ($cands as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            if ($this->isAxisId($id)) continue;

            $pick = $c;
            break;
        }

        if ($pick) {
            unset($pick['_hit']);
            $seen[(string)$pick['id']] = true;

            // 保持总数不变：替换最后一张
            if (count($out) >= max(1, $targetCards)) {
                $out[count($out) - 1] = $pick;
            } else {
                $out[] = $pick;
            }

            return array_values($out);
        }

        // 2) 候选也没有 non-axis：用“topic 非轴向兜底卡”替换最后一张（保持总数不变）
        $fallback = $this->topicFallbackCard($section);
        $fid = (string)($fallback['id'] ?? '');
        if ($fid !== '') $seen[$fid] = true;

        $out[count($out) - 1] = $fallback;

        return array_values($out);
    }

    private function isAxisId(string $id): bool
    {
        return str_contains($id, '_axis_');
    }

    /**
     * 非轴向兜底卡（让 section 不再“全是轴向模板”）
     */
    private function topicFallbackCard(string $section): array
    {
        $title = match ($section) {
            'traits' => '补充洞察：你更像哪种“做事风格”',
            'career' => '补充洞察：你更适合的工作形态',
            'growth' => '补充洞察：把优势变成稳定输出',
            'relationships' => '补充洞察：你在关系里的默认设置',
            default => '补充洞察：一个更贴近你的角度',
        };

        $desc = match ($section) {
            'traits' => '这条不依赖某个轴的极端分数，而是对你整体风格的总结。',
            'career' => '这条不只是“你像什么”，而是“你在什么环境里最容易做出成绩”。',
            'growth' => '这条用于把优势落到动作上，让报告更像“可执行建议”。',
            'relationships' => '这条用于解释互动模式，减少“看完也不知道怎么用”的空转。',
            default => '用于让报告结构更完整、更可读。',
        };

        $bullets = match ($section) {
            'traits' => [
                '目标一旦明确，你会自动进入推进状态',
                '你更在意“结果是否落地”，而不是过程是否漂亮',
                '你愿意为长期收益做短期取舍',
            ],
            'career' => [
                '适合目标清晰、允许你做决策的岗位/团队',
                '你在“复杂任务拆解 + 推动落地”上更占优势',
                '给你明确指标，你会越做越强',
            ],
            'growth' => [
                '把你最强的能力沉淀成模板/清单',
                '关键场景增加一次“反向校验”',
                '每周固定 10 分钟复盘：保留有效、删除无效',
            ],
            'relationships' => [
                '你更在意确定性，但对方可能更在意感受与过程',
                '用一句“我理解你”替代直接给方案，冲突会明显减少',
                '约定沟通规则：什么时候讨论、什么时候先缓一缓',
            ],
            default => ['把优势沉淀成流程', '关键场景加一次反向校验', '每周一次复盘'],
        };

        $tips = match ($section) {
            'traits' => ['写下你最常用的 3 个“决策标准”', '把它当作你的个人规则库'],
            'career' => ['找一个“你负责推进”的位置', '把拆解任务当成你的核心能力展示'],
            'growth' => ['先写第一反应，再补一个备选', '给重要决定加 10 分钟冷却'],
            'relationships' => ['先共情再给建议', '用“我需要……”说需求，而不是指责'],
            default => ['先写第一反应，再补一个备选', '用清单降低临场消耗'],
        };

        return [
            'id'       => "{$section}_topic_fallback_1",
            'section'  => $section,
            'title'    => $title,
            'desc'     => $desc,
            'bullets'  => $bullets,
            'tips'     => $tips,
            'tags'     => ['fallback', 'kind:topic', "section:{$section}"],
            'priority' => 0,
            'match'    => null,
        ];
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