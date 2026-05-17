<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerPublicTrustTaxonomyExporter;
use App\Support\SafeArtifactDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportPublicTrustTaxonomy extends Command
{
    protected $signature = 'career:export-public-trust-taxonomy
        {--timestamp= : Optional output directory timestamp segment}
        {--output= : Optional explicit JSON output path}
        {--limit= : Optional row limit for local smoke checks}
        {--json : Emit JSON output}';

    protected $description = 'Export read-only Career public trust taxonomy for Phase 5B-FU3 policy reconciliation.';

    public function __construct(
        private readonly CareerPublicTrustTaxonomyExporter $exporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $taxonomy = $this->exporter->build($this->limitOption());
            $path = $this->writeTaxonomy($taxonomy);

            $payload = [
                'status' => 'materialized',
                'artifact' => $path,
                'exportStatus' => $taxonomy['exportStatus'] ?? 'unknown',
                'counts' => $taxonomy['counts'] ?? [],
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('artifact='.$path);
            $this->line('exportStatus='.(string) ($taxonomy['exportStatus'] ?? 'unknown'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function limitOption(): ?int
    {
        $value = $this->option('limit');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $limit = (int) $value;
        if ($limit <= 0) {
            throw new \RuntimeException('limit must be a positive integer');
        }

        return $limit;
    }

    /**
     * @param  array<string, mixed>  $taxonomy
     */
    private function writeTaxonomy(array $taxonomy): string
    {
        $encoded = json_encode($taxonomy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode Career public trust taxonomy payload');
        }

        $output = $this->outputPathOption();
        if ($output !== null) {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, $encoded.PHP_EOL);

            return $output;
        }

        $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
        $rootDir = storage_path('app/private/career_public_trust_taxonomy');
        $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = SafeArtifactDirectory::createTemporaryDirectory($rootDir, $finalDir);
        $path = $tmpDir.DIRECTORY_SEPARATOR.CareerPublicTrustTaxonomyExporter::TAXONOMY_FILENAME;
        File::put($path, $encoded.PHP_EOL);
        SafeArtifactDirectory::finalize($tmpDir, $finalDir);

        return $finalDir.DIRECTORY_SEPARATOR.CareerPublicTrustTaxonomyExporter::TAXONOMY_FILENAME;
    }

    private function outputPathOption(): ?string
    {
        $value = $this->option('output');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $path = trim((string) $value);
        if (str_contains($path, "\0")) {
            throw new \RuntimeException('output path contains invalid null byte');
        }

        return $path;
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $normalized = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for Career public trust taxonomy export');
        }

        return $normalized;
    }
}
