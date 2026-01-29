<?php

use Database\Migrations\Concerns\HasIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/Concerns/HasIndex.php';

return new class extends Migration
{
    use HasIndex;

    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider', 32);
                $table->string('provider_event_id', 128);
                $table->string('order_no', 64)->nullable();
                $table->json('payload_json');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->unique('provider_event_id', 'payment_events_provider_event_id_unique');
                $table->index(['order_no', 'received_at'], 'payment_events_order_received_idx');
            });
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
        });

        if (!$this->indexExists('payment_events', 'payment_events_provider_event_id_unique')
            && Schema::hasColumn('payment_events', 'provider_event_id')) {
            $duplicates = DB::table('payment_events')
                ->select('provider_event_id')
                ->whereNotNull('provider_event_id')
                ->groupBy('provider_event_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('payment_events', function (Blueprint $table) {
                    $table->unique('provider_event_id', 'payment_events_provider_event_id_unique');
                });
            }
        }

        if (!$this->indexExists('payment_events', 'payment_events_order_received_idx')
            && Schema::hasColumn('payment_events', 'order_no')
            && Schema::hasColumn('payment_events', 'received_at')) {
            Schema::table('payment_events', function (Blueprint $table) {
                $table->index(['order_no', 'received_at'], 'payment_events_order_received_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
