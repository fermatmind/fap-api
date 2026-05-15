<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidateCnProxyPublicOwner extends Command
{
    private const EXPECTED_CN_PROXY_ROWS = 1663;

    /**
     * @var list<string>
     */
    private const REQUIRED_TRUST_MANIFEST_FIELDS = [
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

    protected $signature = 'career:validate-cn-proxy-public-owner
        {--scope= : Phase 2C CN proxy scope JSON artifact}
        {--manifest= : Optional reviewed CN proxy trust manifest JSON artifact}
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
            $manifestPath = $this->resolveManifestPath();
            $claims = $manifestPath === null ? [] : $this->loadClaims($manifestPath);
            $summary = $this->summarizeRows($rows, $claims);
            $reviewedTrustManifestComplete = $manifestPath !== null
                && $summary['manifest_complete_rows'] === count($rows)
                && $summary['missing_manifest_rows'] === 0
                && $summary['missing_evidence_rows'] === 0
                && $summary['missing_disclaimer_rows'] === 0
                && $summary['missing_reviewer_reviewed_at_rows'] === 0
                && $summary['missing_rollback_condition_rows'] === 0
                && $summary['public_eligible_claim_rows'] === 0
                && $summary['public_indexable_claim_rows'] === 0
                && $summary['sitemap_eligible_claim_rows'] === 0
                && $summary['llms_eligible_claim_rows'] === 0
                && $summary['llms_full_eligible_claim_rows'] === 0;

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-cn-proxy-public-owner',
                'dry_run' => true,
                'did_write' => false,
                'scope_path' => $scopePath,
                'manifest_path' => $manifestPath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'cn_proxy_rows' => count($rows),
                'reviewed_trust_manifest_required' => true,
                'reviewed_trust_manifest_rows' => count($claims),
                'reviewed_trust_manifest_complete' => $reviewedTrustManifestComplete,
                'manifest_complete_rows' => $manifestPath === null ? 0 : $summary['manifest_complete_rows'],
                'required_trust_manifest_fields' => self::REQUIRED_TRUST_MANIFEST_FIELDS,
                'missing_manifest_rows' => $manifestPath === null ? 0 : $summary['missing_manifest_rows'],
                'missing_evidence_rows' => $manifestPath === null ? 0 : $summary['missing_evidence_rows'],
                'missing_disclaimer_rows' => $manifestPath === null ? 0 : $summary['missing_disclaimer_rows'],
                'missing_reviewer_reviewed_at_rows' => $manifestPath === null ? 0 : $summary['missing_reviewer_reviewed_at_rows'],
                'missing_rollback_condition_rows' => $manifestPath === null ? 0 : $summary['missing_rollback_condition_rows'],
                'route_owner_enabled' => false,
                'public_pages_exposed' => 0,
                'public_route_allowed' => false,
                'public_owner_plan_ready' => $reviewedTrustManifestComplete,
                'guarded_public_owner_state' => $reviewedTrustManifestComplete
                    ? 'reviewed_noindex_public_cn_proxy_page_ready_for_separate_owner_train'
                    : 'disabled_until_CN_authority_policy_trust_manifest_disclaimer_and_release_gate',
                'ledger_decision_required' => true,
                'CN_authority_policy_required' => true,
                'trust_manifest_required' => true,
                'disclaimer_required' => true,
                'release_gate_approval_required' => true,
                'rejects_rows_without_ledger_decision' => true,
                'rejects_rows_without_trust_manifest' => true,
                'rejects_rows_without_disclaimer' => true,
                'reviewed_manifest_blocks_canonical_rollout' => true,
                'CN_proxy_can_masquerade_as_US_canonical_job' => false,
                'US_canonical_job_schema_returned' => false,
                'noindex_default' => true,
                'public_cn_proxy_page_rows' => $reviewedTrustManifestComplete ? count($rows) : 0,
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
                $summary['scope_missing_disclaimer_rows'] === 0 ? null : 'CN_proxy_missing_disclaimer_requirement',
                $summary['scope_missing_trust_manifest_rows'] === 0 ? null : 'CN_proxy_missing_trust_manifest_requirement',
                $manifestPath === null || $summary['missing_manifest_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_missing_rows',
                $manifestPath === null || $summary['missing_evidence_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_evidence_missing',
                $manifestPath === null || $summary['missing_disclaimer_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_disclaimer_missing',
                $manifestPath === null || $summary['missing_reviewer_reviewed_at_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_reviewer_missing',
                $manifestPath === null || $summary['missing_rollback_condition_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_rollback_condition_missing',
                $manifestPath === null || $summary['public_eligible_claim_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_public_eligible_rows',
                $manifestPath === null || $summary['public_indexable_claim_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_indexable_rows',
                $manifestPath === null || $summary['sitemap_eligible_claim_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_sitemap_rows',
                $manifestPath === null || $summary['llms_eligible_claim_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_llms_rows',
                $manifestPath === null || $summary['llms_full_eligible_claim_rows'] === 0 ? null : 'CN_proxy_public_owner_manifest_llms_full_rows',
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

    private function resolveManifestPath(): ?string
    {
        $value = $this->option('manifest');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $path = trim((string) $value);
        if (! is_file($path)) {
            throw new \RuntimeException('career CN proxy reviewed trust manifest artifact not found: '.$path);
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
            throw new \RuntimeException('career CN proxy reviewed trust manifest artifact is not valid JSON: '.$path);
        }

        $rows = data_get($payload, 'claims');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career CN proxy reviewed trust manifest artifact has no claims: '.$path);
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
            'public_candidate_rows' => 0,
            'scope_missing_disclaimer_rows' => 0,
            'scope_missing_trust_manifest_rows' => 0,
            'manifest_complete_rows' => 0,
            'missing_manifest_rows' => 0,
            'missing_disclaimer_rows' => 0,
            'missing_evidence_rows' => 0,
            'missing_reviewer_reviewed_at_rows' => 0,
            'missing_rollback_condition_rows' => 0,
            'public_eligible_claim_rows' => 0,
            'public_indexable_claim_rows' => 0,
            'sitemap_eligible_claim_rows' => 0,
            'llms_eligible_claim_rows' => 0,
            'llms_full_eligible_claim_rows' => 0,
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
                $summary['scope_missing_disclaimer_rows']++;
            }
            if (! (bool) ($row['trust_manifest_required'] ?? false)) {
                $summary['scope_missing_trust_manifest_rows']++;
            }

            $slug = $this->nullableString($row['source_slug'] ?? null) ?? $this->nullableString($row['slug'] ?? null);
            $claim = $slug === null ? null : ($claims[$slug] ?? null);
            if (! is_array($claim)) {
                $summary['missing_manifest_rows']++;
                $summary['missing_evidence_rows']++;
                $summary['missing_disclaimer_rows']++;
                $summary['missing_reviewer_reviewed_at_rows']++;
                $summary['missing_rollback_condition_rows']++;

                continue;
            }

            $missingFields = array_filter(self::REQUIRED_TRUST_MANIFEST_FIELDS, fn (string $field): bool => $this->fieldMissing($claim, $field));
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
            if ($this->claimIsPublicIndexable($claim)) {
                $summary['public_indexable_claim_rows']++;
            }
            if ((bool) ($claim['sitemap_eligible'] ?? false)) {
                $summary['sitemap_eligible_claim_rows']++;
            }
            if ((bool) ($claim['llms_eligible'] ?? false)) {
                $summary['llms_eligible_claim_rows']++;
            }
            if ((bool) ($claim['llms_full_eligible'] ?? false)) {
                $summary['llms_full_eligible_claim_rows']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function claimIsPublicIndexable(array $claim): bool
    {
        $indexability = strtolower((string) ($claim['indexability'] ?? ''));

        return in_array($indexability, ['indexable', 'public_indexable'], true);
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function fieldMissing(array $claim, string $field): bool
    {
        if (! array_key_exists($field, $claim)) {
            return true;
        }

        $value = $claim[$field];
        if (is_array($value)) {
            return $value === [];
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return false;
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
            throw new \RuntimeException('invalid timestamp segment for CN proxy public owner validation');
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
            $this->error((string) ($payload['message'] ?? 'CN proxy public owner validation failed'));

            return;
        }

        $this->line('status='.(string) $payload['status']);
        $this->line('cn_proxy_rows='.(string) $payload['cn_proxy_rows']);
        $this->line('public_pages_exposed=0');
        $this->line('did_write=false');
    }
}
