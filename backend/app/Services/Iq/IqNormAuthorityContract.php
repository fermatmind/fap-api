<?php

declare(strict_types=1);

namespace App\Services\Iq;

final class IqNormAuthorityContract
{
    public const SCALE_CODE = 'IQ_INTELLIGENCE_QUOTIENT';

    public const DEFAULT_POPULATION_KEY = 'general_adult_online';

    /**
     * Minimum production calibration sample for unlocking public IQ estimate claims.
     */
    public const MIN_PRODUCTION_SAMPLE_SIZE = 500;

    /**
     * @return list<string>
     */
    public static function claimEligibleStatuses(): array
    {
        return [
            'calibrated',
            'norm_table_available',
            'production_normed',
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            'draft',
            'dry_run_validated',
            'calibrated',
            'norm_table_available',
            'production_normed',
            'retired',
        ];
    }

    public static function statusAllowsPublicClaims(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::claimEligibleStatuses(), true);
    }

    /**
     * @param  array<string,mixed>  $record
     * @return list<string>
     */
    public static function validationErrors(array $record): array
    {
        $errors = [];

        $scaleCode = strtoupper(trim((string) ($record['scale_code'] ?? '')));
        if ($scaleCode !== self::SCALE_CODE) {
            $errors[] = 'scale_code_must_be_iq_intelligence_quotient';
        }

        foreach (['bank_id', 'norm_table_version', 'population_key', 'locale'] as $field) {
            if (trim((string) ($record[$field] ?? '')) === '') {
                $errors[] = $field.'_required';
            }
        }

        $status = strtolower(trim((string) ($record['status'] ?? '')));
        if (! in_array($status, self::allowedStatuses(), true)) {
            $errors[] = 'status_not_allowed';
        }

        $sampleSize = (int) ($record['sample_size'] ?? 0);
        if ($sampleSize < self::MIN_PRODUCTION_SAMPLE_SIZE) {
            $errors[] = 'sample_size_below_public_claim_minimum';
        }

        foreach (['mean', 'standard_deviation', 'min_raw_score', 'max_raw_score'] as $field) {
            if (! is_int($record[$field] ?? null) && ! is_float($record[$field] ?? null) && ! is_numeric((string) ($record[$field] ?? ''))) {
                $errors[] = $field.'_numeric_required';
            }
        }

        if ((float) ($record['standard_deviation'] ?? 0.0) <= 0.0) {
            $errors[] = 'standard_deviation_must_be_positive';
        }

        if (! (bool) ($record['license_verified'] ?? false)) {
            $errors[] = 'license_verification_required';
        }

        if (! (bool) ($record['locked'] ?? false)) {
            $errors[] = 'locked_authority_required';
        }

        if (trim((string) ($record['source_kind'] ?? '')) === '') {
            $errors[] = 'source_kind_required';
        }

        if (trim((string) ($record['source_ref'] ?? '')) === '') {
            $errors[] = 'source_ref_required';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array{claim_eligible:bool,reason_code:?string,errors:list<string>}
     */
    public static function publicClaimGate(array $record): array
    {
        $errors = self::validationErrors($record);
        $status = strtolower(trim((string) ($record['status'] ?? '')));

        if (! self::statusAllowsPublicClaims($status)) {
            $errors[] = 'status_not_public_claim_eligible';
        }

        if (trim((string) ($record['retired_at'] ?? '')) !== '') {
            $errors[] = 'retired_authority_cannot_claim';
        }

        $errors = array_values(array_unique($errors));

        return [
            'claim_eligible' => $errors === [],
            'reason_code' => $errors[0] ?? null,
            'errors' => $errors,
        ];
    }
}
