<?php

declare(strict_types=1);

namespace App\Services\Career\Authority;

final class CareerCrosswalkModePolicy
{
    public const EXACT = 'exact';

    public const TRUST_INHERITANCE = 'trust_inheritance';

    public const FUNCTIONAL_EQUIVALENT = 'functional_equivalent';

    public const LOCAL_HEAVY_INTERPRETATION = 'local_heavy_interpretation';

    public const FAMILY_PROXY = 'family_proxy';

    public const UNMAPPED = 'unmapped';

    /**
     * @return list<string>
     */
    public static function canonicalModes(): array
    {
        return [
            self::EXACT,
            self::TRUST_INHERITANCE,
            self::FUNCTIONAL_EQUIVALENT,
            self::LOCAL_HEAVY_INTERPRETATION,
            self::FAMILY_PROXY,
            self::UNMAPPED,
        ];
    }

    public static function normalizeMode(?string $mode): string
    {
        $normalized = strtolower(trim((string) $mode));

        return match ($normalized) {
            self::EXACT,
            'direct',
            'direct_match' => self::EXACT,
            self::TRUST_INHERITANCE => self::TRUST_INHERITANCE,
            self::FUNCTIONAL_EQUIVALENT,
            'functional_proxy',
            'nearest_us_soc' => self::FUNCTIONAL_EQUIVALENT,
            self::LOCAL_HEAVY_INTERPRETATION,
            'cn_boundary_only' => self::LOCAL_HEAVY_INTERPRETATION,
            self::FAMILY_PROXY,
            'bls_broad_group',
            'multiple_onet_occupations',
            'broad_group' => self::FAMILY_PROXY,
            default => self::UNMAPPED,
        };
    }

    public static function isAutoSafe(string $mode): bool
    {
        return in_array(self::normalizeMode($mode), [self::EXACT, self::TRUST_INHERITANCE], true);
    }

    public static function requiresManualReview(string $mode): bool
    {
        return self::normalizeMode($mode) === self::FUNCTIONAL_EQUIVALENT;
    }

    public static function isBlockedForRelease(string $mode): bool
    {
        return in_array(
            self::normalizeMode($mode),
            [self::LOCAL_HEAVY_INTERPRETATION, self::FAMILY_PROXY, self::UNMAPPED],
            true
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *   mode:string,
     *   release_bucket:string,
     *   authority_write_allowed:bool,
     *   display_import_allowed:bool,
     *   blockers:list<string>,
     *   source_system_policy:string,
     *   notes:list<string>
     * }
     */
    public function classifyWorkbookRow(array $row): array
    {
        $socCode = $this->stringValue($row['SOC_Code'] ?? $row['SOC Code'] ?? null);
        $onetCode = $this->stringValue($row['O_NET_Code'] ?? $row['O*NET Code'] ?? $row['O_NET'] ?? null);
        $explicitMode = $this->nullableString($row['crosswalk_mode'] ?? $row['Crosswalk_Mode'] ?? $row['Mapping Mode'] ?? null);
        $blockers = [];
        $notes = [];
        $mode = $explicitMode !== null ? self::normalizeMode($explicitMode) : self::EXACT;

        if ($socCode === '') {
            $mode = self::UNMAPPED;
            $blockers[] = 'missing_soc';
        }

        if ($onetCode === '') {
            $mode = self::UNMAPPED;
            $blockers[] = 'missing_onet';
        }

        if (str_starts_with(strtoupper($socCode), 'CN-') || $onetCode === 'not_applicable_cn_occupation') {
            $mode = self::LOCAL_HEAVY_INTERPRETATION;
            $blockers[] = 'cn_proxy_not_us_track';
        }

        if (in_array($socCode, ['BLS_BROAD_GROUP', 'broad_group'], true)) {
            $mode = self::FAMILY_PROXY;
            $blockers[] = 'broad_group_requires_manual_resolution';
        }

        if ($onetCode === 'multiple_onet_occupations') {
            $mode = self::FAMILY_PROXY;
            $blockers[] = 'multiple_onet_requires_manual_resolution';
        }

        if (in_array($onetCode, ['functional_proxy', 'nearest_us_soc'], true)) {
            $mode = self::FUNCTIONAL_EQUIVALENT;
            $blockers[] = 'proxy_mapping_requires_manual_review';
        }

        if ($onetCode === 'cn_boundary_only') {
            $mode = self::LOCAL_HEAVY_INTERPRETATION;
            $blockers[] = 'cn_boundary_only_not_release_authority';
        }

        if ($explicitMode !== null && $mode !== strtolower(trim($explicitMode))) {
            $notes[] = 'legacy_mode_normalized_from_'.$explicitMode;
        }

        $knownNonDirectSoc = in_array($socCode, ['BLS_BROAD_GROUP', 'broad_group'], true)
            || str_starts_with(strtoupper($socCode), 'CN-');
        $knownNonDirectOnet = in_array($onetCode, [
            'not_applicable_cn_occupation',
            'multiple_onet_occupations',
            'functional_proxy',
            'nearest_us_soc',
            'cn_boundary_only',
        ], true);

        if (! $knownNonDirectSoc && ! $this->hasNormalSoc($socCode) && ! in_array('missing_soc', $blockers, true)) {
            $blockers[] = 'soc_not_direct_us_pattern';
            $mode = self::isBlockedForRelease($mode) ? $mode : self::UNMAPPED;
        }

        if (! $knownNonDirectOnet && ! $this->hasNormalOnet($onetCode) && ! in_array('missing_onet', $blockers, true)) {
            $blockers[] = 'onet_not_direct_us_pattern';
            $mode = self::isBlockedForRelease($mode) ? $mode : self::UNMAPPED;
        }

        return [
            'mode' => $mode,
            'release_bucket' => match (true) {
                self::isAutoSafe($mode) => 'auto_safe',
                self::requiresManualReview($mode) => 'manual_review',
                default => 'blocked',
            },
            'authority_write_allowed' => $blockers === [] && self::isAutoSafe($mode),
            'display_import_allowed' => $blockers === [] && self::isAutoSafe($mode),
            'blockers' => array_values(array_unique($blockers)),
            'source_system_policy' => self::isAutoSafe($mode) ? 'direct_us_soc_and_onet_required' : 'manual_authority_review_required',
            'notes' => array_values(array_unique($notes)),
        ];
    }

    private function hasNormalSoc(string $value): bool
    {
        return preg_match('/^\d{2}-\d{4}$/', $value) === 1;
    }

    private function hasNormalOnet(string $value): bool
    {
        return preg_match('/^\d{2}-\d{4}\.\d{2}$/', $value) === 1;
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
