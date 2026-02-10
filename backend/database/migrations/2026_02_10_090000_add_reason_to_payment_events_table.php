<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'reason')) {
                $table->string('reason', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_events')) {
            return;
        }

        if (!Schema::hasColumn('payment_events', 'reason')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
