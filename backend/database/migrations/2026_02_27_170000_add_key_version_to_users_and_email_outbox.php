<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateUsers();
        $this->migrateEmailOutbox();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function migrateUsers(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'key_version')) {
                $table->unsignedSmallInteger('key_version')->nullable();
            }
        });
    }

    private function migrateEmailOutbox(): void
    {
        if (! Schema::hasTable('email_outbox')) {
            return;
        }

        Schema::table('email_outbox', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_outbox', 'key_version')) {
                $table->unsignedSmallInteger('key_version')->nullable();
            }
        });
    }
};
