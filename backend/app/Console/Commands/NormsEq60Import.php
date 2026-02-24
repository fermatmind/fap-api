<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScaleNormStat;
use App\Models\ScaleNormsVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NormsEq60Import extends Command
{
    private const SCALE_CODE = 'EQ_60';

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

    protected $signature = 'norms:eq60:import
        {--csv= : CSV file path}
        {--activate=0 : Force activate imported groups}
        {--dry-run=0 : Validate only, no writes}';

    protected $description = 'Import EQ_60 norms stats into scale_norms_versions + scale_norm_stats.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions') || !Schema::hasTable('scale_norm_stats')) {
            $this->error('Missing required tables: scale_norms_versions/scale_norm_stats. Run migrations first.');

            return 1;
        }

        $csvPath = trim((string) $this->option('csv'));
        if ($csvPath === '') {
            $csvPath = base_path('resources/norms/eq60/eq60_norms_seed.csv');
        } elseif (!str_starts_with($csvPath, '/')) {
            $csvPath = base_path($csvPath);
        }

        $activate = $this->isTruthy($this->option('activate'));
        $dryRun = $this->isTruthy($this->option('dry-run'));

        $parsed = $this->readCsv($csvPath);
        if (!($parsed['ok'] ?? false)) {
            foreach ((array) ($parsed['errors'] ?? []) as $error) {
                $this->error((string) $error);
            }

            return 1;
        }

        [$ok, $errors, $groups] = $this->validateRows((array) ($parsed['rows'] ?? []));
        if (!$ok) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return 1;
        }

        ksort($groups);
        $this->info('groups='.count($groups));
        foreach ($groups as $groupKey => $group) {
            $metrics = (array) ($group['metrics'] ?? []);
            $this->line(sprintf('- %s metrics=%d', $groupKey, count($metrics)));
        }

        if ($dryRun) {
            $this->info('dry-run=1, no write performed.');

            return 0;
        }

        DB::transaction(function () use ($groups, $activate, $csvPath): void {
            $now = now();
            foreach ($groups as $group) {
                $attrs = (array) ($group['attrs'] ?? []);
                $metrics = array_values((array) ($group['metrics'] ?? []));
                if ($attrs === [] || $metrics === []) {
                    continue;
                }

                $active = $activate ? true : (bool) ($attrs['is_active'] ?? false);
                $versionId = $this->upsertVersion($attrs, $metrics, $active, $csvPath, $now);
                if ($active) {
                    DB::table('scale_norms_versions')
                        ->where('scale_code', self::SCALE_CODE)
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
                        'metric_level' => (string) $metric['metric_level'],
                        'metric_code' => (string) $metric['metric_code'],
                        'mean' => (float) $metric['mean'],
                        'sd' => (float) $metric['sd'],
                        'sample_n' => (int) $metric['sample_n'],
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

    /**
     * @param  list<array{line:int,row:array<string,string>}>  $rows
     * @return array{0:bool,1:list<string>,2:array<string,array<string,mixed>>}
     */
    private function validateRows(array $rows): array
    {
        $errors = [];
        $groups = [];

        foreach ($rows as $rowWrap) {
            $lineNo = (int) ($rowWrap['line'] ?? 0);
            $row = (array) ($rowWrap['row'] ?? []);

            $scale = strtoupper(trim((string) ($row['scale_code'] ?? '')));
            if ($scale !== self::SCALE_CODE) {
                $errors[] = "line {$lineNo}: scale_code must be ".self::SCALE_CODE.", got {$scale}";
                continue;
            }

            $version = trim((string) ($row['norms_version'] ?? ''));
            $locale = trim((string) ($row['locale'] ?? ''));
            $region = strtoupper(str_replace('-', '_', trim((string) ($row['region'] ?? ''))));
            $groupId = trim((string) ($row['group_id'] ?? ''));
            $gender = strtoupper(trim((string) ($row['gender'] ?? 'ALL')));
            $metricLevel = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $metricCode = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            $status = strtoupper(trim((string) ($row['status'] ?? 'PROVISIONAL')));
            $sourceId = trim((string) ($row['source_id'] ?? ''));
            $sourceType = trim((string) ($row['source_type'] ?? ''));

            $ageMin = (int) ($row['age_min'] ?? 0);
            $ageMax = (int) ($row['age_max'] ?? 0);
            $mean = is_numeric((string) ($row['mean'] ?? '')) ? (float) $row['mean'] : null;
            $sd = is_numeric((string) ($row['sd'] ?? '')) ? (float) $row['sd'] : null;
            $sampleN = is_numeric((string) ($row['sample_n'] ?? '')) ? (int) $row['sample_n'] : 0;

            if ($version === '' || $locale === '' || $region === '' || $groupId === '') {
                $errors[] = "line {$lineNo}: norms_version/locale/region/group_id are required";
                continue;
            }
            if ($gender === '') {
                $gender = 'ALL';
            }
            if ($ageMin <= 0 || $ageMax < $ageMin) {
                $errors[] = "line {$lineNo}: invalid age range {$ageMin}-{$ageMax}";
                continue;
            }
            if (!in_array($metricLevel, ['global', 'index'], true) || $metricCode === '') {
                $errors[] = "line {$lineNo}: invalid metric_level/metric_code";
                continue;
            }
            if ($mean === null || $sd === null || $sd <= 0.0 || $sampleN <= 0) {
                $errors[] = "line {$lineNo}: invalid mean/sd/sample_n";
                continue;
            }
            if ($sourceId === '') {
                $errors[] = "line {$lineNo}: source_id is required";
                continue;
            }
            if ($sourceType === '') {
                $sourceType = 'external_seed';
            }
            if ($status === '') {
                $status = 'PROVISIONAL';
            }
            if (!in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING', 'RETIRED', 'BOOTSTRAP'], true)) {
                $errors[] = "line {$lineNo}: invalid status {$status}";
                continue;
            }

            $publishedAtRaw = trim((string) ($row['published_at'] ?? ''));
            $publishedAt = $publishedAtRaw !== '' ? $publishedAtRaw : now()->toDateTimeString();
            $isActive = $this->isTruthy($row['is_active'] ?? '0');

            $groupKey = $groupId.'|'.$version;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'attrs' => [
                        'scale_code' => self::SCALE_CODE,
                        'version' => $version,
                        'locale' => $locale,
                        'region' => $region,
                        'group_id' => $groupId,
                        'gender' => $gender,
                        'age_min' => $ageMin,
                        'age_max' => $ageMax,
                        'source_id' => $sourceId,
                        'source_type' => $sourceType,
                        'status' => $status,
                        'published_at' => $publishedAt,
                        'is_active' => $isActive,
                    ],
                    'metrics' => [],
                ];
            }

            $metricKey = $metricLevel.':'.$metricCode;
            $groups[$groupKey]['metrics'][$metricKey] = [
                'metric_level' => $metricLevel,
                'metric_code' => $metricCode,
                'mean' => round($mean, 6),
                'sd' => round($sd, 6),
                'sample_n' => $sampleN,
            ];
        }

        foreach ($groups as $groupKey => $group) {
            $metrics = (array) ($group['metrics'] ?? []);
            if ($metrics === []) {
                $errors[] = "group {$groupKey}: no metrics rows";
            }
        }

        return [$errors === [], $errors, $groups];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readCsv(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            return [
                'ok' => false,
                'errors' => ["csv not found: {$csvPath}"],
            ];
        }

        $fp = fopen($csvPath, 'rb');
        if ($fp === false) {
            return [
                'ok' => false,
                'errors' => ["unable to open csv: {$csvPath}"],
            ];
        }

        $lineNo = 0;
        $headers = [];
        $rows = [];
        $errors = [];

        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($lineNo === 1) {
                $headers = is_array($row) ? array_map(static fn ($v): string => trim((string) $v), $row) : [];
                continue;
            }

            if (!is_array($row) || $row === [null]) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $idx => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = trim((string) ($row[$idx] ?? ''));
            }

            $rows[] = [
                'line' => $lineNo,
                'row' => $assoc,
            ];
        }

        fclose($fp);

        if ($headers === []) {
            return [
                'ok' => false,
                'errors' => ['missing csv header row'],
            ];
        }

        $missing = [];
        foreach (self::REQUIRED_HEADERS as $header) {
            if (!in_array($header, $headers, true)) {
                $missing[] = $header;
            }
        }
        if ($missing !== []) {
            $errors[] = 'missing required headers: '.implode(', ', $missing);
        }

        if ($rows === []) {
            $errors[] = 'csv has no data rows';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $attrs
     * @param  list<array<string,mixed>>  $metrics
     */
    private function upsertVersion(array $attrs, array $metrics, bool $active, string $csvPath, mixed $now): string
    {
        $existing = ScaleNormsVersion::query()
            ->where('scale_code', self::SCALE_CODE)
            ->where('locale', $attrs['locale'])
            ->where('region', $attrs['region'])
            ->where('group_id', $attrs['group_id'])
            ->where('version', $attrs['version'])
            ->first();

        $versionId = $existing?->id ?: (string) Str::uuid();
        $checksum = hash(
            'sha256',
            json_encode(
                [
                    'scale_code' => self::SCALE_CODE,
                    'version' => $attrs['version'],
                    'group_id' => $attrs['group_id'],
                    'metrics' => $metrics,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );

        $payload = [
            'id' => $versionId,
            'scale_code' => self::SCALE_CODE,
            'norm_id' => $attrs['group_id'],
            'region' => $attrs['region'],
            'locale' => $attrs['locale'],
            'version' => $attrs['version'],
            'group_id' => $attrs['group_id'],
            'gender' => $attrs['gender'],
            'age_min' => (int) $attrs['age_min'],
            'age_max' => (int) $attrs['age_max'],
            'source_id' => $attrs['source_id'],
            'source_type' => $attrs['source_type'],
            'status' => $attrs['status'],
            'is_active' => $active,
            'published_at' => $attrs['published_at'],
            'checksum' => $checksum,
            'meta_json' => [
                'import_csv' => $csvPath,
                'metrics_count' => count($metrics),
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

        return $versionId;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
