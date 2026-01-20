<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NormsUpdate extends Command
{
    protected $signature = 'norms:update {--pack_id= : norms pack_id (default: content_packs.default_pack_id)}';

    protected $description = 'Build norms table (global sample, last 365 days, rank_rule=leq)';

    public function handle(): int
    {
        $packId = trim((string) $this->option('pack_id'));
        if ($packId === '') {
            $packId = (string) config('content_packs.default_pack_id', '');
        }
        if ($packId === '') {
            $this->error('pack_id is required (option or config content_packs.default_pack_id)');
            return 1;
        }

        $windowEnd = now();
        $windowStart = now()->subDays(365);
        $metrics = ['EI', 'SN', 'TF', 'JP', 'AT'];

        $hasAxisStates = Schema::hasColumn('results', 'axis_states');
        $hasScoresJson = Schema::hasColumn('results', 'scores_json');
        $hasScoresPct = Schema::hasColumn('results', 'scores_pct');
        $hasAttemptResult = Schema::hasColumn('attempts', 'result_json');
        $hasComputedAt = Schema::hasColumn('results', 'computed_at');
        $hasCreatedAt = Schema::hasColumn('results', 'created_at');
        $hasIsValid = Schema::hasColumn('results', 'is_valid');
        $hasContentPackageVersion = Schema::hasColumn('results', 'content_package_version');

        if (!$hasAxisStates && !$hasScoresJson && !$hasAttemptResult && !$hasScoresPct) {
            $this->warn('No usable score source columns found.');
            return 0;
        }

        $packVersion = $this->packIdToVersion($packId);

        $query = DB::table('results as r');
        if ($hasAttemptResult) {
            $query->leftJoin('attempts as a', 'a.id', '=', 'r.attempt_id');
        }

        $select = ['r.id', 'r.attempt_id'];
        if ($hasAxisStates) {
            $select[] = 'r.axis_states';
        }
        if ($hasScoresJson) {
            $select[] = 'r.scores_json';
        }
        if ($hasScoresPct) {
            $select[] = 'r.scores_pct';
        }
        if ($hasAttemptResult) {
            $select[] = 'a.result_json as attempt_result_json';
        }
        if ($hasComputedAt) {
            $select[] = 'r.computed_at';
        }
        if ($hasCreatedAt) {
            $select[] = 'r.created_at';
        }
        $query->select($select);

        if ($hasComputedAt) {
            $query->whereBetween('r.computed_at', [$windowStart, $windowEnd]);
        } elseif ($hasCreatedAt) {
            $query->whereBetween('r.created_at', [$windowStart, $windowEnd]);
        }

        if ($hasIsValid) {
            $query->where('r.is_valid', true);
        }

        if ($hasContentPackageVersion && $packVersion !== '') {
            $query->where('r.content_package_version', $packVersion);
        }

        $sampleN = 0;
        $scoresByMetric = [
            'EI' => [],
            'SN' => [],
            'TF' => [],
            'JP' => [],
            'AT' => [],
        ];

        foreach ($query->cursor() as $row) {
            $scores = $this->extractScoresFromRow(
                $row,
                $metrics,
                $hasAxisStates,
                $hasScoresJson,
                $hasScoresPct,
                $hasAttemptResult
            );
            if (!$scores) {
                continue;
            }

            $sampleN++;
            foreach ($metrics as $metric) {
                $scoresByMetric[$metric][] = (int) round((float) $scores[$metric]);
            }
        }

        if ($sampleN < 200) {
            $this->info("Sample size {$sampleN} < 200, keep active version unchanged.");
            return 0;
        }

        $versionId = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use (
            $versionId,
            $packId,
            $windowStart,
            $windowEnd,
            $sampleN,
            $now,
            $scoresByMetric,
            $metrics
        ) {
            DB::table('norms_versions')->insert([
                'id' => $versionId,
                'pack_id' => $packId,
                'window_start_at' => $windowStart,
                'window_end_at' => $windowEnd,
                'sample_n' => $sampleN,
                'rank_rule' => 'leq',
                'status' => 'active',
                'computed_at' => $now,
                'created_at' => $now,
            ]);

            $rows = [];
            foreach ($metrics as $metric) {
                $counts = array_count_values($scoresByMetric[$metric]);
                ksort($counts, SORT_NUMERIC);

                $leq = 0;
                foreach ($counts as $scoreInt => $count) {
                    $leq += (int) $count;
                    $rows[] = [
                        'norms_version_id' => $versionId,
                        'metric_key' => $metric,
                        'score_int' => (int) $scoreInt,
                        'leq_count' => $leq,
                        'percentile' => $leq / $sampleN,
                        'created_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('norms_table')->insert($chunk);
            }

            DB::table('norms_versions')
                ->where('pack_id', $packId)
                ->where('status', 'active')
                ->where('id', '!=', $versionId)
                ->update(['status' => 'archived']);
        });

        $this->info("Norms updated: pack_id={$packId} version_id={$versionId} N={$sampleN}");

        return 0;
    }

    private function extractScoresFromRow(
        object $row,
        array $metrics,
        bool $hasAxisStates,
        bool $hasScoresJson,
        bool $hasScoresPct,
        bool $hasAttemptResult
    ): ?array {
        $axisStates = $hasAxisStates ? $this->decodeJson($row->axis_states ?? null) : null;
        $scoresJson = $hasScoresJson ? $this->decodeJson($row->scores_json ?? null) : null;
        $scoresPct = $hasScoresPct ? $this->decodeJson($row->scores_pct ?? null) : null;
        $attemptResult = $hasAttemptResult ? $this->decodeJson($row->attempt_result_json ?? null) : null;

        $out = [];
        foreach ($metrics as $metric) {
            $score = null;

            if (is_array($axisStates)) {
                $score = $this->extractNumericScore($axisStates[$metric] ?? null);
            }
            if ($score === null) {
                $score = $this->scoreFromScoresJson($scoresJson, $scoresPct, $metric);
            }
            if ($score === null && is_array($attemptResult)) {
                $score = $this->scoreFromReportJson($attemptResult, $metric);
            }
            if ($score === null) {
                return null;
            }

            $out[$metric] = $score;
        }

        return $out;
    }

    private function scoreFromScoresJson(?array $scoresJson, ?array $scoresPct, string $metric): ?float
    {
        if (is_array($scoresJson) && array_key_exists($metric, $scoresJson)) {
            $val = $scoresJson[$metric];
            $score = $this->extractNumericScore($val);
            if ($score !== null) {
                return $score;
            }

            if (is_array($val)) {
                $sum = $this->toNumber($val['sum'] ?? null);
                $total = $this->toNumber($val['total'] ?? null);
                if ($sum !== null && $total !== null && $total > 0) {
                    $pct = (($sum + 2 * $total) / (4 * $total)) * 100;
                    return max(0.0, min(100.0, $pct));
                }
            }
        }

        if (is_array($scoresPct) && array_key_exists($metric, $scoresPct)) {
            $score = $this->extractNumericScore($scoresPct[$metric] ?? null);
            if ($score !== null) {
                return $score;
            }
        }

        return null;
    }

    private function scoreFromReportJson(array $reportJson, string $metric): ?float
    {
        $scoresPct = $this->decodeJson($reportJson['scores_pct'] ?? null) ?? [];
        $scores = $this->decodeJson($reportJson['scores'] ?? null);
        if (!is_array($scores)) {
            $scores = $this->decodeJson($reportJson['scores_json'] ?? null);
        }

        return $this->scoreFromScoresJson(is_array($scores) ? $scores : [], $scoresPct, $metric);
    }

    private function extractNumericScore(mixed $val): ?float
    {
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }
        if (is_string($val) && is_numeric($val)) {
            return (float) $val;
        }
        if (is_array($val)) {
            if (array_key_exists('score', $val)) {
                return $this->extractNumericScore($val['score']);
            }
            if (array_key_exists('pct', $val)) {
                return $this->extractNumericScore($val['pct']);
            }
        }

        return null;
    }

    private function toNumber(mixed $val): ?float
    {
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }
        if (is_string($val) && is_numeric($val)) {
            return (float) $val;
        }
        return null;
    }

    private function decodeJson(mixed $val): ?array
    {
        if (is_array($val)) {
            return $val;
        }
        if (is_object($val)) {
            return (array) $val;
        }
        if (is_string($val) && $val !== '') {
            $decoded = json_decode($val, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function packIdToVersion(string $packId): string
    {
        $s = trim($packId);
        if ($s === '') {
            return '';
        }

        $parts = explode('.', $s);
        if (count($parts) < 4) {
            return '';
        }

        return implode('.', array_slice($parts, 3));
    }
}
