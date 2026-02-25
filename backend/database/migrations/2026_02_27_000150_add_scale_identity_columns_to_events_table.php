<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'scale_code_v2')) {
                $table->string('scale_code_v2', 64)->nullable()->after('scale_code');
            }

            if (! Schema::hasColumn('events', 'scale_uid')) {
                $table->char('scale_uid', 36)->nullable()->after('scale_code_v2');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled by design.
    }
};

