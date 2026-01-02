<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Report\ReportComposer;

class FapValidateReport extends Command
{
    protected $signature = 'fap:validate-report {--attempt= : Attempt id} {--json= : Write report json to a file}';
    protected $description = 'Runtime validate report output (min_cards, meta/cards consistency).';

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
            @file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $this->info("WROTE={$path}");
        }

        $sections = ['traits','career','growth','relationships'];
        $failures = 0;

        foreach ($sections as $s) {
            $cards =
                $report['sections'][$s]['cards']
                ?? $report[$s]['cards']
                ?? $report['cards'][$s]
                ?? [];

            $cardsLen = is_array($cards) ? count($cards) : 0;

            $policyMin = $report['_meta']['sections'][$s]['assembler']['policy']['min_cards'] ?? null;
            $metaFinal = $report['_meta']['sections'][$s]['assembler']['counts']['final'] ?? null;

            // 断言 1: final >= min_cards
            $ok1 = is_numeric($policyMin) && is_numeric($metaFinal) && ((int)$metaFinal >= (int)$policyMin);

            // 断言 2: metaFinal == cards.length
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

        if ($failures > 0) {
            $this->error("FAILED: {$failures} section(s) violated assertions.");
            return self::FAILURE;
        }

        $this->info('PASS: runtime report assertions OK');
        return self::SUCCESS;
    }
}