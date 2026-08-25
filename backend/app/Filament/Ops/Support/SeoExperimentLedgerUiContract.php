<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoExperimentLedgerUiContract
{
    /** @return array{state:string,statuses:list<string>,required_fields:list<string>} */
    public static function unavailableSnapshot(): array
    {
        return [
            'state' => SeoOperationsUiState::PRODUCTION_UNPROVEN,
            'statuses' => ['planned', 'canary', 'observing', 'kept', 'rolled_back', 'inconclusive'],
            'required_fields' => [
                'hypothesis',
                'rationale',
                'url_set',
                'family_locale',
                'baseline',
                'primary_metric',
                'guardrails',
                'observation_window',
                'change_revision',
                'public_readback',
                'rollback',
            ],
        ];
    }
}
