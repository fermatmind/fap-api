<?php

namespace App\Console\Commands;

use App\Models\ScaleNormStat;
use App\Models\ScaleNormsVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NormsImport extends Command
{
    private const REQUIRED_HEADERS = [
        'scale_code',
        'norms_version',
        'locale',
        'region',
        'group_id',
        'gender',
        'age_min',
        'age_max',
        'metric_level',
        'metric_code',
        'mean',
        'sd',
        'sample_n',
        'source_id',
        'source_type',
        'status',
        'is_active',
        'published_at',
    ];

    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    protected $signature = 'norms:import
        {--scale=BIG5_OCEAN : Scale code}
        {--csv= : CSV file path}
        {--activate=0 : Force activate imported groups}
        {--dry-run=0 : Validate only, no writes}';

    protected $description = 'Import scale norms stats into scale_norms_versions + scale_norm_stats with strict coverage checks.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions') || !Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats. Run migrations first.');
            return 1;
        }

        $scaleCode = strtoupper(trim((string) $this->option('scale')));
        if ($scaleCode === '') {
            $this->error('--scale is required.');
            return 1;
        }

        $csvPath = trim((string) $this->option('csv'));
        if ($csvPath === '') {
            $csvPath = base_path('resources/norms/big5/big5_norm_stats_seed.csv');
        } elseif (!str_starts_with($csvPath, '/')) {
            $csvPath = base_path($csvPath);
        }

        $dryRun = $this->isTruthy($this->option('dry-run'));
        $activate = $this->isTruthy($this->option('activate'));

        $parse = $this->readCsv($csvPath);
        if (!($parse['ok'] ?? false)) {
            foreach ((array) ($parse['errors'] ?? []) as $err) {
                $this->error((string) $err);
            }
            return 1;
        }

        $rows = (array) ($parse['rows'] ?? []);
        [$ok, $errors, $groups, $canonicalRows] = $this->validateRows($rows, $scaleCode);

        if (!$ok) {
            foreach ($errors as $err) {
                $this->error($err);
            }
            return 1;
        }

        ksort($groups);
        $this->info(sprintf('groups=%d', count($groups)));
        foreach ($groups as $groupKey => $group) {
            $domainCount = count((array) ($group['coverage']['domain'] ?? []));
            $facetCount = count((array) ($group['coverage']['facet'] ?? []));
            $this->line(sprintf(
                '- %s coverage=%d/35 (domain=%d facet=%d)',
                $groupKey,
                $domainCount + $facetCount,
                $domainCount,
                $facetCount
            ));
        }

        $checksum = hash('sha256', json_encode($canonicalRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('checksum=' . $checksum);

        if ($dryRun) {
            $this->info('dry-run=1, no write performed.');
            return 0;
        }

        DB::transaction(function () use ($groups, $activate, $checksum, $csvPath): void {
            $now = now();
            $this->upsertSources($groups, $now);

            foreach ($groups as $group) {
                $attrs = (array) $group['attrs'];
                $metrics = array_values((array) $group['metrics']);

                $active = $activate ? true : (bool) ($attrs['is_active'] ?? false);
                $versionId = $this->upsertVersion($attrs, $active, $checksum, $csvPath, $now);

                if ($active) {
                    DB::table('scale_norms_versions')
                        ->where('scale_code', $attrs['scale_code'])
                        ->where('locale', $attrs['locale'])
                        ->where('region', $attrs['region'])
                        ->where('group_id', $attrs['group_id'])
                        ->where('id', '!=', $versionId)
                        ->update([
                            'is_active' => false,
                            'updated_at' => $now,
                        ]);
                }

                DB::table('scale_norm_stats')
                    ->where('norm_version_id', $versionId)
                    ->delete();

                $rows = [];
                foreach ($metrics as $metric) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'norm_version_id' => $versionId,
                        'metric_level' => $metric['metric_level'],
                        'metric_code' => $metric['metric_code'],
                        'mean' => $metric['mean'],
                        'sd' => $metric['sd'],
                        'sample_n' => $metric['sample_n'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 100) as $chunk) {
                    ScaleNormStat::query()->insert($chunk);
                }
            }
        });

        $this->info(sprintf('imported groups=%d from %s', count($groups), $csvPath));
        return 0;
    }

    private function upsertVersion(array $attrs, bool $active, string $checksum, string $csvPath, $now): string
    {
        $existing = ScaleNormsVersion::query()
            ->where('scale_code', $attrs['scale_code'])
            ->where('locale', $attrs['locale'])
            ->where('region', $attrs['region'])
            ->where('group_id', $attrs['group_id'])
            ->where('version', $attrs['version'])
            ->first();

        $id = $existing?->id ?: (string) Str::uuid();

        $payload = [
            'id' => $id,
            'scale_code' => $attrs['scale_code'],
            'norm_id' => $attrs['group_id'],
            'region' => $attrs['region'],
            'locale' => $attrs['locale'],
            'version' => $attrs['version'],
            'group_id' => $attrs['group_id'],
            'gender' => $attrs['gender'],
            'age_min' => $attrs['age_min'],
            'age_max' => $attrs['age_max'],
            'source_id' => $attrs['source_id'],
            'source_type' => $attrs['source_type'],
            'status' => $attrs['status'],
            'is_active' => $active,
            'published_at' => $attrs['published_at'],
            'checksum' => $checksum,
            'meta_json' => [
                'import_csv' => $csvPath,
                'group_id' => $attrs['group_id'],
            ],
            'updated_at' => $now,
        ];

        if ($existing) {
            $existing->fill($payload);
            $existing->save();
        } else {
            $payload['created_at'] = $now;
            ScaleNormsVersion::query()->create($payload);
        }

        return $id;
    }

    private function upsertSources(array $groups, $now): void
    {
        if (!Schema::hasTable('norm_sources')) {
            return;
        }

        $seedPath = base_path('resources/norms/big5/norm_sources_seed.csv');
        $seedRows = [];
        if (is_file($seedPath)) {
            $seedRead = $this->readCsv($seedPath, ['source_id', 'title', 'citation', 'homepage_url', 'license', 'notes_json']);
            if ($seedRead['ok'] ?? false) {
                $seedRows = (array) ($seedRead['rows'] ?? []);
            }
        }

        $sourceRows = [];
        foreach ($seedRows as $row) {
            $sourceId = trim((string) ($row['source_id'] ?? ''));
            if ($sourceId === '') {
                continue;
            }
            $notes = trim((string) ($row['notes_json'] ?? '{}'));
            $decoded = json_decode($notes, true);
            $sourceRows[$sourceId] = [
                'source_id' => $sourceId,
                'title' => (string) ($row['title'] ?? $sourceId),
                'citation' => (string) ($row['citation'] ?? ''),
                'homepage_url' => (string) ($row['homepage_url'] ?? ''),
                'license' => (string) ($row['license'] ?? ''),
                'notes_json' => json_encode(is_array($decoded) ? $decoded : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($groups as $group) {
            $attrs = (array) ($group['attrs'] ?? []);
            $sourceId = (string) ($attrs['source_id'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            if (!isset($sourceRows[$sourceId])) {
                $sourceRows[$sourceId] = [
                    'source_id' => $sourceId,
                    'title' => $sourceId,
                    'citation' => '',
                    'homepage_url' => '',
                    'license' => '',
                    'notes_json' => '{}',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($sourceRows === []) {
            return;
        }

        DB::table('norm_sources')->upsert(
            array_values($sourceRows),
            ['source_id'],
            ['title', 'citation', 'homepage_url', 'license', 'notes_json', 'updated_at']
        );
    }

    private function validateRows(array $rows, string $scaleCode): array
    {
        $errors = [];
        $groups = [];
        $canonicalRows = [];

        $rowNo = 1;
        foreach ($rows as $row) {
            $rowNo++;
            $scale = strtoupper(trim((string) ($row['scale_code'] ?? '')));
            if ($scale !== $scaleCode) {
                $errors[] = "line {$rowNo}: scale_code must be {$scaleCode}, got {$scale}";
                continue;
            }

            $attrs = [
                'scale_code' => $scale,
                'version' => trim((string) ($row['norms_version'] ?? '')),
                'locale' => trim((string) ($row['locale'] ?? '')),
                'region' => strtoupper(str_replace('-', '_', trim((string) ($row['region'] ?? '')))),
                'group_id' => trim((string) ($row['group_id'] ?? '')),
                'gender' => strtoupper(trim((string) ($row['gender'] ?? 'ALL'))),
                'age_min' => (int) ($row['age_min'] ?? 0),
                'age_max' => (int) ($row['age_max'] ?? 0),
                'source_id' => trim((string) ($row['source_id'] ?? '')),
                'source_type' => strtolower(trim((string) ($row['source_type'] ?? ''))),
                'status' => strtoupper(trim((string) ($row['status'] ?? 'BOOTSTRAP'))),
                'is_active' => $this->isTruthy($row['is_active'] ?? '0'),
                'published_at' => trim((string) ($row['published_at'] ?? '')),
            ];

            foreach (['version', 'locale', 'region', 'group_id', 'source_id'] as $required) {
                if ($attrs[$required] === '') {
                    $errors[] = "line {$rowNo}: {$required} is required";
                }
            }

            if ($attrs['gender'] === '') {
                $attrs['gender'] = 'ALL';
            }
            if ($attrs['age_min'] <= 0 || $attrs['age_max'] <= 0 || $attrs['age_max'] < $attrs['age_min']) {
                $errors[] = "line {$rowNo}: invalid age_min/age_max";
            }

            if (!in_array($attrs['source_type'], ['open_dataset', 'peer_reviewed', 'internal_prod'], true)) {
                $errors[] = "line {$rowNo}: invalid source_type={$attrs['source_type']}";
            }

            if (!in_array($attrs['status'], ['BOOTSTRAP', 'CALIBRATED', 'RETIRED'], true)) {
                $errors[] = "line {$rowNo}: invalid status={$attrs['status']}";
            }

            $publishedAt = $attrs['published_at'];
            if ($publishedAt !== '' && strtotime($publishedAt) === false) {
                $errors[] = "line {$rowNo}: invalid published_at={$publishedAt}";
            }

            $metricLevel = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $metricCode = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            $mean = (float) ($row['mean'] ?? 0);
            $sd = (float) ($row['sd'] ?? 0);
            $sampleN = (int) ($row['sample_n'] ?? 0);

            if (!in_array($metricLevel, ['domain', 'facet'], true)) {
                $errors[] = "line {$rowNo}: invalid metric_level={$metricLevel}";
            }

            if ($metricLevel === 'domain' && !in_array($metricCode, self::DOMAINS, true)) {
                $errors[] = "line {$rowNo}: invalid domain metric_code={$metricCode}";
            }
            if ($metricLevel === 'facet' && !in_array($metricCode, self::FACETS, true)) {
                $errors[] = "line {$rowNo}: invalid facet metric_code={$metricCode}";
            }

            if ($mean < 1.0 || $mean > 5.0) {
                $errors[] = "line {$rowNo}: mean must be in [1,5]";
            }
            if ($sd <= 0.0) {
                $errors[] = "line {$rowNo}: sd must be > 0";
            }
            if ($sampleN <= 0) {
                $errors[] = "line {$rowNo}: sample_n must be > 0";
            }

            $groupKey = implode('|', [
                $attrs['scale_code'],
                $attrs['version'],
                $attrs['locale'],
                $attrs['region'],
                $attrs['group_id'],
            ]);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'attrs' => $attrs,
                    'metrics' => [],
                    'coverage' => [
                        'domain' => [],
                        'facet' => [],
                    ],
                    'active_consistency' => $attrs['is_active'],
                ];
            } elseif ((bool) $groups[$groupKey]['active_consistency'] !== (bool) $attrs['is_active']) {
                $errors[] = "line {$rowNo}: is_active must be consistent within group {$groupKey}";
            }

            $metricKey = $metricLevel . ':' . $metricCode;
            if (isset($groups[$groupKey]['metrics'][$metricKey])) {
                $errors[] = "line {$rowNo}: duplicated metric {$metricKey} in group {$groupKey}";
                continue;
            }

            $groups[$groupKey]['metrics'][$metricKey] = [
                'metric_level' => $metricLevel,
                'metric_code' => $metricCode,
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => $sampleN,
            ];
            $groups[$groupKey]['coverage'][$metricLevel][$metricCode] = true;

            $canonicalRows[] = [
                'group' => $groupKey,
                'metric' => $metricKey,
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => $sampleN,
            ];
        }

        foreach ($groups as $groupKey => $group) {
            $domainCoverage = array_keys((array) ($group['coverage']['domain'] ?? []));
            $facetCoverage = array_keys((array) ($group['coverage']['facet'] ?? []));

            if (count($domainCoverage) !== 5 || count(array_intersect($domainCoverage, self::DOMAINS)) !== 5) {
                $errors[] = "group {$groupKey}: domain coverage must be exactly O/C/E/A/N";
            }

            if (count($facetCoverage) !== 30 || count(array_intersect($facetCoverage, self::FACETS)) !== 30) {
                $errors[] = "group {$groupKey}: facet coverage must be exactly 30 facets";
            }

            $metricCount = count((array) $group['metrics']);
            if ($metricCount !== 35) {
                $errors[] = "group {$groupKey}: metric coverage must be 35/35, got {$metricCount}";
            }
        }

        usort($canonicalRows, static function (array $a, array $b): int {
            $groupCmp = strcmp($a['group'], $b['group']);
            if ($groupCmp !== 0) {
                return $groupCmp;
            }

            return strcmp($a['metric'], $b['metric']);
        });

        return [empty($errors), $errors, $groups, $canonicalRows];
    }

    private function readCsv(string $path, ?array $requiredHeaders = null): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'errors' => ["csv not found: {$path}"]];
        }

        $requiredHeaders = $requiredHeaders ?: self::REQUIRED_HEADERS;

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return ['ok' => false, 'errors' => ["cannot open csv: {$path}"]];
        }

        $line = 0;
        $header = [];
        $rows = [];

        while (($csv = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($line === 1) {
                $header = array_map(static fn ($v): string => trim((string) $v), (array) $csv);
                continue;
            }

            if ($csv === [null]) {
                continue;
            }

            $assoc = [];
            foreach ($header as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $assoc[$name] = trim((string) ($csv[$idx] ?? ''));
            }
            $rows[] = $assoc;
        }

        fclose($fp);

        $missing = array_values(array_diff($requiredHeaders, $header));
        if ($missing !== []) {
            return [
                'ok' => false,
                'errors' => [
                    sprintf('csv header mismatch: missing [%s] in %s', implode(', ', $missing), $path),
                ],
            ];
        }

        return [
            'ok' => true,
            'rows' => $rows,
            'header' => $header,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $str = strtolower(trim((string) $value));

        return in_array($str, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
