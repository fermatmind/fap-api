<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use App\Domain\Career\IndexStateValue;

final class CrosswalkSeedPolicy
{
    /**
     * @param  array<string, mixed>  $normalized
     * @return array{index_state: string, index_eligible: bool, editorial_patch_required: bool, editorial_patch_status: string, reason_codes: list<string>}
     */
    public function seed(array $normalized): array
    {
        $mappingMode = (string) ($normalized['mapping_mode'] ?? ImportScopeMode::EXACT);

        if ($mappingMode === ImportScopeMode::TRUST_INHERITANCE) {
            return [
                'index_state' => IndexStateValue::NOINDEX,
                'index_eligible' => false,
                'editorial_patch_required' => true,
                'editorial_patch_status' => 'queued',
                'reason_codes' => ['trust_inheritance', 'editorial_review_pending'],
            ];
        }

        return [
            'index_state' => IndexStateValue::TRUST_LIMITED,
            'index_eligible' => false,
            'editorial_patch_required' => false,
            'editorial_patch_status' => 'not_required',
            'reason_codes' => ['first_wave_conservative_gate'],
        ];
    }
}
