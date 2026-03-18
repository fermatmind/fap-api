<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MbtiUpgradeLegacyPartialUnlocks extends Command
{
    private const FULL_BENEFIT_CODE = 'MBTI_REPORT_FULL';

    private const LEGACY_PARTIAL_SKUS = [
        'MBTI_CAREER_99',
        'MBTI_RELATIONSHIP_99',
    ];

    private const LEGACY_PARTIAL_BENEFIT_CODES = [
        'MBTI_CAREER',
        'MBTI_RELATIONSHIP',
        'MBTI_RELATIONSHIPS',
    ];

    protected $signature = 'commerce:mbti-upgrade-legacy-partials
        {--org_id=0 : Organization id}
        {--dry-run=0 : Preview without writing grants}
        {--json=0 : Output json summary}';

    protected $description = 'Upgrade legacy MBTI 0.99 partial unlocks to the 1.99 full-report entitlement.';

    public function __construct(private readonly EntitlementManager $entitlements)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $orgId = max(0, (int) $this->option('org_id'));
        $dryRun = $this->isTruthy($this->option('dry-run'));

        $summary = [
            'ok' => true,
            'org_id' => $orgId,
            'dry_run' => $dryRun,
            'orders_scanned' => 0,
            'grants_scanned' => 0,
            'attempts_considered' => 0,
            'upgraded' => 0,
            'already_full' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $seenAttempts = [];

        foreach ($this->legacyPartialOrders($orgId) as $order) {
            $summary['orders_scanned']++;
            $this->processCandidate($orgId, $summary, $seenAttempts, $order, null, $dryRun);
        }

        foreach ($this->legacyPartialGrants($orgId) as $grant) {
            $summary['grants_scanned']++;
            $this->processCandidate($orgId, $summary, $seenAttempts, null, $grant, $dryRun);
        }

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('MBTI legacy partial upgrade summary');
            $this->line('org_id='.(string) $orgId.' dry_run='.($dryRun ? '1' : '0'));
            $this->line('orders_scanned='.(string) $summary['orders_scanned']);
            $this->line('grants_scanned='.(string) $summary['grants_scanned']);
            $this->line('attempts_considered='.(string) $summary['attempts_considered']);
            $this->line('upgraded='.(string) $summary['upgraded']);
            $this->line('already_full='.(string) $summary['already_full']);
            $this->line('skipped='.(string) $summary['skipped']);
            $this->line('errors='.(string) count($summary['errors']));
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int,object>
     */
    private function legacyPartialOrders(int $orgId)
    {
        if (! Schema::hasTable('orders')) {
            return collect()->lazy();
        }

        $query = DB::table('orders')
            ->where('org_id', $orgId)
            ->whereNotNull('target_attempt_id')
            ->where('target_attempt_id', '!=', '')
            ->where(function (Builder $builder): void {
                $builder->whereNotNull('paid_at')
                    ->orWhereIn('status', ['paid', 'fulfilled', 'complete', 'completed']);
            })
            ->orderBy('created_at');

        $skuColumns = array_values(array_filter([
            Schema::hasColumn('orders', 'effective_sku') ? 'effective_sku' : null,
            Schema::hasColumn('orders', 'requested_sku') ? 'requested_sku' : null,
            Schema::hasColumn('orders', 'item_sku') ? 'item_sku' : null,
            Schema::hasColumn('orders', 'sku') ? 'sku' : null,
        ]));

        if ($skuColumns === []) {
            return collect()->lazy();
        }

        $query->where(function (Builder $builder) use ($skuColumns): void {
            foreach ($skuColumns as $column) {
                $builder->orWhereIn($column, self::LEGACY_PARTIAL_SKUS);
            }
        });

        return $query->cursor();
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int,object>
     */
    private function legacyPartialGrants(int $orgId)
    {
        if (! Schema::hasTable('benefit_grants')) {
            return collect()->lazy();
        }

        return DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('status', 'active')
            ->whereIn('benefit_code', self::LEGACY_PARTIAL_BENEFIT_CODES)
            ->whereNotNull('attempt_id')
            ->where('attempt_id', '!=', '')
            ->orderBy('created_at')
            ->cursor();
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,bool>  $seenAttempts
     */
    private function processCandidate(
        int $orgId,
        array &$summary,
        array &$seenAttempts,
        ?object $order,
        ?object $grant,
        bool $dryRun
    ): void {
        $attemptId = trim((string) (($order->target_attempt_id ?? null) ?: ($grant->attempt_id ?? null) ?: ''));
        if ($attemptId === '') {
            $summary['skipped']++;

            return;
        }

        if (isset($seenAttempts[$attemptId])) {
            return;
        }
        $seenAttempts[$attemptId] = true;
        $summary['attempts_considered']++;

        if ($this->hasActiveFullGrant($orgId, $attemptId)) {
            $summary['already_full']++;

            return;
        }

        $userId = $this->trimOrNull(($order->user_id ?? null) ?: ($grant->user_id ?? null));
        $anonId = $this->trimOrNull(($order->anon_id ?? null) ?: ($grant->benefit_ref ?? null));
        $orderNo = $this->trimOrNull(($order->order_no ?? null) ?: ($grant->order_no ?? null));

        if ($dryRun) {
            $summary['upgraded']++;

            return;
        }

        $result = $this->entitlements->grantAttemptUnlock(
            $orgId,
            $userId,
            $anonId,
            self::FULL_BENEFIT_CODE,
            $attemptId,
            $orderNo,
            null,
            null,
            [
                ReportAccess::MODULE_CORE_FULL,
                ReportAccess::MODULE_CAREER,
                ReportAccess::MODULE_RELATIONSHIPS,
            ]
        );

        if (($result['ok'] ?? false) === true) {
            $summary['upgraded']++;

            return;
        }

        $summary['ok'] = false;
        $summary['errors'][] = [
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'message' => (string) ($result['message'] ?? $result['error'] ?? 'grant failed'),
        ];
    }

    private function hasActiveFullGrant(int $orgId, string $attemptId): bool
    {
        if (! Schema::hasTable('benefit_grants')) {
            return false;
        }

        return DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', self::FULL_BENEFIT_CODE)
            ->where('status', 'active')
            ->where('attempt_id', $attemptId)
            ->exists();
    }

    private function trimOrNull(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
