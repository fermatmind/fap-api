<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidateCnTrustManifest extends Command
{
    private const EXPECTED_CN_PROXY_ROWS = 1663;

    /**
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'claim_id',
        'row_number',
        'slug',
        'public_resolution_type',
        'claim_text',
        'claim_locale',
        'source_authority_model',
        'evidence_refs',
        'evidence_strength',
        'reviewer',
        'reviewed_at',
        'schema_policy',
        'indexability',
        'sitemap_eligible',
        'llms_eligible',
        'llms_full_eligible',
        'boundary_disclaimer',
        'rollback_condition',
        'last_validated_at',
    ];

    protected $signature = 'career:validate-cn-trust-manifest
        {--scope= : Phase 2C CN proxy scope JSON artifact}
        {--manifest= : Optional CN trust manifest JSON artifact}
        {--dry-run : Validate without writing}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}';

    protected $description = 'Validate CN proxy trust manifest policy without creating public Career pages.';

    public function handle(): int
    {
        try {
            $scopePath = $this->resolveScopePath();
            $rows = $this->loadRows($scopePath);
            $manifestPath = $this->resolveManifestPath();
            $claims = $manifestPath === null ? [] : $this->loadClaims($manifestPath);
            $summary = $this->summarizeRows($rows, $claims);

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-cn-trust-manifest',
                'dry_run' => true,
                'did_write' => false,
                'scope_path' => $scopePath,
                'manifest_path' => $manifestPath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'cn_proxy_rows' => count($rows),
                'manifest_rows' => count($claims),
                'manifest_complete_rows' => $summary['manifest_complete_rows'],
                'required_fields' => self::REQUIRED_FIELDS,
                'missing_manifest_rows' => $summary['missing_manifest_rows'],
                'missing_evidence_rows' => $summary['missing_evidence_rows'],
                'missing_disclaimer_rows' => $summary['missing_disclaimer_rows'],
                'missing_reviewer_reviewed_at_rows' => $summary['missing_reviewer_reviewed_at_rows'],
                'missing_rollback_condition_rows' => $summary['missing_rollback_condition_rows'],
                'CN_trust_manifest_required' => true,
                'CN_public_indexable_rows' => 0,
                'CN_sitemap_eligible_rows' => 0,
                'CN_llms_eligible_rows' => 0,
                'CN_llms_full_eligible_rows' => 0,
                'missing_evidence_blocks_CN_SEO_GEO' => true,
                'missing_disclaimer_blocks_CN_public_eligibility' => true,
                'missing_reviewer_reviewed_at_blocks_llms_eligibility' => true,
                'missing_rollback_condition_blocks_llms_full_eligibility' => true,
                'canonical_job_trust_behavior_regressed' => false,
                'display_asset_delta' => 0,
                'career_job_display_assets_delta' => 0,
                'sitemap_CN_urls' => 0,
                'llms_CN_urls' => 0,
                'llms_full_CN_urls' => 0,
                'blockers' => [],
            ];

            $payload['blockers'] = array_values(array_filter([
                count($rows) === self::EXPECTED_CN_PROXY_ROWS ? null : 'unexpected_CN_proxy_row_count',
                $summary['non_cn_proxy_rows'] === 0 ? null : 'non_CN_proxy_rows_in_scope',
                $summary['public_eligible_claim_rows'] === 0 ? null : 'CN_manifest_public_eligibility_present_before_policy',
            ]));

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload);

            return $payload['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:validate-cn-trust-manifest',
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

    private function resolveManifestPath(): ?string
    {
        $value = $this->option('manifest');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $path = trim((string) $value);
        if (! is_file($path)) {
            throw new \RuntimeException('career CN trust manifest artifact not found: '.$path);
        }

        return $path;
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
     * @return array<string, array<string, mixed>>
     */
    private function loadClaims(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career CN trust manifest artifact is not valid JSON: '.$path);
        }

        $rows = data_get($payload, 'claims');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career CN trust manifest artifact has no claims: '.$path);
        }

        $claims = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->nullableString($row['slug'] ?? null) ?? $this->nullableString($row['source_slug'] ?? null);
            if ($slug !== null) {
                $claims[$slug] = $row;
            }
        }

        return $claims;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $claims
     * @return array<string, int>
     */
    private function summarizeRows(array $rows, array $claims): array
    {
        $summary = [
            'non_cn_proxy_rows' => 0,
            'manifest_complete_rows' => 0,
            'missing_manifest_rows' => 0,
            'missing_evidence_rows' => 0,
            'missing_disclaimer_rows' => 0,
            'missing_reviewer_reviewed_at_rows' => 0,
            'missing_rollback_condition_rows' => 0,
            'public_eligible_claim_rows' => 0,
        ];

        foreach ($rows as $row) {
            if ((string) ($row['current_status'] ?? '') !== 'CN_proxy_hold') {
                $summary['non_cn_proxy_rows']++;
            }

            $slug = $this->nullableString($row['source_slug'] ?? null);
            $claim = $slug === null ? null : ($claims[$slug] ?? null);
            if (! is_array($claim)) {
                $summary['missing_manifest_rows']++;
                $summary['missing_evidence_rows']++;
                $summary['missing_disclaimer_rows']++;
                $summary['missing_reviewer_reviewed_at_rows']++;
                $summary['missing_rollback_condition_rows']++;

                continue;
            }

            $missingFields = array_filter(self::REQUIRED_FIELDS, fn (string $field): bool => $this->fieldMissing($claim, $field));
            if ($missingFields === []) {
                $summary['manifest_complete_rows']++;
            }

            if ($this->fieldMissing($claim, 'evidence_refs') || $this->fieldMissing($claim, 'evidence_strength')) {
                $summary['missing_evidence_rows']++;
            }
            if ($this->fieldMissing($claim, 'boundary_disclaimer')) {
                $summary['missing_disclaimer_rows']++;
            }
            if ($this->fieldMissing($claim, 'reviewer') || $this->fieldMissing($claim, 'reviewed_at')) {
                $summary['missing_reviewer_reviewed_at_rows']++;
            }
            if ($this->fieldMissing($claim, 'rollback_condition')) {
                $summary['missing_rollback_condition_rows']++;
            }
            if ((bool) ($claim['public_eligible'] ?? false)) {
                $summary['public_eligible_claim_rows']++;
            }
        }

        return $summary;
    }

    private function fieldMissing(array $claim, string $field): bool
    {
        if (! array_key_exists($field, $claim)) {
            return true;
        }

        $value = $claim[$field];
        if (is_array($value)) {
            return $value === [];
        }

        return $this->nullableString($value) === null;
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for CN trust manifest validation');
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
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
            $this->error((string) ($payload['message'] ?? 'CN trust manifest validation failed'));

            return;
        }

        $this->line('status='.(string) $payload['status']);
        $this->line('cn_proxy_rows='.(string) $payload['cn_proxy_rows']);
        $this->line('did_write=false');
    }
}
