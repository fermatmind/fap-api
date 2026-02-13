<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->convergeAttempts();
        $this->convergeOrders();
        $this->convergeSkus();
        $this->convergePaymentEvents();
        $this->convergeReportSnapshots();
        $this->convergeIdempotencyKeys();
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function convergeAttempts(): void
    {
        if (!Schema::hasTable('attempts')) {
            return;
        }

        Schema::table('attempts', function (Blueprint $table): void {
            if (!Schema::hasColumn('attempts', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('attempts', 'user_id')) {
                $table->string('user_id', 64)->nullable();
            }
            if (!Schema::hasColumn('attempts', 'anon_id')) {
                $table->string('anon_id', 64)->nullable();
            }
        });
    }

    private function convergeOrders(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
        });
    }

    private function convergePaymentEvents(): void
    {
        if (!Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table): void {
            if (!Schema::hasColumn('payment_events', 'provider')) {
                $table->string('provider', 32)->default('unknown');
            }
        });
    }

    private function convergeSkus(): void
    {
        if (!Schema::hasTable('skus')) {
            return;
        }

        Schema::table('skus', function (Blueprint $table): void {
            if (!Schema::hasColumn('skus', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(1);
            }
        });
    }

    private function convergeReportSnapshots(): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::table('report_snapshots', function (Blueprint $table): void {
            if (!Schema::hasColumn('report_snapshots', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
        });
    }

    private function convergeIdempotencyKeys(): void
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return;
        }

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            if (!Schema::hasColumn('idempotency_keys', 'provider')) {
                $table->string('provider', 64);
            }
            if (!Schema::hasColumn('idempotency_keys', 'external_id')) {
                $table->string('external_id', 191);
            }
            if (!Schema::hasColumn('idempotency_keys', 'recorded_at')) {
                $table->string('recorded_at', 64);
            }
            if (!Schema::hasColumn('idempotency_keys', 'hash')) {
                $table->string('hash', 128);
            }
        });
    }
};
