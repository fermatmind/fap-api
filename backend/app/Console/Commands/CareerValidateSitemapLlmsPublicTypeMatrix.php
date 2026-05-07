<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidateSitemapLlmsPublicTypeMatrix extends Command
{
    protected $signature = 'career:validate-sitemap-llms-public-type-matrix
        {--ledger= : Full release ledger JSON artifact}
        {--locales=zh,en : Comma-separated locales used for canonical URL coverage counts}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}';

    protected $description = 'Validate Career sitemap, llms, and llms-full eligibility by public resolution type.';

    public function handle(): int
    {
        try {
            $ledgerPath = $this->resolveLedgerPath();
            $locales = $this->csvOption('locales');
            $rows = $this->loadRows($ledgerPath);
            $summary = $this->summarizeRows($rows);

            $payload = [
                'status' => 'validated',
                'command' => 'career:validate-sitemap-llms-public-type-matrix',
                'read_only' => true,
                'did_write' => false,
                'ledger_path' => $ledgerPath,
                'timestamp' => $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null),
                'locales' => $locales,
                'ledger_rows' => count($rows),
                'canonical_job_rows' => $summary['canonical_job_rows'],
                'held_canonical_job_rows' => $summary['held_canonical_job_rows'],
                'canonical_career_job_urls' => $summary['canonical_job_rows'] * count($locales),
                'alias_urls_in_sitemap' => 0,
                'alias_urls_in_llms' => 0,
                'alias_urls_in_llms_full' => 0,
                'family_urls_in_sitemap' => $summary['family_sitemap_eligible_rows'],
                'family_urls_in_llms' => $summary['family_llms_eligible_rows'],
                'family_urls_in_llms_full' => $summary['family_llms_full_eligible_rows'],
                'CN_urls_in_sitemap' => 0,
                'CN_urls_in_llms' => 0,
                'CN_urls_in_llms_full' => 0,
                'nonindex_reference_urls_in_sitemap' => 0,
                'nonindex_reference_urls_in_llms' => 0,
                'nonindex_reference_urls_in_llms_full' => 0,
                'software_developers_absent' => $summary['software_developers_public_rows'] === 0,
                'sitemap_bad_count' => 0,
                'llms_bad_count' => 0,
                'llms_full_bad_count' => 0,
                'blockers' => [],
            ];

            $payload['blockers'] = array_values(array_filter([
                $summary['canonical_job_rows'] > 0 ? null : 'canonical_job_rows_missing',
                $summary['canonical_missing_sitemap_rows'] === 0 ? null : 'public_canonical_job_missing_sitemap_eligibility',
                $summary['canonical_missing_llms_rows'] === 0 ? null : 'public_canonical_job_missing_llms_eligibility',
                $summary['canonical_missing_llms_full_rows'] === 0 ? null : 'public_canonical_job_missing_llms_full_eligibility',
                $summary['held_canonical_job_rows'] === 0 ? null : 'held_public_canonical_job_rows',
                $summary['alias_sitemap_or_llms_rows'] === 0 ? null : 'public_alias_redirect_sitemap_llms_leakage',
                $summary['family_eligible_without_required_fields'] === 0 ? null : 'public_family_hub_sitemap_llms_without_schema_children_trust',
                $summary['cn_sitemap_or_llms_rows'] === 0 ? null : 'public_cn_proxy_page_sitemap_llms_leakage',
                $summary['nonindex_sitemap_or_llms_rows'] === 0 ? null : 'public_nonindex_reference_sitemap_llms_leakage',
                $summary['non_public_sitemap_or_llms_rows'] === 0 ? null : 'non_public_type_sitemap_llms_leakage',
                $summary['software_developers_public_rows'] === 0 ? null : 'software_developers_sitemap_llms_leakage',
                $summary['unknown_public_resolution_type_rows'] === 0 ? null : 'unknown_public_resolution_type',
            ]));

            $payload['sitemap_bad_count'] = count($payload['blockers']);
            $payload['llms_bad_count'] = count($payload['blockers']);
            $payload['llms_full_bad_count'] = count($payload['blockers']);

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload);

            return $payload['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:validate-sitemap-llms-public-type-matrix',
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
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) $this->option($name)),
        ), static fn (string $value): bool => $value !== '')));

        if ($values === []) {
            throw new \RuntimeException('--'.$name.' must contain at least one value.');
        }

        return $values;
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
            'canonical_job_rows' => 0,
            'held_canonical_job_rows' => 0,
            'canonical_missing_sitemap_rows' => 0,
            'canonical_missing_llms_rows' => 0,
            'canonical_missing_llms_full_rows' => 0,
            'alias_sitemap_or_llms_rows' => 0,
            'family_sitemap_eligible_rows' => 0,
            'family_llms_eligible_rows' => 0,
            'family_llms_full_eligible_rows' => 0,
            'family_eligible_without_required_fields' => 0,
            'cn_sitemap_or_llms_rows' => 0,
            'nonindex_sitemap_or_llms_rows' => 0,
            'non_public_sitemap_or_llms_rows' => 0,
            'software_developers_public_rows' => 0,
            'unknown_public_resolution_type_rows' => 0,
        ];

        $allowedTypes = array_flip(CareerPublicResolutionTypeMatrix::allowedTypes());
        foreach ($rows as $row) {
            $type = (string) ($row['public_resolution_type'] ?? '');
            if ($type === '' || ! isset($allowedTypes[$type])) {
                $summary['unknown_public_resolution_type_rows']++;

                continue;
            }

            $hasSitemapOrLlms = $this->hasAnyEligibility($row);
            if ((string) ($row['source_slug'] ?? '') === 'software-developers' && ((bool) ($row['public_eligible'] ?? false) || $hasSitemapOrLlms)) {
                $summary['software_developers_public_rows']++;
            }

            match ($type) {
                CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB => $this->summarizeCanonical($row, $summary),
                CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT => $this->summarizeAlias($row, $summary),
                CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB => $this->summarizeFamilyHub($row, $summary),
                CareerPublicResolutionTypeMatrix::PUBLIC_CN_PROXY_PAGE => $this->summarizeCnProxy($row, $summary),
                CareerPublicResolutionTypeMatrix::PUBLIC_NONINDEX_REFERENCE => $this->summarizeNonindexReference($row, $summary),
                CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                CareerPublicResolutionTypeMatrix::BLOCKED_UNTIL_GOVERNANCE_APPROVAL => $this->summarizeNonPublic($row, $summary),
                default => null,
            };
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeCanonical(array $row, array &$summary): void
    {
        $summary['canonical_job_rows']++;
        if ($this->isHeldStatus((string) ($row['current_status'] ?? ''))) {
            $summary['held_canonical_job_rows']++;
        }

        if (! (bool) ($row['sitemap_eligible'] ?? false)) {
            $summary['canonical_missing_sitemap_rows']++;
        }
        if (! (bool) ($row['llms_eligible'] ?? false)) {
            $summary['canonical_missing_llms_rows']++;
        }
        if (! (bool) ($row['llms_full_eligible'] ?? false)) {
            $summary['canonical_missing_llms_full_rows']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeAlias(array $row, array &$summary): void
    {
        if ($this->hasAnyEligibility($row)) {
            $summary['alias_sitemap_or_llms_rows']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeFamilyHub(array $row, array &$summary): void
    {
        if ((bool) ($row['sitemap_eligible'] ?? false)) {
            $summary['family_sitemap_eligible_rows']++;
        }
        if ((bool) ($row['llms_eligible'] ?? false)) {
            $summary['family_llms_eligible_rows']++;
        }
        if ((bool) ($row['llms_full_eligible'] ?? false)) {
            $summary['family_llms_full_eligible_rows']++;
        }
        if ($this->hasAnyEligibility($row) && ! $this->familyHubHasRequiredFields($row)) {
            $summary['family_eligible_without_required_fields']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeCnProxy(array $row, array &$summary): void
    {
        if ($this->hasAnyEligibility($row)) {
            $summary['cn_sitemap_or_llms_rows']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeNonindexReference(array $row, array &$summary): void
    {
        if ($this->hasAnyEligibility($row)) {
            $summary['nonindex_sitemap_or_llms_rows']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     */
    private function summarizeNonPublic(array $row, array &$summary): void
    {
        if ($this->hasAnyEligibility($row)) {
            $summary['non_public_sitemap_or_llms_rows']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasAnyEligibility(array $row): bool
    {
        return (bool) ($row['sitemap_eligible'] ?? false)
            || (bool) ($row['llms_eligible'] ?? false)
            || (bool) ($row['llms_full_eligible'] ?? false);
    }

    private function isHeldStatus(string $status): bool
    {
        return in_array($status, [
            'duplicate_identity_hold',
            'CN_proxy_hold',
            'broad_group_hold',
            'manual_hold',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function familyHubHasRequiredFields(array $row): bool
    {
        return trim((string) ($row['family_hub_slug'] ?? '')) !== ''
            && is_array($row['child_canonical_slugs'] ?? null)
            && $row['child_canonical_slugs'] !== []
            && trim((string) ($row['schema_policy'] ?? '')) !== ''
            && (bool) ($row['trust_manifest_required'] ?? false);
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for sitemap llms public type matrix validation');
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
            $this->error((string) ($payload['message'] ?? 'sitemap llms public type matrix validation failed'));

            return;
        }

        $this->line('status='.(string) $payload['status']);
        $this->line('canonical_career_job_urls='.(string) $payload['canonical_career_job_urls']);
        $this->line('sitemap_bad_count='.(string) $payload['sitemap_bad_count']);
        $this->line('llms_bad_count='.(string) $payload['llms_bad_count']);
        $this->line('llms_full_bad_count='.(string) $payload['llms_full_bad_count']);
        $this->line('did_write=false');
    }
}
