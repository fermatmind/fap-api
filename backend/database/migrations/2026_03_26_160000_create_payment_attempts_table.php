<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORDER_ATTEMPT_UNIQUE = 'payment_attempts_order_attempt_no_unique';

    private const ORDER_NO_INDEX = 'payment_attempts_order_no_idx';

    private const PROVIDER_INDEX = 'payment_attempts_provider_idx';

    private const STATE_INDEX = 'payment_attempts_state_idx';

    private const EXTERNAL_TRADE_NO_INDEX = 'payment_attempts_external_trade_no_idx';

    private const PROVIDER_TRADE_NO_INDEX = 'payment_attempts_provider_trade_no_idx';

    private const ORDER_PROVIDER_SCENE_INDEX = 'payment_attempts_order_provider_scene_idx';

    private const EVENT_ATTEMPT_INDEX = 'payment_events_payment_attempt_id_idx';

    public function up(): void
    {
        $this->convergePaymentAttempts();
        $this->convergePaymentEvents();
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function convergePaymentAttempts(): void
    {
        if (! Schema::hasTable('payment_attempts')) {
            Schema::create('payment_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('order_id');
                $table->string('order_no', 64);
                $table->unsignedInteger('attempt_no');
                $table->string('provider', 32);
                $table->string('channel', 64)->nullable();
                $table->string('provider_app', 128)->nullable();
                $table->string('pay_scene', 32)->nullable();
                $table->string('state', 32)->default('initiated');
                $table->string('external_trade_no', 128)->nullable();
                $table->string('provider_trade_no', 128)->nullable();
                $table->string('provider_session_ref', 191)->nullable();
                $table->integer('amount_expected')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->json('payload_meta_json')->nullable();
                $table->uuid('latest_payment_event_id')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('provider_created_at')->nullable();
                $table->timestamp('client_presented_at')->nullable();
                $table->timestamp('callback_received_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->string('last_error_message', 255)->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('payment_attempts', function (Blueprint $table): void {
                if (! Schema::hasColumn('payment_attempts', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (! Schema::hasColumn('payment_attempts', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn('payment_attempts', 'order_id')) {
                    $table->uuid('order_id')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'order_no')) {
                    $table->string('order_no', 64)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'attempt_no')) {
                    $table->unsignedInteger('attempt_no')->default(1);
                }
                if (! Schema::hasColumn('payment_attempts', 'provider')) {
                    $table->string('provider', 32)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'channel')) {
                    $table->string('channel', 64)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'provider_app')) {
                    $table->string('provider_app', 128)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'pay_scene')) {
                    $table->string('pay_scene', 32)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'state')) {
                    $table->string('state', 32)->default('initiated');
                }
                if (! Schema::hasColumn('payment_attempts', 'external_trade_no')) {
                    $table->string('external_trade_no', 128)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'provider_trade_no')) {
                    $table->string('provider_trade_no', 128)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'provider_session_ref')) {
                    $table->string('provider_session_ref', 191)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'amount_expected')) {
                    $table->integer('amount_expected')->default(0);
                }
                if (! Schema::hasColumn('payment_attempts', 'currency')) {
                    $table->string('currency', 8)->default('USD');
                }
                if (! Schema::hasColumn('payment_attempts', 'payload_meta_json')) {
                    $table->json('payload_meta_json')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'latest_payment_event_id')) {
                    $table->uuid('latest_payment_event_id')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'initiated_at')) {
                    $table->timestamp('initiated_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'provider_created_at')) {
                    $table->timestamp('provider_created_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'client_presented_at')) {
                    $table->timestamp('client_presented_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'callback_received_at')) {
                    $table->timestamp('callback_received_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'finalized_at')) {
                    $table->timestamp('finalized_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'last_error_code')) {
                    $table->string('last_error_code', 64)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'last_error_message')) {
                    $table->string('last_error_message', 255)->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'meta_json')) {
                    $table->json('meta_json')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('payment_attempts', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUniqueIndex(
            'payment_attempts',
            self::ORDER_ATTEMPT_UNIQUE,
            ['order_id', 'attempt_no']
        );
        $this->ensureIndex('payment_attempts', self::ORDER_NO_INDEX, ['order_no']);
        $this->ensureIndex('payment_attempts', self::PROVIDER_INDEX, ['provider']);
        $this->ensureIndex('payment_attempts', self::STATE_INDEX, ['state']);
        $this->ensureIndex('payment_attempts', self::EXTERNAL_TRADE_NO_INDEX, ['external_trade_no']);
        $this->ensureIndex('payment_attempts', self::PROVIDER_TRADE_NO_INDEX, ['provider_trade_no']);
        $this->ensureIndex('payment_attempts', self::ORDER_PROVIDER_SCENE_INDEX, ['order_id', 'provider', 'pay_scene']);
    }

    private function convergePaymentEvents(): void
    {
        if (! Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_events', 'payment_attempt_id')) {
                $table->uuid('payment_attempt_id')->nullable()->after('order_id');
            }
        });

        $this->ensureIndex('payment_events', self::EVENT_ATTEMPT_INDEX, ['payment_attempt_id']);
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function ensureUniqueIndex(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }
};
