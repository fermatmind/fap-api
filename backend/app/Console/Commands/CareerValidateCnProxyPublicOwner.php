<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidateCnProxyPublicOwner extends Command
{
    private const EXPECTED_CN_PROXY_ROWS = 1663;

    protected $signature = 'career:validate-cn-proxy-public-owner
        {--scope= : Phase 2C CN proxy scope JSON artifact}
        {--dry-run : Validate without writing}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}';

    protected $description = 'Validate guarded CN proxy public owner state without exposing CN Career pages.';

    public function handle(): int
    {
        try {
            $scopePath = $this->resolveScopePath();
            $rows = $this->loadRows($scopePath);
            $summary = $this->summarizeRows($rows);

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-cn-proxy-public-owner',
                'dry_run' => true,
                'did_write' => false,
                'scope_path' => $scopePath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'cn_proxy_rows' => count($rows),
                'route_owner_enabled' => false,
                'public_pages_exposed' => 0,
                'public_route_allowed' => false,
                'guarded_public_owner_state' => 'disabled_until_CN_authority_policy_trust_manifest_disclaimer_and_release_gate',
                'ledger_decision_required' => true,
                'CN_authority_policy_required' => true,
                'trust_manifest_required' => true,
                'disclaimer_required' => true,
                'release_gate_approval_required' => true,
                'rejects_rows_without_ledger_decision' => true,
                'rejects_rows_without_trust_manifest' => true,
                'rejects_rows_without_disclaimer' => true,
                'CN_proxy_can_masquerade_as_US_canonical_job' => false,
                'US_canonical_job_schema_returned' => false,
                'noindex_default' => true,
                'indexable_CN_proxy_rows' => 0,
                'sitemap_CN_urls' => 0,
                'llms_CN_urls' => 0,
                'llms_full_CN_urls' => 0,
                'display_asset_delta' => 0,
                'career_job_display_assets_delta' => 0,
                'occupations_delta' => 0,
                'occupation_crosswalks_delta' => 0,
                'blockers' => [],
            ];

            $payload['blockers'] = array_values(array_filter([
                count($rows) === self::EXPECTED_CN_PROXY_ROWS ? null : 'unexpected_CN_proxy_row_count',
                $summary['non_cn_proxy_rows'] === 0 ? null : 'non_CN_proxy_rows_in_scope',
                $summary['public_candidate_rows'] === 0 ? null : 'CN_proxy_public_resolution_present_before_public_owner_policy',
                $summary['missing_disclaimer_rows'] === 0 ? null : 'CN_proxy_missing_disclaimer_requirement',
                $summary['missing_trust_manifest_rows'] === 0 ? null : 'CN_proxy_missing_trust_manifest_requirement',
            ]));

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload);

            return $payload['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:validate-cn-proxy-public-owner',
                'message' => $throwable->getMessage(),
                'blockers' => [$throwable->getMessage()],
            ];

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload, error: true);

            return self::FAILURE;
        }
    }

    private function resolveScopePath(): string
    {
        $value = $this->option('scope');
        if ($value !== null && trim((string) $value) !== '') {
            $path = trim((string) $value);
            if (! is_file($path)) {
                throw new \RuntimeException('career CN proxy scope artifact not found: '.$path);
            }

            return $path;
        }

        $defaultPath = '/tmp/career_phase2c_cn_proxy_scope.json';
        if (is_file($defaultPath)) {
            return $defaultPath;
        }

        throw new \RuntimeException('career CN proxy scope artifact path is required');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career CN proxy scope artifact is not valid JSON: '.$path);
        }

        $rows = data_get($payload, 'rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'cn_proxy_scope.rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career CN proxy scope artifact has no rows: '.$path);
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
            'non_cn_proxy_rows' => 0,
            'public_candidate_rows' => 0,
            'missing_disclaimer_rows' => 0,
            'missing_trust_manifest_rows' => 0,
        ];

        foreach ($rows as $row) {
            if ((string) ($row['current_status'] ?? '') !== 'CN_proxy_hold') {
                $summary['non_cn_proxy_rows']++;
            }

            $recommended = (string) ($row['recommended_resolution'] ?? '');
            if (in_array($recommended, ['public_canonical_job', 'public_cn_proxy_page_candidate', 'public_cn_proxy_page'], true)) {
                $summary['public_candidate_rows']++;
            }

            if (! (bool) ($row['disclaimer_required'] ?? false)) {
                $summary['missing_disclaimer_rows']++;
            }
            if (! (bool) ($row['trust_manifest_required'] ?? false)) {
                $summary['missing_trust_manifest_rows']++;
            }
        }

        return $summary;
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for CN proxy public owner validation');
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
            $this->error((string) ($payload['message'] ?? 'CN proxy public owner validation failed'));

            return;
        }

        $this->line('status='.(string) $payload['status']);
        $this->line('cn_proxy_rows='.(string) $payload['cn_proxy_rows']);
        $this->line('public_pages_exposed=0');
        $this->line('did_write=false');
    }
}
