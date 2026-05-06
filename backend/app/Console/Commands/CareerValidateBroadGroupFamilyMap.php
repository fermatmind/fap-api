<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidateBroadGroupFamilyMap extends Command
{
    private const EXPECTED_BROAD_GROUP_ROWS = 75;

    protected $signature = 'career:validate-broad-group-family-map
        {--scope= : Phase 2B broad group scope JSON artifact}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}';

    protected $description = 'Validate the no-op Career broad group family map before any public family hub materialization.';

    public function handle(): int
    {
        try {
            $scopePath = $this->resolveScopePath();
            $rows = $this->loadRows($scopePath);

            $approvedFamilyHubs = array_values(array_filter($rows, static function (array $row): bool {
                return (string) ($row['recommended_decision'] ?? '') === 'public_family_hub';
            }));

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-broad-group-family-map',
                'dry_run' => true,
                'did_write' => false,
                'scope_path' => $scopePath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'broad_group_rows' => count($rows),
                'approved_family_hubs' => count($approvedFamilyHubs),
                'family_hubs_to_create' => 0,
                'family_hubs_to_update' => 0,
                'active_family_hubs' => 0,
                'display_asset_delta' => 0,
                'career_job_display_assets' => 793,
                'sitemap_family_urls' => 0,
                'llms_family_urls' => 0,
                'llms_full_family_urls' => 0,
                'required_future_fields' => [
                    'family_hub_slug',
                    'child_canonical_slugs',
                    'schema_policy',
                    'indexability',
                    'sitemap_eligible',
                    'llms_eligible',
                    'llms_full_eligible',
                    'trust_manifest_required',
                    'boundary_disclaimer_required',
                    'reviewer',
                    'approved_at',
                    'rollback_condition',
                ],
                'blockers' => [],
            ];

            $payload['blockers'] = array_values(array_filter([
                count($rows) === self::EXPECTED_BROAD_GROUP_ROWS ? null : 'unexpected_broad_group_row_count',
                count($approvedFamilyHubs) === 0 ? null : 'approved_family_hubs_present_before_policy',
            ]));

            $this->writeOutputArtifact($payload);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('broad_group_rows='.$payload['broad_group_rows']);
                $this->line('approved_family_hubs='.$payload['approved_family_hubs']);
                $this->line('did_write=false');
            }

            return $payload['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:validate-broad-group-family-map',
                'message' => $throwable->getMessage(),
                'blockers' => [$throwable->getMessage()],
            ];

            $this->writeOutputArtifact($payload);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->error($throwable->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function resolveScopePath(): string
    {
        $value = $this->option('scope');
        if ($value !== null && trim((string) $value) !== '') {
            $path = trim((string) $value);
            if (! is_file($path)) {
                throw new \RuntimeException('career broad group scope artifact not found: '.$path);
            }

            return $path;
        }

        throw new \RuntimeException('career broad group scope artifact path is required');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career broad group scope artifact is not valid JSON: '.$path);
        }

        $rows = data_get($payload, 'rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'broad_group_scope.rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career broad group scope artifact has no rows: '.$path);
        }

        return array_values(array_filter($rows, static function (mixed $row): bool {
            return is_array($row) && (string) ($row['current_status'] ?? '') === 'broad_group_hold';
        }));
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for broad group family map validation');
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
}
