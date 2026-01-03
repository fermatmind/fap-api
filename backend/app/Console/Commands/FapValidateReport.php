<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Report\ReportComposer;

class FapValidateReport extends Command
{
    protected $signature = 'fap:validate-report {--attempt= : Attempt id} {--json= : Write report json to a file}';
    protected $description = 'Runtime validate report output (min_cards, meta/cards consistency, highlights assertions).';

    public function handle(): int
    {
        $attemptId = (string)($this->option('attempt') ?? '');
        if ($attemptId === '') {
            $this->error('Missing --attempt=...');
            return self::FAILURE;
        }

        $out = app(ReportComposer::class)->compose($attemptId, []);
        $report = is_array($out) ? ($out['report'] ?? $out) : [];

        if (!is_array($report) || $report === []) {
            $this->error('Report is empty or not array.');
            return self::FAILURE;
        }

        if ($path = $this->option('json')) {
            @file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("WROTE={$path}");
        }

        $failures = 0;

        // -------------------------
        // A) section cards assertions (existing)
        // -------------------------
        $sections = ['traits', 'career', 'growth', 'relationships'];

        foreach ($sections as $s) {
            $cards =
                $report['sections'][$s]['cards']
                ?? $report[$s]['cards']
                ?? $report['cards'][$s]
                ?? [];

            $cardsLen = is_array($cards) ? count($cards) : 0;

            $policyMin = $report['_meta']['sections'][$s]['assembler']['policy']['min_cards'] ?? null;
            $metaFinal = $report['_meta']['sections'][$s]['assembler']['counts']['final'] ?? null;

            // assert1: final >= min_cards
            $ok1 = is_numeric($policyMin) && is_numeric($metaFinal) && ((int)$metaFinal >= (int)$policyMin);

            // assert2: metaFinal == cards.length
            $ok2 = is_numeric($metaFinal) && ((int)$metaFinal === $cardsLen);

            $this->line(sprintf(
                '%s: cards=%d meta.final=%s policy.min=%s | assert1=%s assert2=%s',
                $s,
                $cardsLen,
                var_export($metaFinal, true),
                var_export($policyMin, true),
                $ok1 ? 'OK' : 'FAIL',
                $ok2 ? 'OK' : 'FAIL'
            ));

            if (!$ok1 || !$ok2) $failures++;
        }

        // -------------------------
        // B) highlights assertions ✅ NEW (compat both shapes)
        // -------------------------
        $high = $report['highlights']
            ?? ($report['sections']['highlights'] ?? null);

        if ($high === null) {
            $this->line("highlights: MISSING | FAIL");
            $failures++;
        } else {
            // compat:
            // - new shape: highlights is a list: [ {...}, {...} ]
            // - old/alt shape: highlights = { items: [ ... ], _meta: ... }
            $items = [];
            if (is_array($high)) {
                if (isset($high['items']) && is_array($high['items'])) {
                    $items = $high['items'];
                } elseif (function_exists('array_is_list') ? array_is_list($high) : (array_keys($high) === range(0, count($high) - 1))) {
                    $items = $high;
                }
            }

            // policy: try meta first; fallback to constraints; final fallback to defaults
            $policy = $report['_meta']['highlights']['assembler']['policy']
                ?? $report['_meta']['highlights']['policy']
                ?? null;

            if (!is_array($policy)) {
                $constraints = $report['_meta']['highlights']['finalize_meta']['constraints']
                    ?? $report['_meta']['highlights']['base_meta']['constraints']
                    ?? null;

                if (is_array($constraints)) {
                    $policy = [
                        'min_total'    => (int)($constraints['total_min'] ?? 3),
                        'max_total'    => (int)($constraints['total_max'] ?? 4),
                        'max_strength' => (int)($constraints['max_strength'] ?? 2),
                    ];
                }
            }

            if (!is_array($policy)) {
                $policy = ['min_total' => 3, 'max_total' => 4, 'max_strength' => 2];
            }

            $minTotal = (int)($policy['min_total'] ?? 3);
            $maxTotal = (int)($policy['max_total'] ?? 4);
            $maxStrength = (int)($policy['max_strength'] ?? 2);

            $total = is_array($items) ? count($items) : 0;

            $strengthCount = 0;
            $poolSet = [];

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $pool = (string)($it['pool'] ?? $it['kind'] ?? '');
                if ($pool !== '') $poolSet[$pool] = true;
                if ($pool === 'strength') $strengthCount++;
            }

            $okH1 = ($total >= $minTotal && $total <= $maxTotal);
            $okH2 = ($strengthCount <= $maxStrength);

            $this->line(sprintf(
                "highlights: total=%s in[%s,%s]=%s | strength.count=%s <= %s = %s",
                $total,
                $minTotal,
                $maxTotal,
                ($okH1 ? "OK" : "FAIL"),
                $strengthCount,
                $maxStrength,
                ($okH2 ? "OK" : "FAIL")
            ));

            if (!$okH1 || !$okH2) {
                $failures++;
            }

            // explain: require each item has explain string
            $missingExplain = 0;
            $checkedExplain = 0;
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $checkedExplain++;
                $ex = $it['explain'] ?? null;
                if (!is_string($ex) || trim($ex) === '') $missingExplain++;
            }
            $this->line(sprintf(
                "highlights.explain: checked=%d missing=%d | %s",
                $checkedExplain,
                $missingExplain,
                ($missingExplain === 0 ? "OK" : "FAIL")
            ));
            if ($missingExplain > 0) $failures++;

            // pools>=3 (strength/blindspot/action)
            $pools = array_keys($poolSet);
            $okH4 = (count($pools) >= 3);
            if (!empty($items)) {
                $this->line(
                    "highlights.pools>=1: " .
                    json_encode(array_slice($pools, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                    " | " . ($okH4 ? "OK" : "FAIL")
                );
                if (!$okH4) $failures++;
            } else {
                $this->line("highlights.pools>=1: SKIP (empty highlights)");
            }
        }

        // -------------------------
        // final
        // -------------------------
        if ($failures > 0) {
            $this->error("FAILED: {$failures} assertion group(s) violated.");
            return self::FAILURE;
        }

        $this->info('PASS: runtime report assertions OK');
        return self::SUCCESS;
    }
}