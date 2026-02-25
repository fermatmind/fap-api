<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillPaymentEventsScaleIdentity extends Command
{
    protected $signature = 'ops:backfill-payment-events-scale-identity
        {--chunk=1000 : Chunk size}
        {--dry-run : Compute and report only; do not write updates}';

    protected $description = 'Backfill payment_events.scale_code_v2 and payment_events.scale_uid from related order/attempt scale metadata';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('payment_events')) {
            $this->warn('payment_events table missing, skipping.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('payment_events', 'scale_code_v2') || ! Schema::hasColumn('payment_events', 'scale_uid')) {
            $this->warn('payment_events scale identity columns missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skippedUnknown = 0;
        $lastId = '';

        do {
            $rows = DB::table('payment_events')
                ->select(['id', 'order_no', 'scale_code_v2', 'scale_uid'])
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
                $eventId = trim((string) ($row->id ?? ''));
                if ($eventId === '') {
                    continue;
                }
                $lastId = $eventId;
                $scanned++;

                $identity = $this->resolveFromPaymentEventContext($row);
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

                    DB::table('payment_events')
                        ->where('id', $eventId)
                        ->update($payload);
                }

                $updated++;
            }
        } while (true);

        $this->info(sprintf(
            'backfill_payment_events_scale_identity scanned=%d updated=%d skipped_unknown=%d dry_run=%d',
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
    private function resolveFromPaymentEventContext(object $row): array
    {
        $resolvedV2 = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
        $resolvedUid = trim((string) ($row->scale_uid ?? ''));

        if ($resolvedV2 !== '' && $resolvedUid === '') {
            $identity = $this->identityResolver->resolveByAnyCode($resolvedV2);
            if (is_array($identity) && (bool) ($identity['is_known'] ?? false)) {
                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? $resolvedV2)));
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
            }
        }

        $orderNo = trim((string) ($row->order_no ?? ''));
        if ($orderNo === '' || ! Schema::hasTable('orders')) {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $order = DB::table('orders')
            ->select(['target_attempt_id', 'scale_code_v2', 'scale_uid'])
            ->where('order_no', $orderNo)
            ->first();
        if (! $order) {
            return [
                'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
                'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
            ];
        }

        $orderScaleCodeV2 = strtoupper(trim((string) ($order->scale_code_v2 ?? '')));
        $orderScaleUid = trim((string) ($order->scale_uid ?? ''));
        if ($orderScaleCodeV2 !== '') {
            $resolvedV2 = $orderScaleCodeV2;
        }
        if ($orderScaleUid !== '') {
            $resolvedUid = $orderScaleUid;
        }

        $targetAttemptId = trim((string) ($order->target_attempt_id ?? ''));
        if ($targetAttemptId !== '' && Schema::hasTable('attempts')) {
            $attempt = DB::table('attempts')
                ->select(['scale_code', 'scale_code_v2', 'scale_uid'])
                ->where('id', $targetAttemptId)
                ->first();

            if ($attempt) {
                $attemptV2 = strtoupper(trim((string) ($attempt->scale_code_v2 ?? '')));
                $attemptUid = trim((string) ($attempt->scale_uid ?? ''));
                if ($attemptV2 !== '') {
                    $resolvedV2 = $attemptV2;
                }
                if ($attemptUid !== '') {
                    $resolvedUid = $attemptUid;
                }

                if ($resolvedV2 === '' || $resolvedUid === '') {
                    $legacy = strtoupper(trim((string) ($attempt->scale_code ?? '')));
                    if ($legacy !== '') {
                        $identity = $this->identityResolver->resolveByAnyCode($legacy);
                        if (is_array($identity) && (bool) ($identity['is_known'] ?? false)) {
                            if ($resolvedV2 === '') {
                                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? '')));
                            }
                            if ($resolvedUid === '') {
                                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
                            }
                        }
                    }
                }
            }
        }

        if ($resolvedV2 !== '' && $resolvedUid === '') {
            $identity = $this->identityResolver->resolveByAnyCode($resolvedV2);
            if (is_array($identity) && (bool) ($identity['is_known'] ?? false)) {
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
            }
        }

        return [
            'scale_code_v2' => $resolvedV2 !== '' ? $resolvedV2 : null,
            'scale_uid' => $resolvedUid !== '' ? $resolvedUid : null,
        ];
    }
}

