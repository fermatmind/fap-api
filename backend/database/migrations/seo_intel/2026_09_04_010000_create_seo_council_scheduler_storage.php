<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_council_schedule_deliveries')) {
            $schema->create('seo_council_schedule_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->char('delivery_id', 64)->unique();
                $table->string('slot_key', 80);
                $table->dateTime('scheduled_for');
                $table->string('catalog_version', 64);
                $table->char('catalog_hash', 64);
                $table->string('mission_id', 128);
                $table->char('mission_request_hash', 64);
                $table->json('mission_request_json');
                $table->string('idempotency_key', 128)->unique();
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->string('status', 48);
                $table->string('terminal_receipt_reference', 191)->nullable();
                $table->timestamps();

                $table->unique(
                    ['catalog_hash', 'mission_id', 'slot_key'],
                    'seo_council_delivery_catalog_mission_slot_uq',
                );
                $table->index(
                    ['status', 'scheduled_for'],
                    'seo_council_delivery_status_schedule_idx',
                );
            });
        }

        if (! $schema->hasTable('seo_council_scheduler_leases')) {
            $schema->create('seo_council_scheduler_leases', function (Blueprint $table): void {
                $table->id();
                $table->string('lease_key', 128)->unique();
                $table->char('owner_token_hash', 64);
                $table->unsignedBigInteger('fencing_token');
                $table->dateTime('lease_expires_at');
                $table->timestamps();

                $table->index('lease_expires_at', 'seo_council_lease_expiry_idx');
            });
        }

        if (! $schema->hasTable('seo_council_schedule_receipts')) {
            $schema->create('seo_council_schedule_receipts', function (Blueprint $table): void {
                $table->id();
                $table->char('schedule_receipt_id', 64)->unique();
                $table->string('slot_key', 80);
                $table->string('catalog_version', 64);
                $table->char('catalog_hash', 64);
                $table->dateTime('scheduled_for');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->unsignedInteger('mission_planned_count')->default(0);
                $table->unsignedInteger('mission_dispatched_count')->default(0);
                $table->unsignedInteger('mission_terminal_count')->default(0);
                $table->unsignedInteger('mission_succeeded_count')->default(0);
                $table->unsignedInteger('mission_held_count')->default(0);
                $table->unsignedInteger('mission_failed_count')->default(0);
                $table->string('status', 48);
                $table->json('receipt_json');
                $table->char('receipt_hash', 64)->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index(
                    ['status', 'scheduled_for'],
                    'seo_council_schedule_receipt_status_idx',
                );
            });
        }
    }

    public function down(): void
    {
        // Expand-only: scheduler deliveries, leases, and receipts are durable operational evidence.
    }
};
