<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormsBig5DriftCheck extends Command
{
    protected $signature = 'norms:big5:drift-check
        {--scale=BIG5_OCEAN : Scale code}
        {--from= : Source norms version}
        {--to= : Target norms version}
        {--group_id= : Optional group id scope}
        {--threshold_mean=0.35 : Mean drift threshold}
        {--threshold_sd=0.35 : SD drift threshold}';

    protected $description = 'Check BIG5 norms drift between two norms versions and fail on threshold breach.';

    public function handle(): int
    {
        if (! Schema::hasTable('scale_norms_versions') || ! Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats.');

            return 1;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        $fromVersion = trim((string) $this->option('from'));
        $toVersion = trim((string) $this->option('to'));
        $groupId = trim((string) $this->option('group_id'));
        $thresholdMean = max(0.0, (float) $this->option('threshold_mean'));
        $thresholdSd = max(0.0, (float) $this->option('threshold_sd'));

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

        $fromGroups = array_keys($fromRows);
        $toGroups = array_keys($toRows);
        $groups = array_values(array_intersect($fromGroups, $toGroups));
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
            $fromStats = $this->loadStats((string) ($fromRows[$gid]['id'] ?? ''));
            $toStats = $this->loadStats((string) ($toRows[$gid]['id'] ?? ''));

            if (count($fromStats) !== 35 || count($toStats) !== 35) {
                $this->error("group {$gid} must have 35 metrics in both versions.");

                return 1;
            }

            foreach ($fromStats as $metricKey => $fromMetric) {
                if (! isset($toStats[$metricKey])) {
                    $this->error("group {$gid} missing metric in target: {$metricKey}");

                    return 1;
                }

                $toMetric = $toStats[$metricKey];
                $meanDiff = abs((float) ($toMetric['mean'] ?? 0.0) - (float) ($fromMetric['mean'] ?? 0.0));
                $sdDiff = abs((float) ($toMetric['sd'] ?? 0.0) - (float) ($fromMetric['sd'] ?? 0.0));
                $maxMeanDiff = max($maxMeanDiff, $meanDiff);
                $maxSdDiff = max($maxSdDiff, $sdDiff);
                $compared++;

                if ($meanDiff > $thresholdMean || $sdDiff > $thresholdSd) {
                    $breaches[] = [
                        'group_id' => $gid,
                        'metric' => $metricKey,
                        'mean_diff' => $meanDiff,
                        'sd_diff' => $sdDiff,
                    ];
                }
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
                    'breach group=%s metric=%s mean_diff=%.4f sd_diff=%.4f',
                    (string) $breach['group_id'],
                    (string) $breach['metric'],
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
            if (! isset($out[$gid])) {
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
     * @return array<string,array{mean:float,sd:float}>
     */
    private function loadStats(string $versionId): array
    {
        if ($versionId === '') {
            return [];
        }

        $rows = DB::table('scale_norm_stats')
            ->where('norm_version_id', $versionId)
            ->get(['metric_level', 'metric_code', 'mean', 'sd']);
        $out = [];
        foreach ($rows as $row) {
            $level = strtolower(trim((string) ($row->metric_level ?? '')));
            $code = strtoupper(trim((string) ($row->metric_code ?? '')));
            if (! in_array($level, ['domain', 'facet'], true) || $code === '') {
                continue;
            }
            $key = $level.':'.$code;
            $out[$key] = [
                'mean' => (float) ($row->mean ?? 0.0),
                'sd' => (float) ($row->sd ?? 0.0),
            ];
        }

        ksort($out);

        return $out;
    }
}

