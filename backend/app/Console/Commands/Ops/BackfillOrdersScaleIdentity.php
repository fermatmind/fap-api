<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillOrdersScaleIdentity extends Command
{
    protected $signature = 'ops:backfill-orders-scale-identity
        {--chunk=1000 : Chunk size}
        {--dry-run : Compute and report only; do not write updates}';

    protected $description = 'Backfill orders.scale_code_v2 and orders.scale_uid from related attempt scale metadata';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('orders')) {
            $this->warn('orders table missing, skipping.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('orders', 'scale_code_v2') || ! Schema::hasColumn('orders', 'scale_uid')) {
            $this->warn('orders scale identity columns missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skippedUnknown = 0;
        $lastId = '';

        do {
            $rows = DB::table('orders')
                ->select(['id', 'org_id', 'target_attempt_id', 'scale_code_v2', 'scale_uid'])
                ->where(function ($q): void {
                    $q->whereNull('scale_code_v2')
                        ->orWhere('scale_code_v2', '')
                        ->orWhereNull('scale_uid')
                        ->orWhere('scale_uid', '');
                })
                ->when($lastId !== '', fn ($q) => $q->where('id', '>', $lastId))
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $orderId = trim((string) ($row->id ?? ''));
                if ($orderId === '') {
                    continue;
                }
                $lastId = $orderId;
                $scanned++;

                $identity = $this->resolveFromOrderContext($row);
                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? '')));
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
                if ($resolvedV2 === '') {
                    $skippedUnknown++;

                    continue;
                }

                $currentV2 = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
                $currentUid = trim((string) ($row->scale_uid ?? ''));

                $needsV2 = ($currentV2 === '' || $currentV2 !== $resolvedV2);
                $needsUid = ($resolvedUid !== '' && $currentUid !== $resolvedUid);
                if (! $needsV2 && ! $needsUid) {
                    continue;
                }

                if (! $dryRun) {
                    $payload = [
                        'scale_code_v2' => $resolvedV2,
                        'updated_at' => now(),
                    ];
                    if ($resolvedUid !== '') {
                        $payload['scale_uid'] = $resolvedUid;
                    }

                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update($payload);
                }

                $updated++;
            }
        } while (true);

        $this->info(sprintf(
            'backfill_orders_scale_identity scanned=%d updated=%d skipped_unknown=%d dry_run=%d',
            $scanned,
            $updated,
            $skippedUnknown,
            $dryRun ? 1 : 0
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveFromOrderContext(object $orderRow): array
    {
        $resolvedV2 = strtoupper(trim((string) ($orderRow->scale_code_v2 ?? '')));
        $resolvedUid = trim((string) ($orderRow->scale_uid ?? ''));
        if ($resolvedV2 !== '' && $resolvedUid === '') {
            $identity = $this->identityResolver->resolveByAnyCode($resolvedV2);
            if (is_array($identity) && (bool) ($identity['is_known'] ?? false)) {
                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? $resolvedV2)));
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
            }
        }

        $targetAttemptId = trim((string) ($orderRow->target_attempt_id ?? ''));
        if ($targetAttemptId === '') {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        if (! Schema::hasTable('attempts')) {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $attempt = DB::table('attempts')
            ->select(['scale_code', 'scale_code_v2', 'scale_uid'])
            ->where('id', $targetAttemptId)
            ->first();
        if (! $attempt) {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $attemptScaleCodeV2 = strtoupper(trim((string) ($attempt->scale_code_v2 ?? '')));
        $attemptScaleUid = trim((string) ($attempt->scale_uid ?? ''));
        if ($attemptScaleCodeV2 !== '') {
            $resolvedV2 = $attemptScaleCodeV2;
        }
        if ($attemptScaleUid !== '') {
            $resolvedUid = $attemptScaleUid;
        }

        if ($resolvedV2 !== '' && $resolvedUid !== '') {
            return [
                'scale_code_v2' => $resolvedV2,
                'scale_uid' => $resolvedUid,
            ];
        }

        $legacyCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if ($legacyCode === '') {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $identity = $this->identityResolver->resolveByAnyCode($legacyCode);
        if (! is_array($identity) || ! (bool) ($identity['is_known'] ?? false)) {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? $resolvedV2)));
        $resolvedUid = trim((string) ($identity['scale_uid'] ?? $resolvedUid));

        return [
            'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
            'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
        ];
    }
}
