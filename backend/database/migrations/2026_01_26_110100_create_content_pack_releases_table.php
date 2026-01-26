<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_pack_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('action', 32)->index();
            $table->string('region', 32)->index();
            $table->string('locale', 32)->index();
            $table->string('dir_alias', 128)->index();

            $table->uuid('from_version_id')->nullable()->index();
            $table->uuid('to_version_id')->nullable()->index();
            $table->string('from_pack_id', 128)->nullable();
            $table->string('to_pack_id', 128)->nullable();

            $table->string('status', 16)->index();
            $table->text('message')->nullable();
            $table->string('created_by', 64)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_pack_releases');
    }
};
