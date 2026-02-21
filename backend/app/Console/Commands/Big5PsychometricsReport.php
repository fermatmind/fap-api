<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Big5PsychometricsReport extends Command
{
    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const DOMAIN_TO_FACETS = [
        'O' => ['O1', 'O2', 'O3', 'O4', 'O5', 'O6'],
        'C' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'],
        'E' => ['E1', 'E2', 'E3', 'E4', 'E5', 'E6'],
        'A' => ['A1', 'A2', 'A3', 'A4', 'A5', 'A6'],
        'N' => ['N1', 'N2', 'N3', 'N4', 'N5', 'N6'],
    ];

    /**
     * @var list<string>
     */
    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    protected $signature = 'big5:psychometrics
        {--scale=BIG5_OCEAN : Scale code}
        {--norms_version=latest : Target norms version label}
        {--locale=zh-CN : Locale filter}
        {--region=CN_MAINLAND : Region filter, use * for all}
        {--window=last_90_days : Time window, e.g. last_90_days}
        {--only_quality=AB : Accepted quality levels}
        {--min_samples= : Minimum sample count (defaults from config)}';

    protected $description = 'Generate BIG5 psychometrics report and store metrics_json snapshot.';

    public function handle(): int
    {
        if (!Schema::hasTable('big5_psychometrics_reports')) {
            $this->error('Missing table big5_psychometrics_reports. Run migrations first.');
            return 1;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        $locale = trim((string) $this->option('locale'));
        $region = trim((string) $this->option('region'));
        $window = trim((string) $this->option('window'));
        $windowDays = $this->resolveWindowDays($window);
        $qualityLevels = $this->resolveQualityLevels((string) $this->option('only_quality'));
        $minSamples = $this->resolveMinSamples();
        $normsVersion = $this->resolveNormsVersion($scale, $locale, (string) $this->option('norms_version'));

        $query = DB::table('attempts as a')
            ->join('results as r', 'r.attempt_id', '=', 'a.id')
            ->where('a.scale_code', $scale)
            ->whereNotNull('a.submitted_at')
            ->where('a.submitted_at', '>=', now()->subDays($windowDays))
            ->where('a.locale', $locale)
            ->select(['r.result_json', 'a.region']);

        if ($region !== '' && $region !== '*') {
            $query->where('a.region', $region);
        }

        $rows = $query->get();
        $records = [];
        foreach ($rows as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            $quality = strtoupper((string) data_get($payload, 'quality.level', ''));
            if (!in_array($quality, $qualityLevels, true)) {
                continue;
            }

            $domains = data_get($payload, 'raw_scores.domains_mean');
            $facets = data_get($payload, 'raw_scores.facets_mean');
            if (!is_array($domains) || !is_array($facets)) {
                continue;
            }

            if (!$this->hasCompleteMetrics($domains, $facets)) {
                continue;
            }

            $records[] = [
                'domains' => $this->pickMetrics($domains, self::DOMAINS),
                'facets' => $this->pickMetrics($facets, self::FACETS),
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

        $metrics = $this->buildMetrics($records, $windowDays, $qualityLevels);
        $now = now();

        DB::table('big5_psychometrics_reports')->insert([
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

    private function resolveWindowDays(string $window): int
    {
        if (preg_match('/^last_(\d+)_days$/', $window, $m) === 1) {
            return max(1, (int) ($m[1] ?? 90));
        }

        if (is_numeric($window)) {
            return max(1, (int) $window);
        }

        return max(1, (int) config('big5_norms.psychometrics.window_days_default', 90));
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

        $levels = [];
        foreach (str_split($normalized) as $char) {
            if (in_array($char, ['A', 'B', 'C', 'D'], true) && !in_array($char, $levels, true)) {
                $levels[] = $char;
            }
        }

        return $levels === [] ? ['A', 'B'] : $levels;
    }

    private function resolveMinSamples(): int
    {
        $opt = $this->option('min_samples');
        if ($opt !== null && trim((string) $opt) !== '') {
            return max(1, (int) $opt);
        }

        return max(1, (int) config('big5_norms.psychometrics.min_samples', 100));
    }

    private function resolveNormsVersion(string $scale, string $locale, string $normsVersionOpt): string
    {
        $opt = trim($normsVersionOpt);
        if ($opt !== '' && strtolower($opt) !== 'latest') {
            return $opt;
        }

        $version = DB::table('scale_norms_versions')
            ->where('scale_code', $scale)
            ->where('locale', $locale)
            ->where('is_active', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->value('version');

        return is_string($version) && trim($version) !== '' ? $version : 'unknown';
    }

    /**
     * @param array<string,mixed> $domains
     * @param array<string,mixed> $facets
     */
    private function hasCompleteMetrics(array $domains, array $facets): bool
    {
        foreach (self::DOMAINS as $code) {
            if (!isset($domains[$code]) || !is_numeric($domains[$code])) {
                return false;
            }
        }
        foreach (self::FACETS as $code) {
            if (!isset($facets[$code]) || !is_numeric($facets[$code])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $metrics
     * @param list<string> $codes
     * @return array<string,float>
     */
    private function pickMetrics(array $metrics, array $codes): array
    {
        $picked = [];
        foreach ($codes as $code) {
            $picked[$code] = round((float) $metrics[$code], 4);
        }

        return $picked;
    }

    /**
     * @param list<array{domains:array<string,float>,facets:array<string,float>}> $records
     * @param list<string> $qualityLevels
     * @return array<string,mixed>
     */
    private function buildMetrics(array $records, int $windowDays, array $qualityLevels): array
    {
        $domainSeries = [];
        $facetSeries = [];
        foreach (self::DOMAINS as $domain) {
            $domainSeries[$domain] = [];
        }
        foreach (self::FACETS as $facet) {
            $facetSeries[$facet] = [];
        }

        foreach ($records as $row) {
            foreach (self::DOMAINS as $domain) {
                $domainSeries[$domain][] = (float) $row['domains'][$domain];
            }
            foreach (self::FACETS as $facet) {
                $facetSeries[$facet][] = (float) $row['facets'][$facet];
            }
        }

        $domainStats = [];
        foreach (self::DOMAINS as $domain) {
            $series = $domainSeries[$domain];
            $domainStats[$domain] = [
                'mean' => round($this->mean($series), 4),
                'sd' => round($this->populationSd($series), 4),
            ];
        }

        $facetStats = [];
        foreach (self::FACETS as $facet) {
            $series = $facetSeries[$facet];
            $facetStats[$facet] = [
                'mean' => round($this->mean($series), 4),
                'sd' => round($this->populationSd($series), 4),
            ];
        }

        $domainAlpha = [];
        foreach (self::DOMAINS as $domain) {
            $facets = self::DOMAIN_TO_FACETS[$domain];
            $matrix = [];
            foreach ($records as $row) {
                $matrix[] = array_map(static fn (string $f): float => (float) $row['facets'][$f], $facets);
            }
            $domainAlpha[$domain] = $this->cronbachAlpha($matrix);
        }

        $facetItemTotalCorr = [];
        foreach (self::DOMAIN_TO_FACETS as $domain => $facets) {
            foreach ($facets as $facet) {
                $x = [];
                $y = [];
                foreach ($records as $row) {
                    $facetValue = (float) $row['facets'][$facet];
                    $sumOthers = 0.0;
                    foreach ($facets as $f2) {
                        if ($f2 === $facet) {
                            continue;
                        }
                        $sumOthers += (float) $row['facets'][$f2];
                    }
                    $x[] = $facetValue;
                    $y[] = $sumOthers;
                }
                $facetItemTotalCorr[$facet] = $this->correlation($x, $y);
            }
        }

        $thresholds = (array) config('big5_norms.psychometrics.thresholds', []);

        return [
            'sample_n' => count($records),
            'window_days' => $windowDays,
            'quality_filter' => $qualityLevels,
            'domain_alpha' => $domainAlpha,
            'domain_stats' => $domainStats,
            'facet_stats' => $facetStats,
            'facet_item_total_corr' => $facetItemTotalCorr,
            'thresholds' => $thresholds,
        ];
    }

    /**
     * @param list<list<float>> $matrix
     */
    private function cronbachAlpha(array $matrix): ?float
    {
        if ($matrix === []) {
            return null;
        }
        $k = count($matrix[0] ?? []);
        if ($k <= 1) {
            return null;
        }

        $itemVariances = array_fill(0, $k, []);
        $totalScores = [];

        foreach ($matrix as $row) {
            if (count($row) !== $k) {
                return null;
            }
            $totalScores[] = array_sum($row);
            for ($i = 0; $i < $k; $i++) {
                $itemVariances[$i][] = (float) $row[$i];
            }
        }

        $sumItemVariance = 0.0;
        for ($i = 0; $i < $k; $i++) {
            $sumItemVariance += $this->populationSd($itemVariances[$i]) ** 2;
        }

        $totalVariance = $this->populationSd($totalScores) ** 2;
        if ($totalVariance <= 0.0) {
            return null;
        }

        $alpha = ($k / ($k - 1)) * (1.0 - ($sumItemVariance / $totalVariance));
        if (!is_finite($alpha)) {
            return null;
        }

        return round($alpha, 4);
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private function correlation(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n === 0 || $n !== count($y)) {
            return null;
        }

        $meanX = $this->mean($x);
        $meanY = $this->mean($y);
        $sumXY = 0.0;
        $sumXX = 0.0;
        $sumYY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $sumXY += $dx * $dy;
            $sumXX += $dx * $dx;
            $sumYY += $dy * $dy;
        }

        if ($sumXX <= 0.0 || $sumYY <= 0.0) {
            return null;
        }

        $corr = $sumXY / sqrt($sumXX * $sumYY);
        if (!is_finite($corr)) {
            return null;
        }

        return round($corr, 4);
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param list<float> $values
     */
    private function populationSd(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $d = ((float) $value) - $mean;
            $sum += $d * $d;
        }

        return sqrt($sum / count($values));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeResultJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
