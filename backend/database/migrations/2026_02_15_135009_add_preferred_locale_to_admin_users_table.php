<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_users')) {
            return;
        }

        if (Schema::hasColumn('admin_users', 'preferred_locale')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table): void {
            $column = $table->string('preferred_locale', 16)->nullable();

            if (Schema::hasColumn('admin_users', 'totp_enabled_at')) {
                $column->after('totp_enabled_at');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to satisfy migration safety gates.
    }
};
