<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SdsPsychometricsReport extends Command
{
    /**
     * @var list<string>
     */
    private const FACTORS = ['psycho_affective', 'somatic', 'psychomotor', 'cognitive'];

    protected $signature = 'sds:psychometrics
        {--scale=SDS_20 : Scale code}
        {--norms_version=latest : Target norms version label}
        {--locale=zh-CN : Locale filter}
        {--region=CN_MAINLAND : Region filter, use * for all}
        {--window=last_90_days : Time window, e.g. last_90_days}
        {--only_quality=AB : Accepted quality levels}
        {--min_samples= : Minimum sample count (defaults from config)}';

    protected $description = 'Generate SDS_20 psychometrics report and store metrics_json snapshot.';

    public function handle(): int
    {
        if (! Schema::hasTable('sds_psychometrics_reports')) {
            $this->error('Missing table sds_psychometrics_reports. Run migrations first.');

            return 1;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        $locale = $this->normalizeLocale((string) $this->option('locale'));
        $region = strtoupper(trim((string) $this->option('region')));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }

        $window = trim((string) $this->option('window'));
        $windowDays = $this->resolveWindowDays($window);
        $qualityLevels = $this->resolveQualityLevels((string) $this->option('only_quality'));
        $minSamples = $this->resolveMinSamples($this->option('min_samples'));
        $normsVersion = $this->resolveNormsVersion($scale, $locale, $region, (string) $this->option('norms_version'));

        $query = DB::table('attempts as a')
            ->join('results as r', 'r.attempt_id', '=', 'a.id')
            ->where('a.scale_code', $scale)
            ->whereNotNull('a.submitted_at')
            ->where('a.submitted_at', '>=', now()->subDays($windowDays))
            ->where('a.locale', $locale)
            ->select(['r.result_json', 'a.region']);

        if ($region !== '*') {
            $query->where('a.region', $region);
        }

        $totalQualityRecords = 0;
        $qualityCWorseCount = 0;
        $sampleN = 0;
        $crisisCount = 0;
        $bucketCounts = [];
        $indexStats = ['n' => 0, 'sum' => 0.0, 'sum_sq' => 0.0];
        $factorStats = $this->initStats(self::FACTORS);

        foreach ($query->cursor() as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            if ($payload === []) {
                continue;
            }

            $quality = strtoupper((string) (
                data_get($payload, 'quality.level')
                ?? data_get($payload, 'normed_json.quality.level')
                ?? ''
            ));
            if (! in_array($quality, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }

            $indexScore = data_get($payload, 'scores.global.index_score');
            if (! is_numeric($indexScore)) {
                $indexScore = data_get($payload, 'normed_json.scores.global.index_score');
            }
            if (! is_numeric($indexScore)) {
                continue;
            }

            $totalQualityRecords++;
            if (in_array($quality, ['C', 'D'], true)) {
                $qualityCWorseCount++;
            }

            if (! in_array($quality, $qualityLevels, true)) {
                continue;
            }

            $sampleN++;
            $this->pushStat($indexStats, (float) $indexScore);

            $crisisAlert = (bool) (
                data_get($payload, 'quality.crisis_alert')
                ?? data_get($payload, 'normed_json.quality.crisis_alert')
                ?? false
            );
            if ($crisisAlert) {
                $crisisCount++;
            }

            $clinicalLevel = trim((string) (
                data_get($payload, 'scores.global.clinical_level')
                ?? data_get($payload, 'normed_json.scores.global.clinical_level')
                ?? ''
            ));
            $bucket = strtolower($clinicalLevel);
            if ($bucket === '') {
                $bucket = 'unknown';
            }
            $bucketCounts[$bucket] = (int) ($bucketCounts[$bucket] ?? 0) + 1;

            foreach (self::FACTORS as $factor) {
                $score = data_get($payload, 'scores.factors.'.$factor.'.score');
                if (! is_numeric($score)) {
                    $score = data_get($payload, 'normed_json.scores.factors.'.$factor.'.score');
                }
                if (is_numeric($score)) {
                    $this->pushStat($factorStats[$factor], (float) $score);
                }
            }
        }

        if ($sampleN < $minSamples) {
            $this->warn(sprintf(
                'insufficient samples: got=%d required=%d scale=%s locale=%s window=%s',
                $sampleN,
                $minSamples,
                $scale,
                $locale,
                $window
            ));

            return 2;
        }

        $metrics = $this->buildMetrics(
            $sampleN,
            $windowDays,
            $qualityLevels,
            $totalQualityRecords,
            $qualityCWorseCount,
            $indexStats,
            $crisisCount,
            $bucketCounts,
            $factorStats
        );
        $now = now();

        DB::table('sds_psychometrics_reports')->insert([
            'id' => (string) Str::uuid(),
            'scale_code' => $scale,
            'locale' => $locale,
            'region' => ($region === '*') ? null : $region,
            'norms_version' => $normsVersion,
            'time_window' => $window,
            'sample_n' => $sampleN,
            'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->info(sprintf(
            'OK: psychometrics report generated scale=%s locale=%s sample_n=%d norms=%s',
            $scale,
            $locale,
            $sampleN,
            $normsVersion
        ));

        return 0;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'zh-CN';
        }

        $locale = str_replace('_', '-', $locale);
        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            if (strtolower($parts[0]) === 'zh') {
                return 'zh-CN';
            }

            return strtolower($parts[0]);
        }

        return strtolower($parts[0]).'-'.strtoupper($parts[1]);
    }

    private function resolveWindowDays(string $window): int
    {
        if (preg_match('/^last_(\d+)_days$/', $window, $m) === 1) {
            return max(1, (int) ($m[1] ?? 90));
        }

        if (is_numeric($window)) {
            return max(1, (int) $window);
        }

        return max(1, (int) config('sds_norms.psychometrics.window_days_default', 90));
    }

    /**
     * @return list<string>
     */
    private function resolveQualityLevels(string $onlyQuality): array
    {
        $normalized = strtoupper(trim($onlyQuality));
        if ($normalized === '') {
            return ['A', 'B'];
        }

        if (str_contains($normalized, ',')) {
            $parts = array_map('trim', explode(',', $normalized));
        } else {
            $parts = str_split($normalized);
        }

        $levels = [];
        foreach ($parts as $part) {
            $candidate = strtoupper(trim((string) $part));
            if (in_array($candidate, ['A', 'B', 'C', 'D'], true) && ! in_array($candidate, $levels, true)) {
                $levels[] = $candidate;
            }
        }

        return $levels === [] ? ['A', 'B'] : $levels;
    }

    private function resolveMinSamples(mixed $option): int
    {
        if ($option !== null && trim((string) $option) !== '') {
            return max(1, (int) $option);
        }

        return max(1, (int) config('sds_norms.psychometrics.min_samples', 100));
    }

    private function resolveNormsVersion(string $scale, string $locale, string $region, string $normsVersionOpt): string
    {
        $opt = trim($normsVersionOpt);
        if ($opt !== '' && strtolower($opt) !== 'latest') {
            return $opt;
        }

        $query = DB::table('scale_norms_versions')
            ->where('scale_code', $scale)
            ->where('locale', $locale)
            ->where('is_active', 1);

        if ($region !== '*' && $region !== '') {
            $query->whereIn('region', [$region, 'GLOBAL']);
        }

        $version = $query
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->value('version');

        return is_string($version) && trim($version) !== '' ? $version : 'unknown';
    }

    /**
     * @param  list<string>  $qualityLevels
     * @param  array{n:int,sum:float,sum_sq:float}  $indexStats
     * @param  array<string,int>  $bucketCounts
     * @param  array<string,array{n:int,sum:float,sum_sq:float}>  $factorStats
     * @return array<string,mixed>
     */
    private function buildMetrics(
        int $sampleN,
        int $windowDays,
        array $qualityLevels,
        int $totalQualityRecords,
        int $qualityCWorseCount,
        array $indexStats,
        int $crisisCount,
        array $bucketCounts,
        array $factorStats
    ): array {
        $distribution = [];
        foreach ($bucketCounts as $bucket => $count) {
            $distribution[$bucket] = [
                'count' => $count,
                'ratio' => $sampleN > 0 ? round($count / $sampleN, 4) : 0.0,
            ];
        }
        ksort($distribution);

        $factorSummary = [];
        foreach (self::FACTORS as $factor) {
            $factorSummary[$factor] = [
                'mean' => round($this->statMean($factorStats[$factor]), 4),
                'sd' => round($this->statPopulationSd($factorStats[$factor]), 4),
            ];
        }

        $qualityCWorseRate = $totalQualityRecords > 0
            ? round($qualityCWorseCount / $totalQualityRecords, 4)
            : 0.0;

        return [
            'sample_n' => $sampleN,
            'window_days' => $windowDays,
            'quality_filter' => array_values($qualityLevels),
            'index_score_mean' => round($this->statMean($indexStats), 4),
            'index_score_sd' => round($this->statPopulationSd($indexStats), 4),
            'crisis_rate' => $sampleN > 0 ? round($crisisCount / $sampleN, 4) : 0.0,
            'quality_c_or_worse_rate' => $qualityCWorseRate,
            'clinical_bucket_distribution' => $distribution,
            'factor_distribution_summary' => $factorSummary,
            'thresholds' => (array) config('sds_norms.psychometrics.thresholds', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeResultJson(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  list<string>  $codes
     * @return array<string,array{n:int,sum:float,sum_sq:float}>
     */
    private function initStats(array $codes): array
    {
        $stats = [];
        foreach ($codes as $code) {
            $stats[$code] = ['n' => 0, 'sum' => 0.0, 'sum_sq' => 0.0];
        }

        return $stats;
    }

    /**
     * @param  array{n:int,sum:float,sum_sq:float}  $stat
     */
    private function pushStat(array &$stat, float $value): void
    {
        $stat['n']++;
        $stat['sum'] += $value;
        $stat['sum_sq'] += $value * $value;
    }

    /**
     * @param  array{n:int,sum:float,sum_sq:float}  $stat
     */
    private function statMean(array $stat): float
    {
        if ($stat['n'] <= 0) {
            return 0.0;
        }

        return $stat['sum'] / $stat['n'];
    }

    /**
     * @param  array{n:int,sum:float,sum_sq:float}  $stat
     */
    private function statPopulationSd(array $stat): float
    {
        if ($stat['n'] <= 1) {
            return 0.0;
        }

        $mean = $stat['sum'] / $stat['n'];
        $variance = ($stat['sum_sq'] / $stat['n']) - ($mean * $mean);
        if ($variance < 0.0 && abs($variance) < 1e-12) {
            return 0.0;
        }

        return $variance > 0.0 ? sqrt($variance) : 0.0;
    }
}
