<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANNEL_INDEX = 'orders_channel_idx';

    private const PAYMENT_STATE_INDEX = 'orders_payment_state_idx';

    private const GRANT_STATE_INDEX = 'orders_grant_state_idx';

    private const PAID_AT_INDEX = 'orders_paid_at_idx';

    private const PROVIDER_TRADE_NO_INDEX = 'orders_provider_trade_no_idx';

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'channel')) {
                $table->string('channel', 64)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('orders', 'provider_app')) {
                $table->string('provider_app', 128)->nullable()->after('channel');
            }
            if (! Schema::hasColumn('orders', 'payment_state')) {
                $table->string('payment_state', 32)->default('created')->after('status');
            }
            if (! Schema::hasColumn('orders', 'grant_state')) {
                $table->string('grant_state', 32)->default('not_started')->after('payment_state');
            }
            if (! Schema::hasColumn('orders', 'provider_trade_no')) {
                $table->string('provider_trade_no', 128)->nullable()->after('external_trade_no');
            }
            if (! Schema::hasColumn('orders', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('orders', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('expired_at');
            }
            if (! Schema::hasColumn('orders', 'last_payment_event_at')) {
                $table->timestamp('last_payment_event_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('orders', 'last_reconciled_at')) {
                $table->timestamp('last_reconciled_at')->nullable()->after('last_payment_event_at');
            }
            if (! Schema::hasColumn('orders', 'external_user_ref')) {
                $table->string('external_user_ref', 128)->nullable()->after('contact_email_hash');
            }
        });

        $this->ensureIndex('orders', 'channel', self::CHANNEL_INDEX);
        $this->ensureIndex('orders', 'payment_state', self::PAYMENT_STATE_INDEX);
        $this->ensureIndex('orders', 'grant_state', self::GRANT_STATE_INDEX);
        $this->ensureIndex('orders', 'paid_at', self::PAID_AT_INDEX);
        $this->ensureIndex('orders', 'provider_trade_no', self::PROVIDER_TRADE_NO_INDEX);

        $this->backfillPaymentState();
        $this->backfillGrantState();
        $this->backfillExternalUserRef();
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function ensureIndex(string $table, string $column, string $indexName): void
    {
        if (! Schema::hasColumn($table, $column) || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $indexName): void {
            $table->index($column, $indexName);
        });
    }

    private function backfillPaymentState(): void
    {
        if (! Schema::hasColumn('orders', 'payment_state') || ! Schema::hasColumn('orders', 'status')) {
            return;
        }

        DB::table('orders')->update(['payment_state' => 'created']);

        foreach ([
            'created' => 'created',
            'pending' => 'pending',
            'paid' => 'paid',
            'fulfilled' => 'paid',
            'failed' => 'failed',
            'canceled' => 'canceled',
            'cancelled' => 'canceled',
            'expired' => 'expired',
            'refunded' => 'refunded',
        ] as $status => $paymentState) {
            DB::table('orders')
                ->whereRaw("lower(coalesce(status, '')) = ?", [$status])
                ->update(['payment_state' => $paymentState]);
        }
    }

    private function backfillGrantState(): void
    {
        if (! Schema::hasColumn('orders', 'grant_state')) {
            return;
        }

        DB::table('orders')->update(['grant_state' => 'not_started']);

        if (Schema::hasTable('benefit_grants') && Schema::hasColumn('benefit_grants', 'order_no')) {
            if (Schema::hasColumn('benefit_grants', 'status')) {
                DB::table('orders')
                    ->whereIn('order_no', function ($query): void {
                        $query->select('order_no')
                            ->from('benefit_grants')
                            ->whereNotNull('order_no')
                            ->where('order_no', '!=', '')
                            ->whereRaw("lower(coalesce(status, '')) = ?", ['active'])
                            ->distinct();
                    })
                    ->update(['grant_state' => 'granted']);

                DB::table('orders')
                    ->where('grant_state', 'not_started')
                    ->whereIn('order_no', function ($query): void {
                        $query->select('order_no')
                            ->from('benefit_grants')
                            ->whereNotNull('order_no')
                            ->where('order_no', '!=', '')
                            ->whereRaw("lower(coalesce(status, '')) = ?", ['revoked'])
                            ->distinct();
                    })
                    ->update(['grant_state' => 'revoked']);
            } else {
                DB::table('orders')
                    ->whereIn('order_no', function ($query): void {
                        $query->select('order_no')
                            ->from('benefit_grants')
                            ->whereNotNull('order_no')
                            ->where('order_no', '!=', '')
                            ->distinct();
                    })
                    ->update(['grant_state' => 'granted']);
            }
        }

        if (Schema::hasColumn('orders', 'status')) {
            DB::table('orders')
                ->whereRaw("lower(coalesce(status, '')) = ?", ['fulfilled'])
                ->update(['grant_state' => 'granted']);
        }
    }

    private function backfillExternalUserRef(): void
    {
        if (! Schema::hasColumn('orders', 'external_user_ref')) {
            return;
        }

        DB::table('orders')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'user_id', 'anon_id', 'contact_email_hash'])
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $externalUserRef = $this->resolveExternalUserRef(
                        $row->user_id ?? null,
                        $row->anon_id ?? null,
                        $row->contact_email_hash ?? null
                    );

                    if ($externalUserRef === null) {
                        continue;
                    }

                    DB::table('orders')
                        ->where('id', (string) $row->id)
                        ->where(function ($query): void {
                            $query->whereNull('external_user_ref')
                                ->orWhere('external_user_ref', '');
                        })
                        ->update([
                            'external_user_ref' => $externalUserRef,
                        ]);
                }
            });
    }

    private function resolveExternalUserRef(mixed $userId, mixed $anonId, mixed $contactEmailHash): ?string
    {
        $normalizedUserId = trim((string) $userId);
        if ($normalizedUserId !== '') {
            return substr('user:'.$normalizedUserId, 0, 128);
        }

        $normalizedAnonId = trim((string) $anonId);
        if ($normalizedAnonId !== '') {
            return substr('anon:'.$normalizedAnonId, 0, 128);
        }

        $normalizedContactHash = trim((string) $contactEmailHash);
        if ($normalizedContactHash !== '') {
            return substr('email_hash:'.$normalizedContactHash, 0, 128);
        }

        return null;
    }
};
