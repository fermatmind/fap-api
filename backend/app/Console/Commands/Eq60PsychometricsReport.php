<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class Eq60PsychometricsReport extends Command
{
    /**
     * @var list<string>
     */
    private const DIMENSIONS = ['SA', 'ER', 'EM', 'RM'];

    /**
     * @var list<string>
     */
    private const QUALITY_FLAGS = [
        'SPEEDING',
        'LONGSTRING',
        'EXTREME_RESPONSE_BIAS',
        'NEUTRAL_RESPONSE_BIAS',
        'INCONSISTENT',
    ];

    protected $signature = 'eq60:psychometrics
        {--scale=EQ_60 : Scale code}
        {--norms_version=latest : Target norms version label}
        {--locale=zh-CN : Locale filter}
        {--region=CN_MAINLAND : Region filter, use * for all}
        {--window=last_90_days : Time window, e.g. last_90_days}
        {--only_quality=AB : Accepted quality levels}
        {--min_samples= : Minimum sample count (defaults from config)}';

    protected $description = 'Generate EQ_60 psychometrics report and store metrics_json snapshot.';

    public function handle(): int
    {
        if (! Schema::hasTable('eq60_psychometrics_reports')) {
            $this->error('Missing table eq60_psychometrics_reports. Run migrations first.');

            return 1;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        if ($scale === '') {
            $scale = 'EQ_60';
        }

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

        $rows = $query->get();
        $records = [];
        $totalQualityRecords = 0;
        $qualityCWorseCount = 0;

        foreach ($rows as $row) {
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

            $globalStd = data_get($payload, 'scores.global.std_score');
            if (! is_numeric($globalStd)) {
                $globalStd = data_get($payload, 'normed_json.scores.global.std_score');
            }
            if (! is_numeric($globalStd)) {
                continue;
            }

            $totalQualityRecords++;
            if (in_array($quality, ['C', 'D'], true)) {
                $qualityCWorseCount++;
            }

            if (! in_array($quality, $qualityLevels, true)) {
                continue;
            }

            $dimensionStd = [];
            foreach (self::DIMENSIONS as $dimension) {
                $value = data_get($payload, 'scores.'.$dimension.'.std_score');
                if (! is_numeric($value)) {
                    $value = data_get($payload, 'normed_json.scores.'.$dimension.'.std_score');
                }
                if (is_numeric($value)) {
                    $dimensionStd[$dimension] = (float) $value;
                }
            }

            $flags = data_get($payload, 'quality.flags');
            if (! is_array($flags)) {
                $flags = data_get($payload, 'normed_json.quality.flags');
            }
            $normalizedFlags = [];
            foreach ((array) $flags as $flag) {
                $norm = strtoupper(trim((string) $flag));
                if ($norm !== '') {
                    $normalizedFlags[] = $norm;
                }
            }

            $tags = data_get($payload, 'report_tags');
            if (! is_array($tags)) {
                $tags = data_get($payload, 'normed_json.report_tags');
            }
            $normalizedTags = [];
            foreach ((array) $tags as $tag) {
                $norm = trim((string) $tag);
                if ($norm !== '') {
                    $normalizedTags[] = $norm;
                }
            }

            $records[] = [
                'global_std' => (float) $globalStd,
                'dimension_std' => $dimensionStd,
                'flags' => $normalizedFlags,
                'tags' => $normalizedTags,
            ];
        }

        $sampleN = count($records);
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

        $metrics = $this->buildMetrics($records, $windowDays, $qualityLevels, $totalQualityRecords, $qualityCWorseCount);
        $now = now();

        DB::table('eq60_psychometrics_reports')->insert([
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

        return max(1, (int) config('eq60_norms.psychometrics.window_days_default', 90));
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

        return max(1, (int) config('eq60_norms.psychometrics.min_samples', 100));
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
     * @param  list<array{
     *   global_std:float,
     *   dimension_std:array<string,float>,
     *   flags:list<string>,
     *   tags:list<string>
     * }>  $records
     * @param  list<string>  $qualityLevels
     * @return array<string,mixed>
     */
    private function buildMetrics(array $records, int $windowDays, array $qualityLevels, int $totalQualityRecords, int $qualityCWorseCount): array
    {
        $globalSeries = [];
        $dimSeries = [];
        foreach (self::DIMENSIONS as $dimension) {
            $dimSeries[$dimension] = [];
        }

        $flagCount = array_fill_keys(self::QUALITY_FLAGS, 0);
        $tagCount = [];
        foreach ($records as $row) {
            $globalSeries[] = (float) $row['global_std'];

            $dimensionStd = (array) ($row['dimension_std'] ?? []);
            foreach (self::DIMENSIONS as $dimension) {
                if (isset($dimensionStd[$dimension]) && is_numeric($dimensionStd[$dimension])) {
                    $dimSeries[$dimension][] = (float) $dimensionStd[$dimension];
                }
            }

            $flags = array_values(array_unique((array) ($row['flags'] ?? [])));
            foreach ($flags as $flag) {
                if (isset($flagCount[$flag])) {
                    $flagCount[$flag]++;
                }
            }

            foreach ((array) ($row['tags'] ?? []) as $tag) {
                $tagCount[$tag] = (int) ($tagCount[$tag] ?? 0) + 1;
            }
        }

        $dimSummary = [];
        foreach (self::DIMENSIONS as $dimension) {
            $series = $dimSeries[$dimension];
            $dimSummary[$dimension] = [
                'mean' => round($this->mean($series), 4),
                'sd' => round($this->populationSd($series), 4),
            ];
        }

        $sampleN = count($records);
        $flagRates = [];
        foreach ($flagCount as $flag => $count) {
            $flagRates[$flag] = $sampleN > 0 ? round($count / $sampleN, 4) : 0.0;
        }
        ksort($flagRates);

        arsort($tagCount);
        $topTags = array_slice($tagCount, 0, 10, true);

        $qualityCWorseRate = $totalQualityRecords > 0
            ? round($qualityCWorseCount / $totalQualityRecords, 4)
            : 0.0;

        return [
            'sample_n' => $sampleN,
            'window_days' => $windowDays,
            'quality_filter' => array_values($qualityLevels),
            'global_std_mean' => round($this->mean($globalSeries), 4),
            'global_std_sd' => round($this->populationSd($globalSeries), 4),
            'dimension_std_summary' => $dimSummary,
            'quality_c_or_worse_rate' => $qualityCWorseRate,
            'quality_flag_rates' => $flagRates,
            'top_report_tags' => $topTags,
            'thresholds' => (array) config('eq60_norms.psychometrics.thresholds', []),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  list<float>  $values
     */
    private function populationSd(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sumSq = 0.0;
        foreach ($values as $value) {
            $delta = $value - $mean;
            $sumSq += ($delta * $delta);
        }

        return sqrt($sumSq / count($values));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeResultJson(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
