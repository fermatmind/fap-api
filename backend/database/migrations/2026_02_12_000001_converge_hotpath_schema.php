<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->convergePaymentEvents();
        $this->convergeOrders();
        $this->convergeAttempts();
        $this->convergeReportSnapshots();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function convergePaymentEvents(): void
    {
        $table = 'payment_events';
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->addColumnIfMissing($table, 'order_id', static function (Blueprint $blueprint): void {
            $blueprint->uuid('order_id')->nullable();
        });
        $this->addColumnIfMissing($table, 'order_no', static function (Blueprint $blueprint): void {
            $blueprint->string('order_no', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'event_type', static function (Blueprint $blueprint): void {
            $blueprint->string('event_type', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'signature_ok', static function (Blueprint $blueprint): void {
            $blueprint->boolean('signature_ok')->default(false);
        });
        $this->addColumnIfMissing($table, 'status', static function (Blueprint $blueprint): void {
            $blueprint->string('status', 32)->default('received');
        });
        $this->addColumnIfMissing($table, 'attempts', static function (Blueprint $blueprint): void {
            $blueprint->integer('attempts')->default(0);
        });
        $this->addColumnIfMissing($table, 'processed_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('processed_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'handled_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('handled_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'handle_status', static function (Blueprint $blueprint): void {
            $blueprint->string('handle_status', 32)->nullable();
        });
        $this->addColumnIfMissing($table, 'last_error_code', static function (Blueprint $blueprint): void {
            $blueprint->string('last_error_code', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'last_error_message', static function (Blueprint $blueprint): void {
            $blueprint->string('last_error_message', 255)->nullable();
        });
        $this->addColumnIfMissing($table, 'payload_sha256', static function (Blueprint $blueprint): void {
            $blueprint->string('payload_sha256', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'payload_excerpt', static function (Blueprint $blueprint): void {
            $blueprint->text('payload_excerpt')->nullable();
        });
        $this->addColumnIfMissing($table, 'payload_size_bytes', static function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('payload_size_bytes')->nullable();
        });
        $this->addColumnIfMissing($table, 'payload_s3_key', static function (Blueprint $blueprint): void {
            $blueprint->string('payload_s3_key')->nullable();
        });
        $this->addColumnIfMissing($table, 'received_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('received_at')->nullable();
        });

        $this->addUniqueIndexIfMissing(
            $table,
            'payment_events_provider_provider_event_id_unique',
            ['provider', 'provider_event_id']
        );
        $this->addIndexIfMissing(
            $table,
            'payment_events_provider_order_idx',
            ['provider', 'order_no']
        );
    }

    private function convergeOrders(): void
    {
        $table = 'orders';
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->addColumnIfMissing($table, 'org_id', static function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('org_id')->default(0);
        });
        $this->addColumnIfMissing($table, 'order_no', static function (Blueprint $blueprint): void {
            $blueprint->string('order_no', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'user_id', static function (Blueprint $blueprint): void {
            $blueprint->string('user_id', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'anon_id', static function (Blueprint $blueprint): void {
            $blueprint->string('anon_id', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'sku', static function (Blueprint $blueprint): void {
            $blueprint->string('sku', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'item_sku', static function (Blueprint $blueprint): void {
            $blueprint->string('item_sku', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'requested_sku', static function (Blueprint $blueprint): void {
            $blueprint->string('requested_sku', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'effective_sku', static function (Blueprint $blueprint): void {
            $blueprint->string('effective_sku', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'entitlement_id', static function (Blueprint $blueprint): void {
            $blueprint->string('entitlement_id', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'provider', static function (Blueprint $blueprint): void {
            $blueprint->string('provider', 32)->default('stub');
        });
        $this->addColumnIfMissing($table, 'status', static function (Blueprint $blueprint): void {
            $blueprint->string('status', 32)->default('created');
        });
        $this->addColumnIfMissing($table, 'quantity', static function (Blueprint $blueprint): void {
            $blueprint->integer('quantity')->default(1);
        });
        $this->addColumnIfMissing($table, 'target_attempt_id', static function (Blueprint $blueprint): void {
            $blueprint->string('target_attempt_id', 64)->nullable();
        });
        $this->addColumnIfMissing($table, 'amount_cents', static function (Blueprint $blueprint): void {
            $blueprint->integer('amount_cents')->default(0);
        });
        $this->addColumnIfMissing($table, 'amount_total', static function (Blueprint $blueprint): void {
            $blueprint->integer('amount_total')->default(0);
        });
        $this->addColumnIfMissing($table, 'amount_refunded', static function (Blueprint $blueprint): void {
            $blueprint->integer('amount_refunded')->default(0);
        });
        $this->addColumnIfMissing($table, 'currency', static function (Blueprint $blueprint): void {
            $blueprint->string('currency', 8)->default('USD');
        });
        $this->addColumnIfMissing($table, 'idempotency_key', static function (Blueprint $blueprint): void {
            $blueprint->string('idempotency_key', 128)->nullable();
        });
        $this->addColumnIfMissing($table, 'external_trade_no', static function (Blueprint $blueprint): void {
            $blueprint->string('external_trade_no', 128)->nullable();
        });
        $this->addColumnIfMissing($table, 'provider_order_id', static function (Blueprint $blueprint): void {
            $blueprint->string('provider_order_id', 128)->nullable();
        });
        $this->addColumnIfMissing($table, 'device_id', static function (Blueprint $blueprint): void {
            $blueprint->string('device_id', 128)->nullable();
        });
        $this->addColumnIfMissing($table, 'request_id', static function (Blueprint $blueprint): void {
            $blueprint->string('request_id', 128)->nullable();
        });
        $this->addColumnIfMissing($table, 'created_ip', static function (Blueprint $blueprint): void {
            $blueprint->string('created_ip', 45)->nullable();
        });
        $this->addColumnIfMissing($table, 'paid_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('paid_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'fulfilled_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('fulfilled_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'refunded_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('refunded_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'refund_amount_cents', static function (Blueprint $blueprint): void {
            $blueprint->integer('refund_amount_cents')->nullable();
        });
        $this->addColumnIfMissing($table, 'refund_reason', static function (Blueprint $blueprint): void {
            $blueprint->string('refund_reason', 255)->nullable();
        });

        $this->addUniqueIndexIfMissing($table, 'orders_order_no_unique', ['order_no']);
        $this->addIndexIfMissing($table, 'orders_org_order_no_idx', ['org_id', 'order_no']);
    }

    private function convergeAttempts(): void
    {
        $table = 'attempts';
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->addColumnIfMissing($table, 'org_id', static function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('org_id')->default(0);
        });
        $this->addColumnIfMissing($table, 'scale_code', static function (Blueprint $blueprint): void {
            $blueprint->string('scale_code', 32)->nullable();
        });
        $this->addColumnIfMissing($table, 'submitted_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('submitted_at')->nullable();
        });
        $this->addColumnIfMissing($table, 'paid_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('paid_at')->nullable();
        });

        $this->addIndexIfMissing($table, 'attempts_org_scale_submitted_idx', ['org_id', 'scale_code', 'submitted_at']);
    }

    private function convergeReportSnapshots(): void
    {
        $table = 'report_snapshots';
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->addColumnIfMissing($table, 'org_id', static function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('org_id')->default(0);
        });
        $this->addColumnIfMissing($table, 'status', static function (Blueprint $blueprint): void {
            $blueprint->string('status', 16)->default('ready');
        });
        $this->addColumnIfMissing($table, 'last_error', static function (Blueprint $blueprint): void {
            $blueprint->text('last_error')->nullable();
        });
        $this->addColumnIfMissing($table, 'updated_at', static function (Blueprint $blueprint): void {
            $blueprint->timestamp('updated_at')->nullable();
        });

        $this->addIndexIfMissing($table, 'idx_report_snapshots_status', ['org_id', 'attempt_id', 'status']);
    }

    private function addColumnIfMissing(string $table, string $column, callable $callback): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($callback): void {
            $callback($blueprint);
        });
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table) || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $columns): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function addUniqueIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table) || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $columns): void {
            $blueprint->unique($columns, $indexName);
        });
    }
};
