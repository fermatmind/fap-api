<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoWorkbenchUiContract
{
    public const DEFAULT_DECISION_COUNT = 3;

    public const MAX_DECISION_COUNT = 5;

    /**
     * SEO-PLATFORM-09 has not published its unified production read model yet.
     * Partial GSC, Issue, CMS, and deployment models must not be joined here.
     *
     * @return array{
     *     state:string,
     *     decisions:list<never>,
     *     trend:array{window:string,clicks:null,impressions:null,ctr:null,position:null,observed_at:null,latency:null},
     *     health:array{p0:null,p1:null,p2:null,runtime_slo:null,latest_crawl:null,release_chain:null},
     *     required_decision_fields:list<string>
     * }
     */
    public static function unavailableSnapshot(): array
    {
        return [
            'state' => SeoOperationsUiState::MEASUREMENT_HOLD,
            'decisions' => [],
            'trend' => [
                'window' => '28d',
                'clicks' => null,
                'impressions' => null,
                'ctr' => null,
                'position' => null,
                'observed_at' => null,
                'latency' => null,
            ],
            'health' => [
                'p0' => null,
                'p1' => null,
                'p2' => null,
                'runtime_slo' => null,
                'latest_crawl' => null,
                'release_chain' => null,
            ],
            'required_decision_fields' => [
                'detector',
                'family_locale',
                'affected_urls',
                'evidence_freshness',
                'expected_gain',
                'blast_radius',
                'highest_action',
                'next_step',
            ],
        ];
    }
}
