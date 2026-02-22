<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NormsBuildArtifact;
use App\Services\Psychometrics\Big5\Bootstrap\Big5BootstrapCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NormsBig5BootstrapBuild extends Command
{
    private const SCALE_CODE = 'BIG5_OCEAN';

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

    public function __construct(
        private readonly Big5BootstrapCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected $signature = 'norms:big5:bootstrap:build
        {--source=johnson_osf : Source profile key (johnson_osf|zh_cn_validation)}
        {--locale= : Locale override}
        {--input= : Input attempt-level CSV path (optional)}
        {--out=resources/norms/big5/big5_norm_stats_seed.csv : Seed CSV output path}
        {--artifact= : Artifact JSON output path (optional)}
        {--dry-run=0 : Validate only, no file/db writes}';

    protected $description = 'Build BIG5 bootstrap norms rows (35 metrics) and persist deterministic build artifacts.';

    public function handle(): int
    {
        $source = trim((string) $this->option('source'));
        $profiles = (array) config('big5_norms.bootstrap.sources', []);
        $profile = $profiles[$source] ?? null;
        if (!is_array($profile)) {
            $this->error("unknown --source={$source}");

            return 1;
        }

        $locale = trim((string) ($this->option('locale') ?: ($profile['locale'] ?? '')));
        if ($locale === '') {
            $this->error('locale is required (option --locale or source profile locale).');

            return 1;
        }

        $qualityFilters = (array) config('big5_norms.bootstrap.quality_filters', ['A', 'B']);
        $qualityFilters = array_values(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            $qualityFilters
        )));
        if ($qualityFilters === []) {
            $qualityFilters = ['A', 'B'];
        }

        $outputPath = $this->resolvePath((string) $this->option('out'));
        $inputOption = trim((string) $this->option('input'));
        $defaultInput = $this->resolvePathFromProfile((string) ($profile['input_csv'] ?? ''));
        $inputPath = $inputOption !== '' ? $this->resolvePath($inputOption) : $defaultInput;
        $dryRun = $this->isTruthy($this->option('dry-run'));

        $sourceMode = '';
        $build = [];
        if ($inputPath !== '' && is_file($inputPath)) {
            $parsedInput = $this->readCsv($inputPath);
            if (!($parsedInput['ok'] ?? false)) {
                foreach ((array) ($parsedInput['errors'] ?? []) as $error) {
                    $this->error((string) $error);
                }

                return 1;
            }

            $build = $this->calculator->calculateFromAttemptRows((array) ($parsedInput['rows'] ?? []), $qualityFilters);
            $sourceMode = 'attempt_rows';
        } else {
            $fallbackGroupId = trim((string) ($profile['fallback_group_id'] ?? ''));
            $fallbackRawPath = $this->resolvePath((string) config('big5_norms.bootstrap.fallback_raw_csv', 'content_packs/BIG5_OCEAN/v1/raw/norm_stats.csv'));
            $fallback = $this->readFallbackNormRows($fallbackRawPath, $fallbackGroupId);
            if (!($fallback['ok'] ?? false)) {
                foreach ((array) ($fallback['errors'] ?? []) as $error) {
                    $this->error((string) $error);
                }

                return 1;
            }
            $build = $this->calculator->calculateFromNormRows((array) ($fallback['rows'] ?? []));
            $sourceMode = 'fallback_norm_rows';
        }

        if (!($build['ok'] ?? false)) {
            foreach ((array) ($build['errors'] ?? []) as $error) {
                $this->error((string) $error);
            }

            return 1;
        }

        $stats = (array) ($build['stats'] ?? []);
        $domainStats = (array) ($stats['domain'] ?? []);
        $facetStats = (array) ($stats['facet'] ?? []);
        if (count($domainStats) !== 5 || count($facetStats) !== 30) {
            $this->error('bootstrap coverage invalid: expected 5 domains + 30 facets.');

            return 1;
        }

        $normsVersion = trim((string) ($profile['norms_version'] ?? ''));
        $groupId = trim((string) ($profile['group_id'] ?? ''));
        $region = trim((string) ($profile['region'] ?? 'GLOBAL'));
        $gender = trim((string) ($profile['gender'] ?? 'ALL'));
        $ageMin = (int) ($profile['age_min'] ?? 18);
        $ageMax = (int) ($profile['age_max'] ?? 60);
        $sourceId = trim((string) ($profile['source_id'] ?? ''));
        $sourceType = trim((string) ($profile['source_type'] ?? 'open_dataset'));
        $status = strtoupper(trim((string) ($profile['status'] ?? 'CALIBRATED')));
        $publishedAt = trim((string) ($profile['published_at'] ?? now()->toIso8601String()));
        if ($normsVersion === '' || $groupId === '' || $sourceId === '') {
            $this->error('source profile must define norms_version/group_id/source_id.');

            return 1;
        }

        $existingRows = [];
        if (is_file($outputPath)) {
            $parsedSeed = $this->readCsv($outputPath, self::REQUIRED_HEADERS);
            if (!($parsedSeed['ok'] ?? false)) {
                foreach ((array) ($parsedSeed['errors'] ?? []) as $error) {
                    $this->error((string) $error);
                }

                return 1;
            }
            $existingRows = (array) ($parsedSeed['rows'] ?? []);
        }

        $mergedRows = array_values(array_filter(
            $existingRows,
            static fn (array $row): bool => trim((string) ($row['group_id'] ?? '')) !== $groupId
        ));

        foreach ($domainStats as $metricCode => $stat) {
            $mergedRows[] = $this->seedRow(
                $normsVersion,
                $locale,
                $region,
                $groupId,
                $gender,
                $ageMin,
                $ageMax,
                'domain',
                $metricCode,
                (float) ($stat['mean'] ?? 0.0),
                (float) ($stat['sd'] ?? 0.0),
                (int) ($stat['sample_n'] ?? 0),
                $sourceId,
                $sourceType,
                $status,
                $publishedAt
            );
        }
        foreach ($facetStats as $metricCode => $stat) {
            $mergedRows[] = $this->seedRow(
                $normsVersion,
                $locale,
                $region,
                $groupId,
                $gender,
                $ageMin,
                $ageMax,
                'facet',
                $metricCode,
                (float) ($stat['mean'] ?? 0.0),
                (float) ($stat['sd'] ?? 0.0),
                (int) ($stat['sample_n'] ?? 0),
                $sourceId,
                $sourceType,
                $status,
                $publishedAt
            );
        }

        usort($mergedRows, static function (array $left, array $right): int {
            $leftLevel = strtolower((string) ($left['metric_level'] ?? ''));
            $rightLevel = strtolower((string) ($right['metric_level'] ?? ''));
            $leftKey = sprintf(
                '%s|%d|%s',
                (string) ($left['group_id'] ?? ''),
                $leftLevel === 'domain' ? 0 : 1,
                (string) ($left['metric_code'] ?? '')
            );
            $rightKey = sprintf(
                '%s|%d|%s',
                (string) ($right['group_id'] ?? ''),
                $rightLevel === 'domain' ? 0 : 1,
                (string) ($right['metric_code'] ?? '')
            );

            return $leftKey <=> $rightKey;
        });

        $computeSpecHash = $this->computeSpecHash((string) config('big5_norms.bootstrap.hash_algo', 'sha256'));
        $csvWritten = false;
        if (!$dryRun) {
            $csvWritten = $this->writeCsv($outputPath, self::REQUIRED_HEADERS, $mergedRows);
            if (!$csvWritten) {
                $this->error("failed to write output csv: {$outputPath}");

                return 1;
            }
        }

        $outputHash = $dryRun
            ? hash(
                (string) config('big5_norms.bootstrap.hash_algo', 'sha256'),
                json_encode($mergedRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            )
            : hash_file((string) config('big5_norms.bootstrap.hash_algo', 'sha256'), $outputPath);

        $artifactPayload = [
            'scale_code' => self::SCALE_CODE,
            'norms_version' => $normsVersion,
            'source_id' => $sourceId,
            'source_type' => $sourceType,
            'pack_locale' => $locale,
            'group_id' => $groupId,
            'sample_n_raw' => (int) ($build['sample_n_raw'] ?? 0),
            'sample_n_kept' => (int) ($build['sample_n_kept'] ?? 0),
            'filters_applied' => [
                'quality_levels' => $qualityFilters,
                'source_mode' => $sourceMode,
                'input_path' => $inputPath,
            ],
            'compute_spec_hash' => $computeSpecHash,
            'output_csv_sha256' => (string) $outputHash,
            'output_csv_path' => $outputPath,
        ];

        if (!$dryRun) {
            $artifactPath = $this->resolveArtifactPath((string) $this->option('artifact'), $normsVersion, $groupId);
            $artifactDir = dirname($artifactPath);
            if (!is_dir($artifactDir)) {
                File::makeDirectory($artifactDir, 0755, true);
            }
            File::put(
                $artifactPath,
                json_encode(
                    array_merge($artifactPayload, [
                        'created_at' => now()->toIso8601String(),
                    ]),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            if (Schema::hasTable('norms_build_artifacts')) {
                NormsBuildArtifact::query()->create(array_merge($artifactPayload, [
                    'id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $this->info("artifact_written={$artifactPath}");
        }

        $this->info(sprintf(
            'bootstrap built source=%s locale=%s group=%s coverage=35/35 sample_n_raw=%d sample_n_kept=%d',
            $source,
            $locale,
            $groupId,
            (int) ($build['sample_n_raw'] ?? 0),
            (int) ($build['sample_n_kept'] ?? 0)
        ));
        $this->line("compute_spec_hash={$computeSpecHash}");
        $this->line("output_csv_sha256={$outputHash}");
        if ($dryRun) {
            $this->line('dry-run=1, no file/db writes performed.');
        } elseif ($csvWritten) {
            $this->line("output_written={$outputPath}");
        }

        return 0;
    }

    /**
     * @param list<string> $headers
     * @param list<array<string,mixed>> $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }

        try {
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                $ordered = [];
                foreach ($headers as $header) {
                    $ordered[] = $row[$header] ?? '';
                }
                fputcsv($handle, $ordered);
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * @param list<string>|null $requiredHeaders
     * @return array{ok: bool, rows?: list<array<string,string>>, errors?: list<string>}
     */
    private function readCsv(string $path, ?array $requiredHeaders = null): array
    {
        if (!is_file($path)) {
            return [
                'ok' => false,
                'errors' => ["csv not found: {$path}"],
            ];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [
                'ok' => false,
                'errors' => ["csv not readable: {$path}"],
            ];
        }

        try {
            $header = fgetcsv($handle);
            if (!is_array($header) || $header === []) {
                return [
                    'ok' => false,
                    'errors' => ["csv missing header: {$path}"],
                ];
            }
            $header = array_map(static fn ($value): string => trim((string) $value), $header);
            if (is_array($requiredHeaders)) {
                foreach ($requiredHeaders as $required) {
                    if (!in_array($required, $header, true)) {
                        return [
                            'ok' => false,
                            'errors' => ["csv missing header {$required}: {$path}"],
                        ];
                    }
                }
            }

            $rows = [];
            while (($raw = fgetcsv($handle)) !== false) {
                if (!is_array($raw)) {
                    continue;
                }
                $assoc = [];
                foreach ($header as $index => $column) {
                    $assoc[$column] = array_key_exists($index, $raw) ? trim((string) $raw[$index]) : '';
                }
                $rows[] = $assoc;
            }

            return [
                'ok' => true,
                'rows' => $rows,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{ok: bool, rows?: list<array<string,string>>, errors?: list<string>}
     */
    private function readFallbackNormRows(string $path, string $groupId): array
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            return [
                'ok' => false,
                'errors' => ['fallback_group_id is required in source profile'],
            ];
        }

        $parsed = $this->readCsv($path);
        if (!($parsed['ok'] ?? false)) {
            return $parsed;
        }

        $rows = array_values(array_filter(
            (array) ($parsed['rows'] ?? []),
            static fn (array $row): bool => trim((string) ($row['group_id'] ?? '')) === $groupId
        ));
        if ($rows === []) {
            return [
                'ok' => false,
                'errors' => ["no fallback rows for group_id={$groupId} in {$path}"],
            ];
        }

        return [
            'ok' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function seedRow(
        string $normsVersion,
        string $locale,
        string $region,
        string $groupId,
        string $gender,
        int $ageMin,
        int $ageMax,
        string $metricLevel,
        string $metricCode,
        float $mean,
        float $sd,
        int $sampleN,
        string $sourceId,
        string $sourceType,
        string $status,
        string $publishedAt
    ): array {
        return [
            'scale_code' => self::SCALE_CODE,
            'norms_version' => $normsVersion,
            'locale' => $locale,
            'region' => $region,
            'group_id' => $groupId,
            'gender' => $gender,
            'age_min' => (string) max(1, $ageMin),
            'age_max' => (string) max($ageMin, $ageMax),
            'metric_level' => strtolower($metricLevel),
            'metric_code' => strtoupper($metricCode),
            'mean' => number_format($mean, 3, '.', ''),
            'sd' => number_format(max($sd, 0.0001), 3, '.', ''),
            'sample_n' => (string) max(1, $sampleN),
            'source_id' => $sourceId,
            'source_type' => $sourceType,
            'status' => $status,
            'is_active' => '1',
            'published_at' => $publishedAt,
        ];
    }

    private function resolveArtifactPath(string $optionPath, string $normsVersion, string $groupId): string
    {
        $rawPath = trim($optionPath);
        if ($rawPath !== '') {
            return $this->resolvePath($rawPath);
        }

        $dir = trim((string) config('big5_norms.bootstrap.artifact_output_dir', base_path('resources/norms/big5/build_artifacts')));
        if ($dir === '') {
            $dir = base_path('resources/norms/big5/build_artifacts');
        }

        return rtrim($this->resolvePath($dir), '/').'/'.sprintf(
            '%s__%s.json',
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $normsVersion),
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $groupId)
        );
    }

    private function resolvePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }
        if (str_starts_with($trimmed, 'backend/')) {
            return base_path(substr($trimmed, strlen('backend/')));
        }

        return base_path($trimmed);
    }

    private function resolvePathFromProfile(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        $root = trim((string) config('big5_norms.bootstrap.source_root', storage_path('app/norm_sources/big5')));
        if ($root === '') {
            $root = storage_path('app/norm_sources/big5');
        }
        if (!str_starts_with($root, '/')) {
            $root = $this->resolvePath($root);
        }

        return rtrim($root, '/').'/'.$trimmed;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function computeSpecHash(string $algo): string
    {
        $policyPath = base_path('content_packs/BIG5_OCEAN/v1/raw/policy.json');
        $policyRaw = is_file($policyPath) ? (string) file_get_contents($policyPath) : '{}';
        $policy = json_decode($policyRaw, true);
        if (!is_array($policy)) {
            $policy = [];
        }

        $fingerprint = [
            'scale_code' => self::SCALE_CODE,
            'engine_version' => (string) ($policy['engine_version'] ?? ''),
            'spec_version' => (string) ($policy['spec_version'] ?? ''),
            'item_bank_version' => (string) ($policy['item_bank_version'] ?? ''),
            'bucket_thresholds' => (array) ($policy['bucket_thresholds'] ?? []),
            'profile_rules' => (array) ($policy['profile_rules'] ?? []),
        ];

        return hash($algo, json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
