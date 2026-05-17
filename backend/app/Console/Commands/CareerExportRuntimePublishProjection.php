<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Support\SafeArtifactDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportRuntimePublishProjection extends Command
{
    protected $signature = 'career:export-runtime-publish-projection
        {--timestamp= : Optional output directory timestamp segment}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Export the Career runtime publish projection from the Career full release ledger authority.';

    public function __construct(
        private readonly CareerRuntimePublishProjectionExporter $exporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_runtime_publish_projection');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = SafeArtifactDirectory::createTemporaryDirectory($rootDir, $finalDir);

            $projection = $this->exporter->build($this->ledgerPathOption());

            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
            $encoded = json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode runtime publish projection payload');
            }
            File::put($path, $encoded.PHP_EOL);

            SafeArtifactDirectory::finalize($tmpDir, $finalDir);

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME => $path = $finalDir.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
                ],
                'projection' => $projection,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-runtime-publish-projection='.$path);

            return self::SUCCESS;
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
            throw new \RuntimeException('invalid timestamp segment for runtime publish projection export');
        }

        return $normalized;
    }

    private function ledgerPathOption(): ?string
    {
        $value = $this->option('ledger');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
