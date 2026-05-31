<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Iq\IqNormAuthorityContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormsIqImport extends Command
{
    private const SCHEMA_VERSION = 'iq.norm_table.v1';

    protected $signature = 'norms:iq:import
        {--file= : IQ norm table JSON file path}
        {--dry-run=1 : Validate only; writes are intentionally disabled in IQ-NORM-02}
        {--require-claim-ready=0 : Fail unless the authority metadata is already public-claim eligible}';

    protected $description = 'Validate IQ norm table authority payloads in dry-run mode without importing production data.';

    public function handle(): int
    {
        if (! Schema::hasTable('iq_norm_authorities')) {
            $this->error('Missing required table: iq_norm_authorities. Run migrations first.');

            return self::FAILURE;
        }

        $dryRun = $this->isTruthy($this->option('dry-run'));
        if (! $dryRun) {
            $this->error('IQ norm import writes are disabled in IQ-NORM-02. Use --dry-run=1.');

            return self::FAILURE;
        }

        $file = $this->resolveFilePath((string) $this->option('file'));
        if ($file === '') {
            $this->error('Missing --file path.');

            return self::FAILURE;
        }

        $payload = $this->readJson($file);
        if (! is_array($payload)) {
            $this->error('Invalid JSON payload.');

            return self::FAILURE;
        }

        $errors = $this->validatePayload($payload);
        $gate = IqNormAuthorityContract::publicClaimGate($this->authorityRecord($payload));
        $requireClaimReady = $this->isTruthy($this->option('require-claim-ready'));
        if ($requireClaimReady && ! (bool) ($gate['claim_eligible'] ?? false)) {
            $errors[] = 'authority_not_public_claim_ready:'.(string) ($gate['reason_code'] ?? 'unknown');
        }

        if ($errors !== []) {
            foreach (array_values(array_unique($errors)) as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $rows = (array) ($payload['rows'] ?? []);
        $this->info('dry-run=1, no write performed.');
        $this->line(sprintf(
            'validated scale=%s bank=%s version=%s rows=%d claim_eligible=%s',
            (string) ($payload['scale_code'] ?? ''),
            (string) ($payload['bank_id'] ?? ''),
            (string) ($payload['norm_table_version'] ?? ''),
            count($rows),
            (bool) ($gate['claim_eligible'] ?? false) ? 'true' : 'false'
        ));

        $existing = DB::table('iq_norm_authorities')
            ->where('scale_code', IqNormAuthorityContract::SCALE_CODE)
            ->where('bank_id', (string) ($payload['bank_id'] ?? ''))
            ->where('norm_table_version', (string) ($payload['norm_table_version'] ?? ''))
            ->where('population_key', (string) ($payload['population_key'] ?? IqNormAuthorityContract::DEFAULT_POPULATION_KEY))
            ->where('locale', (string) ($payload['locale'] ?? ''))
            ->exists();

        $this->line('version_lock='.($existing ? 'existing_authority_detected' : 'new_authority_candidate'));

        return self::SUCCESS;
    }

    private function resolveFilePath(string $file): string
    {
        $normalized = trim($file);
        if ($normalized === '') {
            return '';
        }

        return str_starts_with($normalized, '/') ? $normalized : base_path($normalized);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            $this->error('File not found: '.$file);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];

        if ((string) ($payload['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version_must_be_'.self::SCHEMA_VERSION;
        }

        $authority = $this->authorityRecord($payload);
        foreach (['scale_code', 'bank_id', 'norm_table_version', 'status', 'population_key', 'locale', 'source_kind', 'source_ref'] as $field) {
            if (trim((string) ($authority[$field] ?? '')) === '') {
                $errors[] = $field.'_required';
            }
        }

        if (strtoupper((string) ($authority['scale_code'] ?? '')) !== IqNormAuthorityContract::SCALE_CODE) {
            $errors[] = 'scale_code_must_be_iq_intelligence_quotient';
        }

        $status = strtolower((string) ($authority['status'] ?? ''));
        if (! in_array($status, IqNormAuthorityContract::allowedStatuses(), true)) {
            $errors[] = 'status_not_allowed';
        }

        $sampleSize = (int) ($authority['sample_size'] ?? 0);
        if ($sampleSize <= 0) {
            $errors[] = 'sample_size_positive_required';
        }

        $mean = $this->numericOrNull($authority['mean'] ?? null);
        $sd = $this->numericOrNull($authority['standard_deviation'] ?? null);
        $minRaw = $this->numericOrNull($authority['min_raw_score'] ?? null);
        $maxRaw = $this->numericOrNull($authority['max_raw_score'] ?? null);
        if ($mean === null) {
            $errors[] = 'mean_numeric_required';
        }
        if ($sd === null || $sd <= 0.0) {
            $errors[] = 'standard_deviation_positive_required';
        }
        if ($minRaw === null || $maxRaw === null || $maxRaw < $minRaw) {
            $errors[] = 'raw_score_range_invalid';
        }

        $rows = $payload['rows'] ?? null;
        if (! is_array($rows) || $rows === []) {
            $errors[] = 'rows_required';

            return array_values(array_unique($errors));
        }

        $seen = [];
        $hasMin = false;
        $hasMax = false;
        $previousRaw = null;
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                $errors[] = "rows.{$index}_must_be_object";

                continue;
            }

            $raw = $this->numericOrNull($row['raw_score'] ?? null);
            $iq = $this->numericOrNull($row['iq_estimate'] ?? null);
            $percentile = $this->numericOrNull($row['percentile'] ?? null);
            $ciLow = $this->numericOrNull($row['ci_low'] ?? null);
            $ciHigh = $this->numericOrNull($row['ci_high'] ?? null);

            if ($raw === null || ($minRaw !== null && $raw < $minRaw) || ($maxRaw !== null && $raw > $maxRaw)) {
                $errors[] = "rows.{$index}.raw_score_out_of_range";

                continue;
            }
            $rawKey = (string) $raw;
            if (isset($seen[$rawKey])) {
                $errors[] = "rows.{$index}.raw_score_duplicate";
            }
            $seen[$rawKey] = true;
            if ($previousRaw !== null && $raw <= $previousRaw) {
                $errors[] = "rows.{$index}.raw_score_not_strictly_ascending";
            }
            $previousRaw = $raw;
            $hasMin = $hasMin || ($minRaw !== null && abs($raw - $minRaw) < 0.000001);
            $hasMax = $hasMax || ($maxRaw !== null && abs($raw - $maxRaw) < 0.000001);

            if ($iq === null || $iq < 40.0 || $iq > 180.0) {
                $errors[] = "rows.{$index}.iq_estimate_out_of_range";
            }
            if ($percentile === null || $percentile < 0.0 || $percentile > 100.0) {
                $errors[] = "rows.{$index}.percentile_out_of_range";
            }
            if ($ciLow === null || $ciHigh === null || $ciHigh < $ciLow) {
                $errors[] = "rows.{$index}.confidence_interval_invalid";
            }
        }

        if (! $hasMin) {
            $errors[] = 'rows_must_include_min_raw_score';
        }
        if (! $hasMax) {
            $errors[] = 'rows_must_include_max_raw_score';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function authorityRecord(array $payload): array
    {
        return [
            'scale_code' => $payload['scale_code'] ?? null,
            'bank_id' => $payload['bank_id'] ?? null,
            'norm_table_version' => $payload['norm_table_version'] ?? null,
            'status' => $payload['status'] ?? null,
            'population_key' => $payload['population_key'] ?? IqNormAuthorityContract::DEFAULT_POPULATION_KEY,
            'locale' => $payload['locale'] ?? null,
            'sample_size' => $payload['sample_size'] ?? null,
            'mean' => $payload['mean'] ?? null,
            'standard_deviation' => $payload['standard_deviation'] ?? null,
            'min_raw_score' => $payload['min_raw_score'] ?? null,
            'max_raw_score' => $payload['max_raw_score'] ?? null,
            'source_kind' => $payload['source_kind'] ?? null,
            'source_ref' => $payload['source_ref'] ?? null,
            'license_verified' => $payload['license_verified'] ?? false,
            'locked' => $payload['locked'] ?? false,
            'retired_at' => $payload['retired_at'] ?? null,
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
