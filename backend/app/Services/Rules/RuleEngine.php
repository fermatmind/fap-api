<?php

namespace App\Services\Rules;

use Illuminate\Support\Facades\Log;

final class RuleEngine
{
    /**
     * 单条评估
     *
     * @param array $item  至少包含：id,tags,priority；规则可在 item.rules 或顶层字段里
     * @param array $userSet  形如 ['role:SJ'=>true,'axis:EI:E'=>true,...]
     * @param array $opt  ['seed'=>123,'ctx'=>'cards:traits','debug'=>true,'global_rules'=>[...] ]
     * @return array 评估结果：ok/hit/priority/min_match/score/reason/detail/shuffle
     */
    public function evaluate(array $item, array $userSet, array $opt = []): array
    {
        $id = (string)($item['id'] ?? '');

        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        $tags = array_values(array_filter($tags, fn ($x) => is_string($x) && trim($x) !== ''));

        $priority = (int)($item['priority'] ?? 0);

        // ✅ 先取 global_rules（避免先用后定义）
        $globalRules = is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [];

        // item.rules（嵌套规则）
        $itemRules = is_array($item['rules'] ?? null) ? $item['rules'] : [];

        // 顶层扁平规则（兼容 cards/highlights/reads 直接塞在 item 上）
        $flatRules = [];
        foreach (['require_all', 'require_any', 'forbid'] as $k) {
            if (is_array($item[$k] ?? null)) {
                $flatRules[$k] = $item[$k];
            }
        }
        if (array_key_exists('min_match', $item)) {
            $flatRules['min_match'] = (int)$item['min_match'];
        }

        // ✅ 优先级：global -> flat -> rules（后者覆盖/补充前者）
        $rules = $this->mergeRules(
            $this->mergeRules($globalRules, $flatRules),
            $itemRules
        );

        // hitCount：tags 与 userSet 的交集数
        $hit = 0;
        foreach ($tags as $t) {
            if (isset($userSet[$t])) $hit++;
        }

        // 规则校验
        [$ok, $reason, $detail] = $this->passesRules($rules, $userSet, $hit);

        // score（最小版）
        $score = $priority + 10 * $hit;

        return [
            'id'        => $id,
            'ok'        => (bool)$ok,
            'reason'    => (string)$reason,
            'detail'    => is_array($detail) ? $detail : [],
            'hit'       => (int)$hit,
            'priority'  => (int)$priority,
            'min_match' => (int)($rules['min_match'] ?? 0),
            'score'     => (int)$score,
            'shuffle'   => $this->stableShuffleKey((int)($opt['seed'] ?? 0), $id),
        ];
    }

    /**
     * 批量选择：filter(ok) + sort(score desc, shuffle asc) + take(max_items)
     *
     * @param array $items
     * @param array $userSet  ['tag'=>true,...]
     * @param array $opt ['ctx'=>'reads:by_role','debug'=>true,'seed'=>123,'max_items'=>3,'rejected_samples'=>5,'global_rules'=>[...] ]
     * @return array [$selectedItems, $evaluations]
     */
    public function select(array $items, array $userSet, array $opt = []): array
    {
        $ctx   = (string)($opt['ctx'] ?? 're');
        $seed  = (int)($opt['seed'] ?? 0);
        $max   = array_key_exists('max_items', $opt) ? (int)$opt['max_items'] : count($items);
        $rejN  = array_key_exists('rejected_samples', $opt) ? (int)$opt['rejected_samples'] : 5;
        $debug = (bool)($opt['debug'] ?? false);

        $globalRules = is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [];

        if ($max < 0) $max = 0;
        if ($rejN < 0) $rejN = 0;

        $evals    = [];
        $oks      = [];
        $rejected = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $e = $this->evaluate($it, $userSet, [
                'seed'         => $seed,
                'global_rules' => $globalRules,
            ] + $opt);

            $evals[] = $e;

            if ($e['ok'] ?? false) {
                $it['_re'] = $e;
                $oks[] = $it;
            } else {
                $rejected[] = $e;
            }
        }

        // sort: score desc, shuffle asc（稳定）
        usort($oks, function ($a, $b) {
            $ea = $a['_re'] ?? [];
            $eb = $b['_re'] ?? [];

            $sa = (int)($ea['score'] ?? 0);
            $sb = (int)($eb['score'] ?? 0);
            if ($sa !== $sb) return $sb <=> $sa;

            $ha = (int)($ea['shuffle'] ?? 0);
            $hb = (int)($eb['shuffle'] ?? 0);
            return $ha <=> $hb;
        });

        $selected = array_slice($oks, 0, $max);

        // explain payload（只输出必要字段）
        $selectedExplains = array_map(function ($it) {
            $e = $it['_re'] ?? [];
            return [
                'id'        => (string)($e['id'] ?? ($it['id'] ?? '')),
                'hit'       => (int)($e['hit'] ?? 0),
                'priority'  => (int)($e['priority'] ?? 0),
                'min_match' => (int)($e['min_match'] ?? 0),
                'score'     => (int)($e['score'] ?? 0),
            ];
        }, $selected);

        $rejectedSamples = array_slice(array_map(function ($e) {
            return [
                'id'        => (string)($e['id'] ?? ''),
                'reason'    => (string)($e['reason'] ?? ''),
                'detail'    => is_array($e['detail'] ?? null) ? $e['detail'] : [],
                'hit'       => (int)($e['hit'] ?? 0),
                'priority'  => (int)($e['priority'] ?? 0),
                'min_match' => (int)($e['min_match'] ?? 0),
                'score'     => (int)($e['score'] ?? 0),
            ];
        }, $rejected), 0, $rejN);

        $this->explain($ctx, $selectedExplains, $rejectedSamples, [
            'debug' => $debug,
            'seed'  => $seed,
        ] + $opt);

        return [$selected, $evals];
    }

    public function explain(string $ctx, array $selectedExplains, array $rejectedSamples = [], array $opt = []): void
    {
        // ✅ 统一开关：local + env(RE_EXPLAIN=1) 或 opt.debug=true
        if (!app()->environment('local')) return;

        $debug = (bool)($opt['debug'] ?? false);
        $envOn = (bool) env('RE_EXPLAIN', false);

        if (!$debug && !$envOn) return;

        Log::debug('[RE] explain', [
            'ctx'             => $ctx,
            'seed'            => $opt['seed'] ?? null,
            'selected'        => $selectedExplains,
            'rejected_samples'=> $rejectedSamples,
        ]);
    }

    private function mergeRules(array $global, array $item): array
    {
        $out = [];

        foreach (['require_all', 'require_any', 'forbid'] as $k) {
            $ga = is_array($global[$k] ?? null) ? $global[$k] : [];
            $ia = is_array($item[$k] ?? null) ? $item[$k] : [];
            $out[$k] = array_values(array_unique(array_filter(
                array_merge($ga, $ia),
                fn ($x) => is_string($x) && $x !== ''
            )));
        }

        $out['min_match'] = max(
            (int)($global['min_match'] ?? 0),
            (int)($item['min_match'] ?? 0)
        );

        return $out;
    }

    /**
     * @return array [ok(bool), reason(string), detail(array)]
     */
    private function passesRules(array $rules, array $userSet, int $hit): array
    {
        $reqAll   = is_array($rules['require_all'] ?? null) ? $rules['require_all'] : [];
        $reqAny   = is_array($rules['require_any'] ?? null) ? $rules['require_any'] : [];
        $forbid   = is_array($rules['forbid'] ?? null) ? $rules['forbid'] : [];
        $minMatch = (int)($rules['min_match'] ?? 0);

        // forbid：命中即排除
        $forbidHit = [];
        foreach ($forbid as $t) {
            if (isset($userSet[$t])) $forbidHit[] = $t;
        }
        if (!empty($forbidHit)) {
            return [false, 'forbid_hit', ['hit' => $forbidHit]];
        }

        // require_all：全部命中
        $missing = [];
        foreach ($reqAll as $t) {
            if (!isset($userSet[$t])) $missing[] = $t;
        }
        if (!empty($missing)) {
            return [false, 'require_all_missing', ['missing' => $missing]];
        }

        // require_any：至少命中一个
        if (!empty($reqAny)) {
            $okAny = false;
            foreach ($reqAny as $t) {
                if (isset($userSet[$t])) { $okAny = true; break; }
            }
            if (!$okAny) {
                return [false, 'require_any_miss', ['need_any' => $reqAny]];
            }
        }

        // min_match：hitCount 阈值
        if ($hit < $minMatch) {
            return [false, 'min_match_fail', ['hit' => $hit, 'need' => $minMatch]];
        }

        return [true, 'ok', []];
    }

    private function stableShuffleKey(int $seed, string $id): int
    {
        $u = sprintf('%u', crc32($seed . '|' . $id));
        return (int)((int)$u & 0x7fffffff);
    }
}