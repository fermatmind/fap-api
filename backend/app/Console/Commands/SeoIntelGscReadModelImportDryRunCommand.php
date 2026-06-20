<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\GscReadModelArtifactDryRunImporter;
use Illuminate\Console\Command;
use Throwable;

final class SeoIntelGscReadModelImportDryRunCommand extends Command
{
    protected $signature = 'seo-intel:gsc-readmodel-import-dry-run
        {--artifact= : Path to a sanitized GSC sidecar artifact}
        {--limit=250 : Preview row limit, bounded 1..250}
        {--dry-run : Required; this command never writes}
        {--json : Emit JSON summary}';

    protected $description = 'Validate a sanitized GSC sidecar artifact and preview future seo_gsc_daily rows without writing.';

    public function handle(GscReadModelArtifactDryRunImporter $importer): int
    {
        if (! (bool) $this->option('dry-run')) {
            $summary = $this->failureSummary('dry_run_required');
            $this->emitSummary($summary);

            return self::FAILURE;
        }

        $path = $this->artifactPath();
        if ($path === null) {
            $summary = $this->failureSummary('artifact_path_required');
            $this->emitSummary($summary);

            return self::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $summary = $this->failureSummary('artifact_json_invalid');
            $this->emitSummary($summary);

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $summary = $this->failureSummary('artifact_must_be_object');
            $this->emitSummary($summary);

            return self::FAILURE;
        }

        $summary = $importer->preview($decoded, $this->limit());
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function artifactPath(): ?string
    {
        $path = trim((string) $this->option('artifact'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) ? $path : null;
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 250;
        }

        return max(1, min((int) $raw, 250));
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $code): array
    {
        return [
            'schema_version' => 'gsc-readmodel-importer-dryrun.v1',
            'task' => 'SEO-GSC-READMODEL-IMPORTER-DRYRUN-01',
            'ok' => false,
            'dry_run' => true,
            'would_write' => false,
            'target_table' => 'seo_gsc_daily',
            'rows_previewed' => 0,
            'rows_would_insert' => 0,
            'preview_rows' => [],
            'errors' => [$code],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? 'true' : 'false'));
        $this->line('dry_run=true');
        $this->line('would_write=false');
        $this->line('target_table=seo_gsc_daily');
        $this->line('rows_previewed='.(string) ($summary['rows_previewed'] ?? 0));
        $this->line('rows_would_insert='.(string) ($summary['rows_would_insert'] ?? 0));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
