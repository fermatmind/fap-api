<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NormsSdsRebuild extends Command
{
    private const SCALE_CODE = 'SDS_20';

    protected $signature = 'norms:sds:rebuild
        {--locale=zh-CN : Target locale}
        {--region= : Target region (default by locale)}
        {--group=all_18-60 : Group suffix or full group id}
        {--gender=ALL : Group gender}
        {--age_min=18 : Group age minimum}
        {--age_max=60 : Group age maximum}
        {--window_days=365 : Rolling window days}
        {--min_samples=1000 : Minimum samples required for publish}
        {--only_quality=AB : Include quality levels, e.g. AB or A,B}
        {--norms_version= : Norms version label}
        {--activate=1 : Set imported version active}
        {--dry-run=0 : Validate and calculate only}';

    protected $description = 'Rebuild SDS_20 rolling norms from production attempts and write scale_norms_versions/scale_norm_stats.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions') || !Schema::hasTable('scale_norm_stats')) {
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
            $group = 'all_18-60';
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
            $version = 'sds20_rebuild_'.now()->format('Ymd_His');
        }

        $this->line(sprintf(
            'rebuild scope: locale=%s region=%s group_id=%s window_days=%d quality=%s',
            $locale,
            $region,
            $groupId,
            $windowDays,
            implode(',', $qualityLevels)
        ));

        $rows = DB::table('attempts as a')
            ->join('results as r', 'r.attempt_id', '=', 'a.id')
            ->where('a.scale_code', self::SCALE_CODE)
            ->whereNotNull('a.submitted_at')
            ->where('a.submitted_at', '>=', now()->subDays($windowDays))
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

            $identity = $this->attemptIdentity((string) ($row->id ?? ''), $row->user_id ?? null, (string) ($row->anon_id ?? ''));
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

        $indexScores = [];
        foreach ($deduped as $row) {
            $payload = $this->decodeResultJson($row->result_json ?? null);
            $quality = strtoupper((string) (
                data_get($payload, 'quality.level')
                ?? data_get($payload, 'normed_json.quality.level')
                ?? ''
            ));
            if (!in_array($quality, $qualityLevels, true)) {
                continue;
            }

            $flags = data_get($payload, 'quality.flags');
            if (!is_array($flags)) {
                $flags = data_get($payload, 'normed_json.quality.flags');
            }
            if (is_array($flags)) {
                $upperFlags = array_map(
                    static fn ($flag): string => strtoupper(trim((string) $flag)),
                    $flags
                );
                foreach (['SPEEDING', 'STRAIGHTLINING'] as $blockedFlag) {
                    if (in_array($blockedFlag, $upperFlags, true)) {
                        continue 2;
                    }
                }
            }

            $indexScore = data_get($payload, 'scores.global.index_score');
            if (!is_numeric($indexScore)) {
                $indexScore = data_get($payload, 'normed_json.scores.global.index_score');
            }
            if (!is_numeric($indexScore)) {
                continue;
            }

            $indexScores[] = (float) $indexScore;
        }

        $sampleN = count($indexScores);
        if ($sampleN < $minSamples) {
            $this->error("insufficient samples: kept={$sampleN}, min_samples={$minSamples}");

            return 1;
        }

        $mean = $this->mean($indexScores);
        $sd = max(0.0001, $this->populationSd($indexScores, $mean));
        $checksum = hash(
            'sha256',
            json_encode([
                'group_id' => $groupId,
                'version' => $version,
                'metric' => [
                    'metric_level' => 'global',
                    'metric_code' => 'INDEX_SCORE',
                    'mean' => round($mean, 4),
                    'sd' => round($sd, 4),
                    'sample_n' => $sampleN,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info(sprintf('coverage=1/1 sample_n=%d checksum=%s', $sampleN, $checksum));
        if ($dryRun) {
            $this->line('dry-run=1, no write performed.');

            return 0;
        }

        $sourceId = trim((string) config('sds_norms.rolling.source_id', 'FERMATMIND_SDS20_PROD_ROLLING'));
        if ($sourceId === '') {
            $sourceId = 'FERMATMIND_SDS20_PROD_ROLLING';
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
            $sampleN,
            $checksum,
            $sourceId,
            $mean,
            $sd
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
                'source_id' => $sourceId,
                'source_type' => 'internal_prod',
                'status' => 'CALIBRATED',
                'is_active' => $activate ? 1 : 0,
                'published_at' => $now,
                'checksum' => $checksum,
                'meta_json' => json_encode([
                    'window_days' => $windowDays,
                    'min_samples' => $minSamples,
                    'only_quality' => implode(',', $qualityLevels),
                    'sample_n' => $sampleN,
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

            DB::table('scale_norm_stats')->insert([
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'global',
                'metric_code' => 'INDEX_SCORE',
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => $sampleN,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->info("published group_id={$groupId} version={$version} sample_n={$sampleN}");

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

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '') {
            return '';
        }

        return str_replace('-', '_', $region);
    }

    /**
     * @return list<string>
     */
    private function parseQualityLevels(string $option): array
    {
        $normalized = strtoupper(trim($option));
        if ($normalized === '') {
            return (array) config('sds_norms.rolling.quality_levels_default', ['A', 'B']);
        }

        if (str_contains($normalized, ',')) {
            $parts = array_map('trim', explode(',', $normalized));
        } else {
            $parts = str_split($normalized);
        }

        $levels = [];
        foreach ($parts as $part) {
            $candidate = strtoupper(trim((string) $part));
            if (in_array($candidate, ['A', 'B', 'C', 'D'], true) && !in_array($candidate, $levels, true)) {
                $levels[] = $candidate;
            }
        }

        return $levels === [] ? ['A', 'B'] : $levels;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function attemptIdentity(string $attemptId, mixed $userId, string $anonId): string
    {
        if (is_numeric($userId) && (int) $userId > 0) {
            return 'u:'.(int) $userId;
        }

        $anonId = trim($anonId);
        if ($anonId !== '') {
            return 'a:'.$anonId;
        }

        return 'attempt:'.$attemptId;
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
    private function populationSd(array $values, float $mean): float
    {
        if (count($values) <= 1) {
            return 0.0;
        }

        $acc = 0.0;
        foreach ($values as $value) {
            $delta = $value - $mean;
            $acc += $delta * $delta;
        }

        return sqrt($acc / count($values));
    }
}
