<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('failed_jobs') || !Schema::hasColumn('failed_jobs', 'uuid')) {
            return;
        }

        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->string('uuid')->nullable()->change();
        });
    }

    public function down(): void
    {
        // no-op: reverting to NOT NULL can break on existing NULL rows
    }
};
