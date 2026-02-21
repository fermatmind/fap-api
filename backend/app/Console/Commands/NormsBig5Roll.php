<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NormsBig5Roll extends Command
{
    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    protected $signature = 'norms:big5:roll {--window_days=365 : rolling window days}';

    protected $description = 'Build rolling BIG5_OCEAN production norms from quality A/B attempts.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions') || !Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats.');
            return 1;
        }

        $windowDays = max(1, (int) $this->option('window_days'));
        $windowStart = now()->subDays($windowDays);

        $rows = DB::table('attempts as a')
            ->join('results as r', 'r.attempt_id', '=', 'a.id')
            ->where('a.scale_code', 'BIG5_OCEAN')
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
        foreach ($rows as $row) {
            $identity = trim((string) ($row->user_id ?? ''));
            if ($identity === '') {
                $identity = 'anon:' . trim((string) ($row->anon_id ?? ''));
            } else {
                $identity = 'user:' . $identity;
            }
            if ($identity === 'anon:') {
                $identity = 'attempt:' . (string) $row->id;
            }
            if (!isset($deduped[$identity])) {
                $deduped[$identity] = $row;
            }
        }

        $groups = [];
        foreach ($deduped as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            $qualityLevel = strtoupper((string) data_get($payload, 'quality.level', ''));
            if (!in_array($qualityLevel, ['A', 'B'], true)) {
                continue;
            }

            $domains = data_get($payload, 'raw_scores.domains_mean');
            $facets = data_get($payload, 'raw_scores.facets_mean');
            if (!is_array($domains) || !is_array($facets)) {
                continue;
            }

            $locale = $this->normalizeLocale((string) ($row->locale ?? ''));
            $region = $this->normalizeRegion((string) ($row->region ?? ''));
            $groupId = $locale . '_prod_all_18-60';

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'locale' => $locale,
                    'region' => $region,
                    'domains' => [],
                    'facets' => [],
                    'samples' => 0,
                ];
            }

            $groups[$groupId]['samples']++;
            foreach (self::DOMAINS as $code) {
                $val = isset($domains[$code]) ? (float) $domains[$code] : null;
                if ($val === null) {
                    continue;
                }
                $groups[$groupId]['domains'][$code][] = $val;
            }
            foreach (self::FACETS as $code) {
                $val = isset($facets[$code]) ? (float) $facets[$code] : null;
                if ($val === null) {
                    continue;
                }
                $groups[$groupId]['facets'][$code][] = $val;
            }
        }

        if ($groups === []) {
            $this->warn('No eligible BIG5 quality A/B records found for rolling norms.');
            return 0;
        }

        $version = 'roll_' . now()->format('Ymd_His');
        $thresholds = (array) config('big5_norms.rolling.publish_thresholds', []);
        $published = 0;

        DB::transaction(function () use ($groups, $thresholds, $version, &$published): void {
            $now = now();

            foreach ($groups as $groupId => $group) {
                $sampleN = (int) ($group['samples'] ?? 0);
                $threshold = (int) ($thresholds[$groupId] ?? PHP_INT_MAX);
                if ($sampleN < $threshold) {
                    $this->line("skip {$groupId}: sample_n={$sampleN} threshold={$threshold}");
                    continue;
                }

                if (!$this->hasCoverage($group)) {
                    $this->line("skip {$groupId}: metric coverage incomplete");
                    continue;
                }

                $versionId = (string) Str::uuid();
                DB::table('scale_norms_versions')->insert([
                    'id' => $versionId,
                    'scale_code' => 'BIG5_OCEAN',
                    'norm_id' => $groupId,
                    'region' => (string) $group['region'],
                    'locale' => (string) $group['locale'],
                    'version' => $version,
                    'group_id' => $groupId,
                    'gender' => 'ALL',
                    'age_min' => 18,
                    'age_max' => 60,
                    'source_id' => 'FERMATMIND_PROD_ROLLING',
                    'source_type' => 'internal_prod',
                    'status' => 'CALIBRATED',
                    'is_active' => 1,
                    'published_at' => $now,
                    'checksum' => hash('sha256', $groupId . '|' . $version . '|' . $sampleN),
                    'meta_json' => json_encode(['window_days' => (int) $this->option('window_days')], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('scale_norms_versions')
                    ->where('scale_code', 'BIG5_OCEAN')
                    ->where('group_id', $groupId)
                    ->where('id', '!=', $versionId)
                    ->update([
                        'is_active' => 0,
                        'updated_at' => $now,
                    ]);

                $rows = [];
                foreach (self::DOMAINS as $domain) {
                    $vals = (array) ($group['domains'][$domain] ?? []);
                    $mean = $this->mean($vals);
                    $sd = max(0.0001, $this->populationSd($vals, $mean));
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'norm_version_id' => $versionId,
                        'metric_level' => 'domain',
                        'metric_code' => $domain,
                        'mean' => round($mean, 4),
                        'sd' => round($sd, 4),
                        'sample_n' => count($vals),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (self::FACETS as $facet) {
                    $vals = (array) ($group['facets'][$facet] ?? []);
                    $mean = $this->mean($vals);
                    $sd = max(0.0001, $this->populationSd($vals, $mean));
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'norm_version_id' => $versionId,
                        'metric_level' => 'facet',
                        'metric_code' => $facet,
                        'mean' => round($mean, 4),
                        'sd' => round($sd, 4),
                        'sample_n' => count($vals),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('scale_norm_stats')->insert($chunk);
                }

                $published++;
                $this->info("published {$groupId} version={$version} sample_n={$sampleN}");
            }
        });

        if ($published === 0) {
            $this->warn('No BIG5 rolling norms published (threshold/coverage constraints).');
            return 0;
        }

        $this->info("BIG5 rolling norms published groups={$published} version={$version}");

        return 0;
    }

    private function hasCoverage(array $group): bool
    {
        foreach (self::DOMAINS as $domain) {
            if (count((array) ($group['domains'][$domain] ?? [])) === 0) {
                return false;
            }
        }
        foreach (self::FACETS as $facet) {
            if (count((array) ($group['facets'][$facet] ?? [])) === 0) {
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

        return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '') {
            return 'GLOBAL';
        }

        return str_replace('-', '_', $region);
    }

    private function decodeResultJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
