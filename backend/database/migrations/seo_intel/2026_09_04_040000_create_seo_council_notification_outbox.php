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
        if ($schema->hasTable('seo_council_notification_outbox')) {
            return;
        }

        $schema->create('seo_council_notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->char('notification_id', 64)->unique();
            $table->char('fingerprint', 64)->unique();
            $table->string('event_type', 64);
            $table->char('subject_hash', 64);
            $table->char('policy_revision', 64);
            $table->string('incident_state', 32);
            $table->string('status', 16)->default('pending');
            $table->json('payload_json');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->dateTime('available_at');
            $table->char('lease_token_hash', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'available_at', 'lease_expires_at'],
                'seo_council_notification_claim_idx',
            );
            $table->index(
                ['subject_hash', 'event_type', 'incident_state'],
                'seo_council_notification_incident_idx',
            );
        });
    }

    public function down(): void
    {
        // Expand-only: notification delivery and terminal failure evidence remains readable.
    }
};
