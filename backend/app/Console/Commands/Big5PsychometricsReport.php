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
        if (! Schema::hasTable('big5_psychometrics_reports')) {
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

        $sampleN = 0;
        $domainStats = $this->initStats(self::DOMAINS);
        $facetStats = $this->initStats(self::FACETS);
        $domainTotalStats = $this->initStats(self::DOMAINS);
        $facetItemTotalCorrStats = $this->initCorrelations(self::FACETS);

        foreach ($query->cursor() as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            $quality = strtoupper((string) data_get($payload, 'quality.level', ''));
            if (! in_array($quality, $qualityLevels, true)) {
                continue;
            }

            $domains = data_get($payload, 'raw_scores.domains_mean');
            $facets = data_get($payload, 'raw_scores.facets_mean');
            if (! is_array($domains) || ! is_array($facets)) {
                continue;
            }

            if (! $this->hasCompleteMetrics($domains, $facets)) {
                continue;
            }

            $pickedDomains = $this->pickMetrics($domains, self::DOMAINS);
            $pickedFacets = $this->pickMetrics($facets, self::FACETS);

            $sampleN++;
            foreach ($pickedDomains as $domain => $score) {
                $this->pushStat($domainStats[$domain], (float) $score);
            }
            foreach ($pickedFacets as $facet => $score) {
                $this->pushStat($facetStats[$facet], (float) $score);
            }

            foreach (self::DOMAIN_TO_FACETS as $domain => $facetsInDomain) {
                $domainTotal = 0.0;
                foreach ($facetsInDomain as $facet) {
                    $domainTotal += (float) $pickedFacets[$facet];
                }

                $this->pushStat($domainTotalStats[$domain], $domainTotal);
                foreach ($facetsInDomain as $facet) {
                    $facetValue = (float) $pickedFacets[$facet];
                    $sumOthers = $domainTotal - $facetValue;
                    $this->pushCorrelation($facetItemTotalCorrStats[$facet], $facetValue, $sumOthers);
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
            $domainStats,
            $facetStats,
            $domainTotalStats,
            $facetItemTotalCorrStats
        );
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
            if (in_array($char, ['A', 'B', 'C', 'D'], true) && ! in_array($char, $levels, true)) {
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
     * @param  array<string,mixed>  $domains
     * @param  array<string,mixed>  $facets
     */
    private function hasCompleteMetrics(array $domains, array $facets): bool
    {
        foreach (self::DOMAINS as $code) {
            if (! isset($domains[$code]) || ! is_numeric($domains[$code])) {
                return false;
            }
        }
        foreach (self::FACETS as $code) {
            if (! isset($facets[$code]) || ! is_numeric($facets[$code])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  list<string>  $codes
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
     * @param  list<string>  $qualityLevels
     * @param  array<string,array{n:int,sum:float,sum_sq:float}>  $domainStats
     * @param  array<string,array{n:int,sum:float,sum_sq:float}>  $facetStats
     * @param  array<string,array{n:int,sum:float,sum_sq:float}>  $domainTotalStats
     * @param  array<string,array{n:int,sum_x:float,sum_y:float,sum_x2:float,sum_y2:float,sum_xy:float}>  $facetItemTotalCorrStats
     * @return array<string,mixed>
     */
    private function buildMetrics(
        int $sampleN,
        int $windowDays,
        array $qualityLevels,
        array $domainStats,
        array $facetStats,
        array $domainTotalStats,
        array $facetItemTotalCorrStats
    ): array {
        $normalizedDomainStats = [];
        foreach (self::DOMAINS as $domain) {
            $stat = $domainStats[$domain];
            $normalizedDomainStats[$domain] = [
                'mean' => round($this->statMean($stat), 4),
                'sd' => round($this->statPopulationSd($stat), 4),
            ];
        }

        $normalizedFacetStats = [];
        foreach (self::FACETS as $facet) {
            $stat = $facetStats[$facet];
            $normalizedFacetStats[$facet] = [
                'mean' => round($this->statMean($stat), 4),
                'sd' => round($this->statPopulationSd($stat), 4),
            ];
        }

        $domainAlpha = [];
        foreach (self::DOMAINS as $domain) {
            $facets = self::DOMAIN_TO_FACETS[$domain];
            $k = count($facets);
            if ($k <= 1) {
                $domainAlpha[$domain] = null;

                continue;
            }

            $sumItemVariance = 0.0;
            foreach ($facets as $facet) {
                $sumItemVariance += $this->statVariance($facetStats[$facet]);
            }

            $totalVariance = $this->statVariance($domainTotalStats[$domain]);
            if ($totalVariance <= 0.0) {
                $domainAlpha[$domain] = null;

                continue;
            }

            $alpha = ($k / ($k - 1)) * (1.0 - ($sumItemVariance / $totalVariance));
            $domainAlpha[$domain] = is_finite($alpha) ? round($alpha, 4) : null;
        }

        $facetItemTotalCorr = [];
        foreach (self::FACETS as $facet) {
            $facetItemTotalCorr[$facet] = $this->corrFromStat($facetItemTotalCorrStats[$facet]);
        }

        $thresholds = (array) config('big5_norms.psychometrics.thresholds', []);

        return [
            'sample_n' => $sampleN,
            'window_days' => $windowDays,
            'quality_filter' => $qualityLevels,
            'domain_alpha' => $domainAlpha,
            'domain_stats' => $normalizedDomainStats,
            'facet_stats' => $normalizedFacetStats,
            'facet_item_total_corr' => $facetItemTotalCorr,
            'thresholds' => $thresholds,
        ];
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
     * @param  list<string>  $codes
     * @return array<string,array{n:int,sum_x:float,sum_y:float,sum_x2:float,sum_y2:float,sum_xy:float}>
     */
    private function initCorrelations(array $codes): array
    {
        $stats = [];
        foreach ($codes as $code) {
            $stats[$code] = [
                'n' => 0,
                'sum_x' => 0.0,
                'sum_y' => 0.0,
                'sum_x2' => 0.0,
                'sum_y2' => 0.0,
                'sum_xy' => 0.0,
            ];
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
     * @param  array{n:int,sum_x:float,sum_y:float,sum_x2:float,sum_y2:float,sum_xy:float}  $stat
     */
    private function pushCorrelation(array &$stat, float $x, float $y): void
    {
        $stat['n']++;
        $stat['sum_x'] += $x;
        $stat['sum_y'] += $y;
        $stat['sum_x2'] += $x * $x;
        $stat['sum_y2'] += $y * $y;
        $stat['sum_xy'] += $x * $y;
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
        $variance = $this->statVariance($stat);

        return $variance > 0.0 ? sqrt($variance) : 0.0;
    }

    /**
     * @param  array{n:int,sum:float,sum_sq:float}  $stat
     */
    private function statVariance(array $stat): float
    {
        if ($stat['n'] <= 0) {
            return 0.0;
        }

        $mean = $stat['sum'] / $stat['n'];
        $variance = ($stat['sum_sq'] / $stat['n']) - ($mean * $mean);
        if ($variance < 0.0 && abs($variance) < 1e-12) {
            return 0.0;
        }

        return $variance > 0.0 ? $variance : 0.0;
    }

    /**
     * @param  array{n:int,sum_x:float,sum_y:float,sum_x2:float,sum_y2:float,sum_xy:float}  $stat
     */
    private function corrFromStat(array $stat): ?float
    {
        $n = $stat['n'];
        if ($n <= 0) {
            return null;
        }

        $sumX = $stat['sum_x'];
        $sumY = $stat['sum_y'];
        $sumXX = $stat['sum_x2'] - (($sumX * $sumX) / $n);
        $sumYY = $stat['sum_y2'] - (($sumY * $sumY) / $n);
        if ($sumXX <= 0.0 || $sumYY <= 0.0) {
            return null;
        }

        $sumXY = $stat['sum_xy'] - (($sumX * $sumY) / $n);
        $corr = $sumXY / sqrt($sumXX * $sumYY);
        if (! is_finite($corr)) {
            return null;
        }

        return round($corr, 4);
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
