<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalExpansionManifestExporter;
use App\Domain\Career\Expansion\CanonicalExpansionManifestValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportCanonicalExpansionManifest extends Command
{
    protected $signature = 'career:export-canonical-expansion-manifest
        {--timestamp= : Optional output directory timestamp segment}
        {--batch-id= : Optional canonical expansion batch id}
        {--batch-size=50 : Maximum unique slugs in the manifest}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--projection= : Optional Career runtime publish projection JSON artifact}
        {--truth= : Optional Career canonical runtime truth JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Export a read-only Career canonical expansion manifest from canonical runtime truth candidates.';

    public function __construct(
        private readonly CanonicalExpansionManifestExporter $exporter,
        private readonly CanonicalExpansionManifestValidator $validator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_canonical_expansion_manifest');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('canonical expansion manifest output dir already exists: '.$finalDir);
            }

            $manifest = $this->exporter->build(
                truthPath: $this->pathOption('truth'),
                projectionPath: $this->pathOption('projection'),
                ledgerPath: $this->pathOption('ledger'),
                batchSize: (int) $this->option('batch-size'),
                batchId: $this->pathOption('batch-id'),
            );
            $validation = $this->validator->validate($manifest);

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CanonicalExpansionManifestExporter::MANIFEST_FILENAME;
            $encoded = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode canonical expansion manifest payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize canonical expansion manifest output dir: '.$finalDir);
            }

            $payload = [
                'status' => $validation['status'] === 'pass' ? 'materialized' : 'blocked',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CanonicalExpansionManifestExporter::MANIFEST_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CanonicalExpansionManifestExporter::MANIFEST_FILENAME,
                ],
                'validation' => $validation,
                'manifest' => $manifest,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('output_dir='.$finalDir);
                $this->line('candidate_slugs='.(string) data_get($manifest, 'counts.candidate_slugs', 0));
                $this->line('failures='.(string) data_get($validation, 'counts.failures', 0));
            }

            return $validation['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $normalized = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for canonical expansion manifest export');
        }

        return $normalized;
    }

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
