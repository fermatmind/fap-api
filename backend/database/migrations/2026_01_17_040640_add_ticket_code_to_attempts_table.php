<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            // Phase A: nullable for existing rows; new attempts will be auto-generated in model booted()
            // Format expected: FMT-XXXXXXXX (12 chars), keep some headroom.
            $table->string('ticket_code', 20)
                ->nullable()
                ->after('id');

            // Name the unique index explicitly for stable rollback across drivers.
            $table->unique('ticket_code', 'attempts_ticket_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique('attempts_ticket_code_unique');
            $table->dropColumn('ticket_code');
        });
    }
};