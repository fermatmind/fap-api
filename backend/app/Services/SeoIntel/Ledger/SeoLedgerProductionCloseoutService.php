<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\EnsureSeoIntelReadAuthorized;
use App\Http\Middleware\OpsAccessControl;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoLedgerProductionCloseoutService
{
    public const SCHEMA_VERSION = 'seo-platform-08-production-closeout.v1';

    private const REQUIRED_LEDGER_COLUMNS = [
        'ledger_id', 'schema_version', 'idempotency_key', 'change_type',
        'hypothesis', 'rationale', 'source_json', 'public_url_cohort_json',
        'page_family', 'locale', 'authority_revision', 'baseline_window_json',
        'primary_metric_json', 'guardrail_metrics_json', 'observation_window_json',
        'change_revision', 'canary_scope_json', 'blast_radius_json',
        'public_runtime_readback_json', 'gsc_funnel_evidence_state_json',
        'rollback_plan_json', 'owner_actor_json', 'approval_policy_decision_json',
        'current_state', 'close_reason', 'transition_sequence',
    ];

    private const REQUIRED_EVENT_COLUMNS = [
        'event_id', 'ledger_id', 'sequence', 'idempotency_key', 'event_type',
        'from_state', 'to_state', 'denial_code', 'actor_json', 'evidence_json',
        'evidence_hash', 'occurred_at',
    ];

    public function __construct(
        private readonly SeoLedgerSnapshotReadService $snapshotReadService,
        private readonly string $connection = 'seo_intel',
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(string $expectedSha, int $permissionNegativeStatus): array
    {
        $expectedSha = strtolower(trim($expectedSha));
        $currentSha = $this->currentRevision();
        $snapshot = $this->snapshotReadService->snapshot(1, 1);
        $schemaReady = $this->schemaReady();
        $routeProtected = $this->routeProtected();
        $exactShaBound = preg_match('/^[a-f0-9]{40}$/', $expectedSha) === 1
            && $currentSha !== null
            && hash_equals($expectedSha, $currentSha);
        $snapshotReadable = in_array($snapshot['state'] ?? null, ['production_proven'], true)
            && ($snapshot['read_only'] ?? null) === true
            && is_array($snapshot['items'] ?? null)
            && is_int(data_get($snapshot, 'pagination.total'));
        $permissionNegative = $permissionNegativeStatus === 401;
        $proven = $schemaReady && $routeProtected && $exactShaBound && $snapshotReadable && $permissionNegative;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $proven ? 'production_proven' : 'production_unproven',
            'schema_ready' => $schemaReady,
            'route_protected' => $routeProtected,
            'exact_sha_bound' => $exactShaBound,
            'snapshot_readable' => $snapshotReadable,
            'snapshot_empty' => $snapshot['empty'] ?? null,
            'snapshot_count' => data_get($snapshot, 'pagination.total'),
            'permission_negative_status' => $permissionNegativeStatus,
            'highest_enabled_level' => 'L2',
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
            'real_experiment_required' => false,
            'boundaries' => [
                'read_only' => true,
                'manual_migration_allowed' => false,
                'production_database_write' => false,
                'raw_snapshot_emitted' => false,
                'private_topology_emitted' => false,
            ],
        ];
    }

    private function schemaReady(): bool
    {
        try {
            $schema = Schema::connection($this->connection);

            return $schema->hasTable('seo_change_ledgers')
                && $schema->hasTable('seo_change_ledger_events')
                && $schema->hasColumns('seo_change_ledgers', self::REQUIRED_LEDGER_COLUMNS)
                && $schema->hasColumns('seo_change_ledger_events', self::REQUIRED_EVENT_COLUMNS);
        } catch (Throwable) {
            return false;
        }
    }

    private function routeProtected(): bool
    {
        $route = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.experiment_ledger');
        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            return false;
        }

        $middleware = $route->gatherMiddleware();
        foreach ([AdminAuth::class, EnsureAdminTotpVerified::class, OpsAccessControl::class, EnsureSeoIntelReadAuthorized::class] as $required) {
            if (! in_array($required, $middleware, true)) {
                return false;
            }
        }

        return true;
    }

    private function currentRevision(): ?string
    {
        $path = dirname(base_path()).DIRECTORY_SEPARATOR.'REVISION';
        if (! is_file($path)) {
            return null;
        }

        $revision = strtolower(trim((string) file_get_contents($path)));

        return preg_match('/^[a-f0-9]{40}$/', $revision) === 1 ? $revision : null;
    }
}
