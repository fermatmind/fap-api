<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormsSdsDriftCheck extends Command
{
    protected $signature = 'norms:sds:drift-check
        {--scale=SDS_20 : Scale code}
        {--from= : Source norms version}
        {--to= : Target norms version}
        {--group_id= : Optional group id scope}
        {--threshold_mean= : Mean drift threshold}
        {--threshold_sd= : SD drift threshold}';

    protected $description = 'Check SDS_20 norms drift between two norms versions and fail on threshold breach.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions') || !Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats.');

            return 1;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        $fromVersion = trim((string) $this->option('from'));
        $toVersion = trim((string) $this->option('to'));
        $groupId = trim((string) $this->option('group_id'));

        $thresholdMean = $this->resolveThreshold(
            $this->option('threshold_mean'),
            (float) config('sds_norms.drift.threshold_mean', 3.0)
        );
        $thresholdSd = $this->resolveThreshold(
            $this->option('threshold_sd'),
            (float) config('sds_norms.drift.threshold_sd', 3.0)
        );

        if ($scale === '' || $fromVersion === '' || $toVersion === '') {
            $this->error('--scale, --from and --to are required.');

            return 1;
        }

        $fromRows = $this->loadVersions($scale, $fromVersion, $groupId);
        $toRows = $this->loadVersions($scale, $toVersion, $groupId);
        if ($fromRows === [] || $toRows === []) {
            $this->error("missing versions: from={$fromVersion} count=".count($fromRows).", to={$toVersion} count=".count($toRows));

            return 1;
        }

        $groups = array_values(array_intersect(array_keys($fromRows), array_keys($toRows)));
        sort($groups);
        if ($groups === []) {
            $this->error('no common groups between from/to versions.');

            return 1;
        }

        $compared = 0;
        $breaches = [];
        $maxMeanDiff = 0.0;
        $maxSdDiff = 0.0;

        foreach ($groups as $gid) {
            $fromMetric = $this->loadIndexMetric((string) ($fromRows[$gid]['id'] ?? ''));
            $toMetric = $this->loadIndexMetric((string) ($toRows[$gid]['id'] ?? ''));
            if ($fromMetric === null || $toMetric === null) {
                $this->error("group {$gid} missing INDEX_SCORE metric in one version.");

                return 1;
            }

            $meanDiff = abs((float) $toMetric['mean'] - (float) $fromMetric['mean']);
            $sdDiff = abs((float) $toMetric['sd'] - (float) $fromMetric['sd']);
            $maxMeanDiff = max($maxMeanDiff, $meanDiff);
            $maxSdDiff = max($maxSdDiff, $sdDiff);
            $compared++;

            if ($meanDiff > $thresholdMean || $sdDiff > $thresholdSd) {
                $breaches[] = [
                    'group_id' => $gid,
                    'mean_diff' => $meanDiff,
                    'sd_diff' => $sdDiff,
                ];
            }
        }

        $this->info(sprintf(
            'drift compared metrics=%d groups=%d max_mean_diff=%.4f max_sd_diff=%.4f',
            $compared,
            count($groups),
            $maxMeanDiff,
            $maxSdDiff
        ));

        if ($breaches !== []) {
            foreach (array_slice($breaches, 0, 10) as $breach) {
                $this->line(sprintf(
                    'breach group=%s metric=global:INDEX_SCORE mean_diff=%.4f sd_diff=%.4f',
                    (string) $breach['group_id'],
                    (float) $breach['mean_diff'],
                    (float) $breach['sd_diff']
                ));
            }

            $this->error(sprintf(
                'drift threshold breached: total=%d threshold_mean=%.4f threshold_sd=%.4f',
                count($breaches),
                $thresholdMean,
                $thresholdSd
            ));

            return 1;
        }

        $this->info('drift-check PASS');

        return 0;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadVersions(string $scale, string $version, string $groupId): array
    {
        $query = DB::table('scale_norms_versions')
            ->where('scale_code', $scale)
            ->where('version', $version);
        if ($groupId !== '') {
            $query->where('group_id', $groupId);
        }

        $rows = $query->get(['id', 'group_id', 'version', 'published_at']);
        $out = [];
        foreach ($rows as $row) {
            $gid = trim((string) ($row->group_id ?? ''));
            if ($gid === '') {
                continue;
            }

            if (!isset($out[$gid])) {
                $out[$gid] = [
                    'id' => (string) ($row->id ?? ''),
                    'group_id' => $gid,
                    'version' => (string) ($row->version ?? ''),
                    'published_at' => (string) ($row->published_at ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{mean:float,sd:float}|null
     */
    private function loadIndexMetric(string $versionId): ?array
    {
        if ($versionId === '') {
            return null;
        }

        $row = DB::table('scale_norm_stats')
            ->where('norm_version_id', $versionId)
            ->whereIn('metric_level', ['global', 'index'])
            ->where('metric_code', 'INDEX_SCORE')
            ->orderByDesc('sample_n')
            ->first(['mean', 'sd']);

        if (!$row) {
            return null;
        }

        return [
            'mean' => (float) ($row->mean ?? 0.0),
            'sd' => (float) ($row->sd ?? 0.0),
        ];
    }

    private function resolveThreshold(mixed $value, float $fallback): float
    {
        $fallback = max(0.0, $fallback);
        if ($value === null) {
            return $fallback;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return $fallback;
        }

        return max(0.0, (float) $trimmed);
    }
}
