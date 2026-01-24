<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fm_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->index()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('fm_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
