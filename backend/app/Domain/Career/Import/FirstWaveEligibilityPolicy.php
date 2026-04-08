<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

final class FirstWaveEligibilityPolicy
{
    /**
     * @param  array<string, mixed>  $normalized
     * @param  list<string>  $allowedModes
     * @return array{accepted: bool, reasons: list<string>}
     */
    public function evaluate(array $normalized, array $allowedModes): array
    {
        $reasons = [];

        $mappingMode = (string) ($normalized['mapping_mode'] ?? '');
        if (! in_array($mappingMode, $allowedModes, true)) {
            $reasons[] = 'unsupported_mapping_mode';
        }

        foreach ([
            'canonical_title_en',
            'canonical_slug',
            'bls_url',
            'ai_exposure',
            'median_pay_usd_annual',
            'jobs_2024',
            'projected_jobs_2034',
            'employment_change',
            'outlook_pct_2024_2034',
            'crosswalk_source_code',
        ] as $requiredField) {
            if (($normalized[$requiredField] ?? null) === null || $normalized[$requiredField] === '') {
                $reasons[] = sprintf('missing_%s', $requiredField);
            }
        }

        return [
            'accepted' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}
