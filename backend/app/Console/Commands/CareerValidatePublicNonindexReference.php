<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidatePublicNonindexReference extends Command
{
    protected $signature = 'career:validate-public-nonindex-reference
        {--ledger= : Full release ledger JSON artifact}
        {--dry-run : Validate without writing}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}';

    protected $description = 'Validate Career public_nonindex_reference governance without creating public URLs.';

    public function handle(): int
    {
        try {
            $ledgerPath = $this->resolveLedgerPath();
            $rows = $this->loadRows($ledgerPath);
            $summary = $this->summarizeRows($rows);

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-public-nonindex-reference',
                'dry_run' => true,
                'did_write' => false,
                'ledger_path' => $ledgerPath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'ledger_rows' => count($rows),
                'public_nonindex_reference_rows' => $summary['public_nonindex_reference_rows'],
                'active_public_nonindex_reference_urls' => $summary['public_nonindex_reference_rows'],
                'public_urls_created' => 0,
                'sitemap_nonindex_reference_urls' => 0,
                'llms_nonindex_reference_urls' => 0,
                'llms_full_nonindex_reference_urls' => 0,
                'ledger_decision_required' => true,
                'noindex_required' => true,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'manifest_eligible' => false,
                'held_rows_can_use_nonindex_as_bypass' => false,
                'software_developers_can_use_nonindex_without_manual_decision' => false,
                'blockers' => [],
            ];

            $payload['blockers'] = array_values(array_filter([
                $summary['missing_public_resolution_type_public_rows'] === 0 ? null : 'public_row_missing_public_resolution_type',
                $summary['unknown_public_resolution_type_rows'] === 0 ? null : 'unknown_public_resolution_type',
                $summary['nonindex_rows_without_public_eligibility'] === 0 ? null : 'public_nonindex_reference_without_public_eligibility',
                $summary['nonindex_rows_without_noindex'] === 0 ? null : 'public_nonindex_reference_without_noindex',
                $summary['nonindex_sitemap_eligible_rows'] === 0 ? null : 'public_nonindex_reference_sitemap_eligible',
                $summary['nonindex_llms_eligible_rows'] === 0 ? null : 'public_nonindex_reference_llms_eligible',
                $summary['nonindex_llms_full_eligible_rows'] === 0 ? null : 'public_nonindex_reference_llms_full_eligible',
                $summary['held_nonindex_reference_rows'] === 0 ? null : 'held_row_public_nonindex_reference_bypass',
                $summary['software_developers_nonindex_reference_rows'] === 0 ? null : 'software_developers_public_nonindex_reference_without_manual_decision',
            ]));

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload);

            return $payload['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:validate-public-nonindex-reference',
                'message' => $throwable->getMessage(),
                'blockers' => [$throwable->getMessage()],
            ];

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload, error: true);

            return self::FAILURE;
        }
    }

    private function resolveLedgerPath(): string
    {
        $value = $this->option('ledger');
        if ($value !== null && trim((string) $value) !== '') {
            $path = trim((string) $value);
            if (! is_file($path)) {
                throw new \RuntimeException('career full release ledger artifact not found: '.$path);
            }

            return $path;
        }

        throw new \RuntimeException('career full release ledger artifact path is required');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career full release ledger artifact is not valid JSON: '.$path);
        }

        $rows = data_get($payload, 'public_resolution.rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career full release ledger artifact has no public resolution rows: '.$path);
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarizeRows(array $rows): array
    {
        $summary = [
            'public_nonindex_reference_rows' => 0,
            'missing_public_resolution_type_public_rows' => 0,
            'unknown_public_resolution_type_rows' => 0,
            'nonindex_rows_without_public_eligibility' => 0,
            'nonindex_rows_without_noindex' => 0,
            'nonindex_sitemap_eligible_rows' => 0,
            'nonindex_llms_eligible_rows' => 0,
            'nonindex_llms_full_eligible_rows' => 0,
            'held_nonindex_reference_rows' => 0,
            'software_developers_nonindex_reference_rows' => 0,
        ];

        $allowedTypes = array_flip(CareerPublicResolutionTypeMatrix::allowedTypes());
        foreach ($rows as $row) {
            $type = $this->nullableString($row['public_resolution_type'] ?? null);
            if ((bool) ($row['public_eligible'] ?? false) && $type === null) {
                $summary['missing_public_resolution_type_public_rows']++;
            }
            if ($type !== null && ! isset($allowedTypes[$type])) {
                $summary['unknown_public_resolution_type_rows']++;
            }
            if ($type !== CareerPublicResolutionTypeMatrix::PUBLIC_NONINDEX_REFERENCE) {
                continue;
            }

            $summary['public_nonindex_reference_rows']++;
            if (! (bool) ($row['public_eligible'] ?? false)) {
                $summary['nonindex_rows_without_public_eligibility']++;
            }
            if (! in_array((string) ($row['indexability'] ?? ''), ['noindex', 'no_independent_index'], true)) {
                $summary['nonindex_rows_without_noindex']++;
            }
            if ((bool) ($row['sitemap_eligible'] ?? false)) {
                $summary['nonindex_sitemap_eligible_rows']++;
            }
            if ((bool) ($row['llms_eligible'] ?? false)) {
                $summary['nonindex_llms_eligible_rows']++;
            }
            if ((bool) ($row['llms_full_eligible'] ?? false)) {
                $summary['nonindex_llms_full_eligible_rows']++;
            }
            if (in_array((string) ($row['current_status'] ?? ''), ['duplicate_identity_hold', 'CN_proxy_hold', 'broad_group_hold', 'manual_hold'], true)) {
                $summary['held_nonindex_reference_rows']++;
            }
            if ((string) ($row['source_slug'] ?? '') === 'software-developers') {
                $summary['software_developers_nonindex_reference_rows']++;
            }
        }

        return $summary;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for public nonindex reference validation');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeOutputArtifact(array $payload): void
    {
        $output = $this->option('output');
        if ($output === null || trim((string) $output) === '') {
            return;
        }

        $path = trim((string) $output);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitPayload(array $payload, bool $error = false): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return;
        }

        if ($error) {
            $this->error((string) ($payload['message'] ?? 'public nonindex reference validation failed'));

            return;
        }

        $this->line('status='.(string) $payload['status']);
        $this->line('public_nonindex_reference_rows='.(string) $payload['public_nonindex_reference_rows']);
        $this->line('sitemap_nonindex_reference_urls=0');
        $this->line('llms_nonindex_reference_urls=0');
        $this->line('did_write=false');
    }
}
