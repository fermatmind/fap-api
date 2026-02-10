<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'migration_backfills';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->string('key', 64)->primary();
                $table->unsignedBigInteger('last_id')->default(0);
                $table->string('last_cursor', 64)->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'last_id')) {
                $table->unsignedBigInteger('last_id')->default(0);
            }

            if (!Schema::hasColumn(self::TABLE, 'last_cursor')) {
                $table->string('last_cursor', 64)->nullable();
            }

            if (!Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Safety: no-op.
    }
};
