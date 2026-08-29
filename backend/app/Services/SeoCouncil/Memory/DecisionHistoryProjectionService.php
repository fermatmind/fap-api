<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Memory;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DecisionHistoryProjectionService
{
    private const SOURCES = [
        'experiment_change_ledger' => 'seo_change_ledgers',
        'decision_cards' => 'seo_decision_cards',
        'content_lifecycle_material_decision' => 'content_material_decisions',
        'council_runs' => 'seo_council_runs',
        'council_steps' => 'seo_council_run_steps',
        'policy_exception' => 'seo_policy_exceptions',
        'data_source_incident' => 'seo_data_source_incidents',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function project(): array
    {
        $seoConnection = (string) config('seo_council.connection', 'seo_intel');
        $states = [];
        foreach (self::SOURCES as $source => $table) {
            $connection = $table === 'content_material_decisions' ? (string) config('database.default') : $seoConnection;
            $states[] = [
                'source' => $source,
                'table_hash' => hash('sha256', $table),
                'available' => Schema::connection($connection)->hasTable($table),
            ];
        }
        $available = array_filter($states, static fn (array $state): bool => $state['available']);
        $records = [];
        if (count($available) === count($states)) {
            foreach (self::SOURCES as $source => $table) {
                $connection = $table === 'content_material_decisions' ? (string) config('database.default') : $seoConnection;
                $records[] = [
                    'source' => $source,
                    'record_count' => DB::connection($connection)->table($table)->count(),
                ];
            }
        }
        $projection = [
            'projection_id' => 'seo.decision_history_projection.v1',
            'sources' => $states,
            'status' => count($available) === count($states) ? 'READY' : 'SOURCE_CAPABILITY_UNAVAILABLE',
            'records' => $records,
            'execution_allowed' => false,
        ];
        $projection['projection_hash'] = $this->hasher->hash($projection);

        return $projection;
    }
}
