<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BENEFIT_GRANTS = 'benefit_grants';
    private const PAYMENT_EVENTS = 'payment_events';

    private const IDX_GRANT_USER = 'idx_benefit_grants_org_benefit_status_user';
    private const IDX_GRANT_ATTEMPT = 'idx_benefit_grants_org_benefit_status_attempt';
    private const IDX_PAYMENT = 'idx_payment_events_provider_status_received';
    private const IDX_PAYMENT_EQUIV = 'idx_pay_status_time';

    public function up(): void
    {
        if (Schema::hasTable(self::BENEFIT_GRANTS)
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'org_id')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'benefit_code')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'status')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'user_id')
            && !SchemaIndex::indexExists(self::BENEFIT_GRANTS, self::IDX_GRANT_USER)) {
            Schema::table(self::BENEFIT_GRANTS, function (Blueprint $table): void {
                $table->index(
                    ['org_id', 'benefit_code', 'status', 'user_id'],
                    self::IDX_GRANT_USER
                );
            });
        }

        if (Schema::hasTable(self::BENEFIT_GRANTS)
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'org_id')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'benefit_code')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'status')
            && Schema::hasColumn(self::BENEFIT_GRANTS, 'attempt_id')
            && !SchemaIndex::indexExists(self::BENEFIT_GRANTS, self::IDX_GRANT_ATTEMPT)) {
            Schema::table(self::BENEFIT_GRANTS, function (Blueprint $table): void {
                $table->index(
                    ['org_id', 'benefit_code', 'status', 'attempt_id'],
                    self::IDX_GRANT_ATTEMPT
                );
            });
        }

        if (Schema::hasTable(self::PAYMENT_EVENTS)
            && Schema::hasColumn(self::PAYMENT_EVENTS, 'provider')
            && Schema::hasColumn(self::PAYMENT_EVENTS, 'status')
            && Schema::hasColumn(self::PAYMENT_EVENTS, 'received_at')
            && !SchemaIndex::indexExists(self::PAYMENT_EVENTS, self::IDX_PAYMENT)
            && !SchemaIndex::indexExists(self::PAYMENT_EVENTS, self::IDX_PAYMENT_EQUIV)) {
            Schema::table(self::PAYMENT_EVENTS, function (Blueprint $table): void {
                $table->index(
                    ['provider', 'status', 'received_at'],
                    self::IDX_PAYMENT
                );
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
