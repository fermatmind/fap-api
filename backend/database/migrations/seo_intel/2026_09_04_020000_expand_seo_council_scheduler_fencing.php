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
            return;
        }

        $schema->table('seo_council_schedule_deliveries', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_council_schedule_deliveries', 'lease_key')) {
                $table->string('lease_key', 128)->nullable()->after('idempotency_key');
            }
            if (! $schema->hasColumn('seo_council_schedule_deliveries', 'fencing_token')) {
                $table->unsignedBigInteger('fencing_token')->nullable()->after('lease_key');
            }
            if (! $schema->hasColumn('seo_council_schedule_deliveries', 'version_vector_hash')) {
                $table->char('version_vector_hash', 64)->nullable()->after('mission_request_hash');
            }
            if (! $schema->hasColumn('seo_council_schedule_deliveries', 'version_vector_json')) {
                $table->json('version_vector_json')->nullable()->after('version_vector_hash');
            }
            if (! $schema->hasColumn('seo_council_schedule_deliveries', 'terminal_receipt_hash')) {
                $table->char('terminal_receipt_hash', 64)
                    ->nullable()
                    ->unique('seo_council_delivery_terminal_receipt_hash_uq')
                    ->after('terminal_receipt_reference');
            }
        });
    }

    public function down(): void
    {
        // Expand-only: fencing and terminal receipt bindings are durable execution evidence.
    }
};
