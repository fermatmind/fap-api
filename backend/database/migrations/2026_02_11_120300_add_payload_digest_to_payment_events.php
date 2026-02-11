<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'payment_events';
    private const INDEX = 'idx_payment_events_payload_sha256';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'payload_sha256')) {
                $table->string('payload_sha256', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'payload_size_bytes')) {
                $table->unsignedInteger('payload_size_bytes')->nullable();
            }
        });

        if (!Schema::hasColumn(self::TABLE, 'payload_sha256') || SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index('payload_sha256', self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
