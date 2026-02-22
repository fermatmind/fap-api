<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use App\Services\Psychometrics\Big5\Bootstrap\Big5BootstrapCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Big5BootstrapBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_build_command_writes_seed_and_artifact_row(): void
    {
        $inputPath = storage_path('app/testing/big5_bootstrap_input.csv');
        $outputPath = storage_path('app/testing/big5_bootstrap_seed.csv');
        $artifactPath = storage_path('app/testing/big5_bootstrap_artifact.json');
        $this->writeAttemptInput($inputPath);

        if (is_file($outputPath)) {
            unlink($outputPath);
        }
        if (is_file($artifactPath)) {
            unlink($artifactPath);
        }

        $this->artisan(sprintf(
            'norms:big5:bootstrap:build --source=johnson_osf --locale=en --input=%s --out=%s --artifact=%s',
            $inputPath,
            $outputPath,
            $artifactPath
        ))->assertExitCode(0);

        $this->assertFileExists($outputPath);
        $this->assertFileExists($artifactPath);

        $rows = $this->readCsv($outputPath);
        $this->assertCount(35, $rows);
        foreach ($rows as $row) {
            $this->assertSame('en_johnson_all_18-60', (string) ($row['group_id'] ?? ''));
            $this->assertSame('2026Q1_bootstrap_v1', (string) ($row['norms_version'] ?? ''));
            $this->assertTrue(in_array((string) ($row['metric_level'] ?? ''), ['domain', 'facet'], true));
        }

        $artifact = DB::table('norms_build_artifacts')->first();
        $this->assertNotNull($artifact);
        $this->assertSame('en_johnson_all_18-60', (string) $artifact->group_id);
        $this->assertSame('GLOBAL_IPIPNEO_JOHNSON_ARCHIVE', (string) $artifact->source_id);
        $this->assertSame(2, (int) $artifact->sample_n_raw);
        $this->assertSame(2, (int) $artifact->sample_n_kept);
        $this->assertSame(64, strlen((string) $artifact->compute_spec_hash));
        $this->assertSame(64, strlen((string) $artifact->output_csv_sha256));
    }

    private function writeAttemptInput(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $headers = array_merge(['quality_level'], Big5BootstrapCalculator::metricCodes());
        $rowA = ['quality_level' => 'A'];
        $rowB = ['quality_level' => 'B'];
        foreach (Big5BootstrapCalculator::metricCodes() as $metricCode) {
            $rowA[$metricCode] = '3.0';
            $rowB[$metricCode] = '4.0';
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            self::fail('failed to open bootstrap input file for writing');
        }
        try {
            fputcsv($handle, $headers);
            $orderedA = [];
            $orderedB = [];
            foreach ($headers as $header) {
                $orderedA[] = $rowA[$header] ?? '';
                $orderedB[] = $rowB[$header] ?? '';
            }
            fputcsv($handle, $orderedA);
            fputcsv($handle, $orderedB);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array<string,string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            self::fail('failed to read output csv');
        }

        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
                return [];
            }
            $header = array_map(static fn ($value): string => trim((string) $value), $header);

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

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
