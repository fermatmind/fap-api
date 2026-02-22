<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NormsBig5Rebuild extends Command
{
    private const SCALE_CODE = 'BIG5_OCEAN';
    private const SOURCE_ID = 'FERMATMIND_PROD_ROLLING';

    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    protected $signature = 'norms:big5:rebuild
        {--locale=zh-CN : Target locale}
        {--region= : Target region (default by locale)}
        {--group=prod_all_18-60 : Group suffix or full group id}
        {--gender=ALL : Group gender}
        {--age_min=18 : Group age minimum}
        {--age_max=60 : Group age maximum}
        {--window_days=365 : Rolling window days}
        {--min_samples=1000 : Minimum samples required for publish}
        {--only_quality=AB : Include quality levels, e.g. AB or A,B}
        {--norms_version= : Norms version label}
        {--activate=1 : Set imported version active}
        {--dry-run=0 : Validate and calculate only}';

    protected $description = 'Rebuild BIG5 rolling norms from production attempts and write scale_norms_versions/scale_norm_stats.';

    public function handle(): int
    {
        if (! Schema::hasTable('scale_norms_versions') || ! Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats.');

            return 1;
        }

        $locale = $this->normalizeLocale((string) $this->option('locale'));
        $region = $this->normalizeRegion((string) $this->option('region'));
        if ($region === '') {
            $region = str_starts_with(strtolower($locale), 'zh') ? 'CN_MAINLAND' : 'GLOBAL';
        }
        $group = trim((string) $this->option('group'));
        if ($group === '') {
            $group = 'prod_all_18-60';
        }
        $groupId = str_starts_with($group, $locale.'_') ? $group : $locale.'_'.$group;

        $gender = strtoupper(trim((string) $this->option('gender')));
        if ($gender === '') {
            $gender = 'ALL';
        }
        $ageMin = max(1, (int) $this->option('age_min'));
        $ageMax = max($ageMin, (int) $this->option('age_max'));
        $windowDays = max(1, (int) $this->option('window_days'));
        $minSamples = max(1, (int) $this->option('min_samples'));
        $qualityLevels = $this->parseQualityLevels((string) $this->option('only_quality'));
        $dryRun = $this->isTruthy($this->option('dry-run'));
        $activate = $this->isTruthy($this->option('activate'));
        $version = trim((string) $this->option('norms_version'));
        if ($version === '') {
            $version = 'rebuild_'.now()->format('Ymd_His');
        }

        $this->line(sprintf(
            'rebuild scope: locale=%s region=%s group_id=%s window_days=%d quality=%s',
            $locale,
            $region,
            $groupId,
            $windowDays,
            implode(',', $qualityLevels)
        ));

        $windowStart = now()->subDays($windowDays);
        $rows = DB::table('attempts as a')
            ->join('results as r', 'r.attempt_id', '=', 'a.id')
            ->where('a.scale_code', self::SCALE_CODE)
            ->whereNotNull('a.submitted_at')
            ->where('a.submitted_at', '>=', $windowStart)
            ->orderByDesc('a.submitted_at')
            ->select([
                'a.id',
                'a.user_id',
                'a.anon_id',
                'a.locale',
                'a.region',
                'a.submitted_at',
                'r.result_json',
            ])
            ->get();

        $deduped = [];
        $lastAcceptedByIdentity = [];
        $dedupWindowSeconds = 30 * 24 * 3600;
        foreach ($rows as $row) {
            $rowLocale = $this->normalizeLocale((string) ($row->locale ?? ''));
            $rowRegion = $this->normalizeRegion((string) ($row->region ?? ''));
            if ($rowLocale !== $locale || $rowRegion !== $region) {
                continue;
            }

            $identity = $this->attemptIdentity(
                (string) ($row->id ?? ''),
                $row->user_id ?? null,
                (string) ($row->anon_id ?? '')
            );
            $submittedAtRaw = (string) ($row->submitted_at ?? '');
            $submittedAtTs = strtotime($submittedAtRaw);
            if ($submittedAtTs === false) {
                continue;
            }

            $lastAcceptedTs = $lastAcceptedByIdentity[$identity] ?? null;
            if (is_int($lastAcceptedTs) && ($lastAcceptedTs - $submittedAtTs) < $dedupWindowSeconds) {
                continue;
            }

            $lastAcceptedByIdentity[$identity] = $submittedAtTs;
            $deduped[] = $row;
        }

        $domainSamples = [];
        $facetSamples = [];
        $kept = 0;
        foreach ($deduped as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            $score = $this->extractScoreResult($payload);

            $quality = strtoupper((string) data_get($score, 'quality.level', ''));
            if (! in_array($quality, $qualityLevels, true)) {
                continue;
            }
            $qualityFlags = data_get($score, 'quality.flags');
            if (is_array($qualityFlags)) {
                $upperFlags = array_map(
                    static fn ($flag): string => strtoupper(trim((string) $flag)),
                    $qualityFlags
                );
                foreach (['ATTENTION_CHECK_FAILED', 'SPEEDING', 'STRAIGHTLINING'] as $blockedFlag) {
                    if (in_array($blockedFlag, $upperFlags, true)) {
                        continue 2;
                    }
                }
            }

            $domains = data_get($score, 'raw_scores.domains_mean');
            $facets = data_get($score, 'raw_scores.facets_mean');
            if (! is_array($domains) || ! is_array($facets)) {
                continue;
            }

            $kept++;
            foreach (self::DOMAINS as $domain) {
                if (! array_key_exists($domain, $domains)) {
                    continue;
                }
                $domainSamples[$domain][] = (float) $domains[$domain];
            }

            foreach (self::FACETS as $facet) {
                if (! array_key_exists($facet, $facets)) {
                    continue;
                }
                $facetSamples[$facet][] = (float) $facets[$facet];
            }
        }

        if (! $this->hasCoverage($domainSamples, $facetSamples)) {
            $this->error('coverage incomplete: expected 5 domains and 30 facets with at least 1 sample each.');

            return 1;
        }

        if ($kept < $minSamples) {
            $this->error("insufficient samples: kept={$kept}, min_samples={$minSamples}");

            return 1;
        }

        $stats = $this->buildStats($domainSamples, $facetSamples);
        $checksum = hash(
            'sha256',
            json_encode(['group_id' => $groupId, 'version' => $version, 'stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info("coverage=35/35 sample_n={$kept} checksum={$checksum}");
        if ($dryRun) {
            $this->line('dry-run=1, no write performed.');

            return 0;
        }

        DB::transaction(function () use (
            $locale,
            $region,
            $groupId,
            $gender,
            $ageMin,
            $ageMax,
            $version,
            $activate,
            $windowDays,
            $minSamples,
            $qualityLevels,
            $kept,
            $checksum,
            $stats
        ): void {
            $now = now();
            $versionId = (string) Str::uuid();

            DB::table('scale_norms_versions')->insert([
                'id' => $versionId,
                'scale_code' => self::SCALE_CODE,
                'norm_id' => $groupId,
                'region' => $region,
                'locale' => $locale,
                'version' => $version,
                'group_id' => $groupId,
                'gender' => $gender,
                'age_min' => $ageMin,
                'age_max' => $ageMax,
                'source_id' => self::SOURCE_ID,
                'source_type' => 'internal_prod',
                'status' => 'CALIBRATED',
                'is_active' => $activate ? 1 : 0,
                'published_at' => $now,
                'checksum' => $checksum,
                'meta_json' => json_encode([
                    'window_days' => $windowDays,
                    'min_samples' => $minSamples,
                    'only_quality' => implode(',', $qualityLevels),
                    'sample_n' => $kept,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($activate) {
                DB::table('scale_norms_versions')
                    ->where('scale_code', self::SCALE_CODE)
                    ->where('group_id', $groupId)
                    ->where('id', '!=', $versionId)
                    ->update([
                        'is_active' => 0,
                        'updated_at' => $now,
                    ]);
            }

            $rows = [];
            foreach ($stats as $metricLevel => $metricRows) {
                foreach ($metricRows as $metricCode => $stat) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'norm_version_id' => $versionId,
                        'metric_level' => $metricLevel,
                        'metric_code' => $metricCode,
                        'mean' => $stat['mean'],
                        'sd' => $stat['sd'],
                        'sample_n' => $stat['sample_n'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('scale_norm_stats')->insert($chunk);
            }
        });

        $this->info("published group_id={$groupId} version={$version} sample_n={$kept}");

        return 0;
    }

    /**
     * @param  array<string,list<float>>  $domainSamples
     * @param  array<string,list<float>>  $facetSamples
     * @return array{domain:array<string,array{mean:float,sd:float,sample_n:int}>,facet:array<string,array{mean:float,sd:float,sample_n:int}>}
     */
    private function buildStats(array $domainSamples, array $facetSamples): array
    {
        $domains = [];
        foreach (self::DOMAINS as $domain) {
            $vals = (array) ($domainSamples[$domain] ?? []);
            $mean = $this->mean($vals);
            $sd = max(0.0001, $this->populationSd($vals, $mean));
            $domains[$domain] = [
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => count($vals),
            ];
        }

        $facets = [];
        foreach (self::FACETS as $facet) {
            $vals = (array) ($facetSamples[$facet] ?? []);
            $mean = $this->mean($vals);
            $sd = max(0.0001, $this->populationSd($vals, $mean));
            $facets[$facet] = [
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => count($vals),
            ];
        }

        return [
            'domain' => $domains,
            'facet' => $facets,
        ];
    }

    /**
     * @param  array<string,list<float>>  $domainSamples
     * @param  array<string,list<float>>  $facetSamples
     */
    private function hasCoverage(array $domainSamples, array $facetSamples): bool
    {
        foreach (self::DOMAINS as $domain) {
            if (count((array) ($domainSamples[$domain] ?? [])) === 0) {
                return false;
            }
        }
        foreach (self::FACETS as $facet) {
            if (count((array) ($facetSamples[$facet] ?? [])) === 0) {
                return false;
            }
        }

        return true;
    }

    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function populationSd(array $values, float $mean): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $value) {
            $delta = ((float) $value) - $mean;
            $sum += $delta * $delta;
        }

        return sqrt($sum / count($values));
    }

    /**
     * @return list<string>
     */
    private function parseQualityLevels(string $raw): array
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return ['A', 'B'];
        }

        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        if (count($parts) === 1 && strlen((string) $parts[0]) > 1 && strpos((string) $parts[0], ',') === false) {
            $parts = str_split((string) $parts[0]);
        }

        $out = [];
        foreach ($parts as $part) {
            $part = strtoupper(trim((string) $part));
            if (in_array($part, ['A', 'B', 'C', 'D'], true)) {
                $out[$part] = true;
            }
        }

        return $out === [] ? ['A', 'B'] : array_keys($out);
    }

    private function extractScoreResult(array $payload): array
    {
        if (isset($payload['raw_scores']) && is_array($payload['raw_scores'])) {
            return $payload;
        }
        $fromBreakdown = data_get($payload, 'breakdown_json.score_result');
        if (is_array($fromBreakdown) && isset($fromBreakdown['raw_scores']) && is_array($fromBreakdown['raw_scores'])) {
            return $fromBreakdown;
        }
        $fromAxis = data_get($payload, 'axis_scores_json.score_result');
        if (is_array($fromAxis) && isset($fromAxis['raw_scores']) && is_array($fromAxis['raw_scores'])) {
            return $fromAxis;
        }
        $fromNormed = data_get($payload, 'normed_json');
        if (is_array($fromNormed) && isset($fromNormed['raw_scores']) && is_array($fromNormed['raw_scores'])) {
            return $fromNormed;
        }

        return [];
    }

    private function decodeResultJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function attemptIdentity(string $attemptId, mixed $userId, string $anonId): string
    {
        if (is_int($userId) || (is_string($userId) && trim($userId) !== '')) {
            return 'user:'.trim((string) $userId);
        }
        if (trim($anonId) !== '') {
            return 'anon:'.trim($anonId);
        }

        return 'attempt:'.$attemptId;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '') {
            return 'en';
        }

        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            $lang = strtolower($parts[0]);

            return $lang === 'zh' ? 'zh-CN' : $lang;
        }

        return strtolower($parts[0]).'-'.strtoupper($parts[1]);
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '') {
            return '';
        }

        return str_replace('-', '_', $region);
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
