<?php

namespace App\Services\Report;

use App\Services\Content\ContentPack;
use App\Services\Rules\RuleEngine;
use Illuminate\Support\Facades\Log;

final class SectionCardGenerator
{
    /**
     * ✅ 新入口：从 pack chain + manifest.assets.cards 加载 cards
     *
     * @param string $section traits/career/growth/relationships
     * @param ContentPack[] $chain primary + fallback packs
     * @param array $userTags TagBuilder 输出
     * @param array $axisInfo 建议传 report.scores（含 delta/side/pct）；可选 axisInfo['attempt_id'] 用于稳定打散
     * @param string|null $legacyContentPackageDir 仅用于日志/兜底信息（不会读取旧路径）
     * @return array[] cards
     */
    public function generateFromPackChain(
        string $section,
        array $chain,
        array $userTags,
        array $axisInfo = [],
        ?string $legacyContentPackageDir = null
    ): array {
        $file = "report_cards_{$section}.json";

        $json = $this->loadJsonDocFromPackChain($chain, 'cards', $file);

        Log::info('[CARDS] loaded', [
            'section' => $section,
            'file'    => $file,
            'items'   => is_array($json['items'] ?? null) ? count($json['items']) : 0,
            'rules'   => $json['rules'] ?? null,
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        $items = is_array($json['items'] ?? null) ? $json['items'] : [];
        $rules = is_array($json['rules'] ?? null) ? $json['rules'] : [];

        // 规则：至少 2 张
        $minCards    = max(2, (int)($rules['min_cards'] ?? 2));
        $targetCards = (int)($rules['target_cards'] ?? 3);
        $maxCards    = (int)($rules['max_cards'] ?? 6);
        if ($targetCards < $minCards) $targetCards = $minCards;
        if ($maxCards < $targetCards) $maxCards = $targetCards;

        // 体验目标：axis 最多 2，且至少 1 张 non-axis（当 target>=3 时）
        $axisMax     = max(0, min(2, $targetCards - 1));
        $nonAxisMin  = ($targetCards >= 3) ? 1 : 0;

        $fallbackTags = $rules['fallback_tags'] ?? ['fallback', 'kind:core'];
        if (!is_array($fallbackTags)) $fallbackTags = ['fallback', 'kind:core'];

        // assets 不存在：直接返回兜底
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

        // seed
        $seed = $this->stableSeed($userSet, $axisInfo);

        // RuleEngine
        $re = app(RuleEngine::class);
        $ctx = "cards:{$section}";
        $debugRE = app()->environment('local', 'development') && (bool) env('FAP_RE_DEBUG', false);

        $evalById = [];
        $rejectedSamples = [];

        // normalize + evaluate + score
        $cands = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            $tags = is_array($it['tags'] ?? null) ? $it['tags'] : [];
            $tags = array_values(array_filter($tags, fn($x) => is_string($x) && trim($x) !== ''));

            $prio = (int)($it['priority'] ?? 0);

            $base = [
                'id'       => $id,
                'tags'     => $tags,
                'priority' => $prio,
                'rules'    => is_array($it['rules'] ?? null) ? $it['rules'] : [],
            ];

            $ev = $re->evaluate($base, $userSet, [
                'seed' => $seed,
                'ctx'  => $ctx,
                'debug' => $debugRE,
                'global_rules' => [],
            ]);

            $evalById[$id] = $ev;

            if (!$ev['ok']) {
                if ($debugRE && count($rejectedSamples) < 6) {
                    $rejectedSamples[] = [
                        'id' => $id,
                        'reason' => $ev['reason'],
                        'detail' => $ev['detail'] ?? null,
                        'hit' => $ev['hit'],
                        'priority' => $ev['priority'],
                        'min_match' => $ev['min_match'],
                        'score' => $ev['score'],
                    ];
                }
                continue;
            }

            // axis match 门槛
            if (!$this->passesAxisMatch($it, $userSet, $axisInfo)) {
                continue;
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

                '_hit'     => (int)($ev['hit'] ?? 0),
                '_score'   => (int)($ev['score'] ?? 0),
                '_min'     => (int)($ev['min_match'] ?? 0),
                '_is_axis' => $isAxis,
                '_shuffle' => (int)($ev['shuffle'] ?? 0),
            ];
        }

        // sort
        usort($cands, function ($a, $b) {
            $sa = (int)($a['_score'] ?? 0);
            $sb = (int)($b['_score'] ?? 0);
            if ($sa !== $sb) return $sb <=> $sa;

            $sha = (int)($a['_shuffle'] ?? 0);
            $shb = (int)($b['_shuffle'] ?? 0);
            if ($sha !== $shb) return $sha <=> $shb;

            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        $out  = [];
        $seen = [];

        $primary = array_slice($cands, 0, $targetCards);

        // 先保证 non-axis >= 1（当 target>=3）
        if ($nonAxisMin > 0) {
            foreach ($primary as $c) {
                if (count($out) >= $targetCards) break;
                if ((int)($c['_hit'] ?? 0) <= 0) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                $seen[$id] = true;

                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countNonAxis($out) >= $nonAxisMin) break;
            }
        }

        // 再填 hit>0
        foreach ($primary as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)($c['_hit'] ?? 0) <= 0) continue;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;

            if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

            $seen[$id] = true;
            unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
            $out[] = $c;
        }

        // 不足：补齐 non-axis
        if ($nonAxisMin > 0 && $this->countNonAxis($out) < $nonAxisMin) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countNonAxis($out) >= $nonAxisMin) break;
            }
        }

        // 不足：补齐 axis
        if ($this->countAxis($out) < $axisMax) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) !== true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countAxis($out) >= $axisMax) break;
            }
        }

        // fallback tags 补齐到 minCards
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;

                if (!$this->hasAnyTag($c['tags'] ?? [], $fallbackTags)) continue;
                if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }
        }

        // 仍不足：随便补到 minCards
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $minCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;

                if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }
        }

        if (count($out) < $minCards) {
            $out = array_merge($out, $this->fallbackCards($section, $minCards - count($out)));
        }

        $out = array_slice(array_values($out), 0, $maxCards);

        // RE explain
        $selectedExplains = [];
        if ($debugRE) {
            foreach ($out as $c) {
                $id = (string)($c['id'] ?? '');
                $ev = $evalById[$id] ?? null;
                if (is_array($ev)) {
                    $selectedExplains[] = [
                        'id' => $id,
                        'hit' => $ev['hit'],
                        'priority' => $ev['priority'],
                        'min_match' => $ev['min_match'],
                        'score' => $ev['score'],
                    ];
                }
            }
        }
        $re->explain($ctx, $selectedExplains, $rejectedSamples, ['debug' => $debugRE]);

        Log::info('[CARDS] selected', [
            'section' => $section,
            'ids'     => array_map(fn($x) => $x['id'] ?? null, $out),
        ]);

        return $out;
    }

    /**
     * ⚠️ 旧入口（建议只保留给兼容调用）；开启开关后，任何旧调用直接炸
     */
    public function generate(string $section, string $contentPackageVersion, array $userTags, array $axisInfo = []): array
    {
        if ((bool) env('FAP_FORBID_LEGACY_CARDS_LOADER', false)) {
            throw new \RuntimeException('LEGACY_SECTION_CARD_GENERATOR_USED: generate(section, contentPackageVersion, ...)');
        }

        // 兼容：不再读取旧路径，直接兜底卡，避免你以为“还在用旧体系”
        return $this->fallbackCards($section, 2);
    }

    private function loadJsonDocFromPackChain(array $chain, string $assetKey, string $wantedBasename): array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) continue;

            $paths = $this->flattenAssetPaths($p->assets()[$assetKey] ?? null);

            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;
                if (basename($rel) !== $wantedBasename) continue;

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) continue;

                $json = json_decode((string)file_get_contents($abs), true);
                if (is_array($json)) {
                    Log::info('[PACK] json_loaded', [
                        'asset'  => $assetKey,
                        'file'   => $wantedBasename,
                        'pack_id' => $p->packId(),
                        'version' => $p->version(),
                        'path'   => $abs,
                        'schema' => $json['schema'] ?? null,
                    ]);
                    return $json;
                }
            }
        }

        Log::warning('[PACK] json_not_found', [
            'asset' => $assetKey,
            'file'  => $wantedBasename,
        ]);

        return [];
    }

    private function flattenAssetPaths($assetVal): array
    {
        if (!is_array($assetVal)) return [];

        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn($x) => is_string($x) && trim($x) !== ''));
        }

        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') continue;
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') $out[] = $x;
            }
        }
        return array_values(array_unique($out));
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) === range(0, count($a) - 1);
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

        if (!isset($userSet["axis:{$dim}:{$side}"])) return false;

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
        $attemptId = $axisInfo['attempt_id'] ?? null;
        if (is_string($attemptId) && $attemptId !== '') {
            return $this->ucrc32($attemptId);
        }

        $tags = array_keys($userSet);
        sort($tags);

        $dims = ['EI','SN','TF','JP','AT'];
        $axes = [];
        foreach ($dims as $dim) {
            $v = (isset($axisInfo[$dim]) && is_array($axisInfo[$dim])) ? $axisInfo[$dim] : [];
            $side  = (string)($v['side'] ?? '');
            $delta = (int)($v['delta'] ?? 0);
            $pct   = (int)($v['pct'] ?? 0);
            $axes[] = "{$dim}:{$side}:{$delta}:{$pct}";
        }

        $payload = json_encode([$tags, $axes], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) $payload = '';

        return $this->ucrc32($payload);
    }

    private function ucrc32(string $s): int
    {
        $u = sprintf('%u', crc32($s));
        return (int)$u;
    }

    private function fallbackCards(string $section, int $need): array
    {
        $out = [];
        for ($i = 1; $i <= $need; $i++) {
            $out[] = [
                'id'       => "{$section}_fallback_{$i}",
                'section'  => $section,
                'title'    => 'General Tip',
                'desc'     => 'Content pack did not provide enough matched cards. Showing a safe fallback tip.',
                'bullets'  => ['Turn strengths into a repeatable template', 'Add one counter-check in key moments', 'Weekly review: keep what works, remove what doesn’t'],
                'tips'     => ['Write your first instinct, then add one alternative', 'Use checklists to reduce cognitive load'],
                'tags'     => ['fallback'],
                'priority' => 0,
                'match'    => null,
            ];
        }
        return $out;
    }
}