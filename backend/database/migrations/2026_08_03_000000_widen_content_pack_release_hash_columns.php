<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pack_releases', function (Blueprint $table) {
            $table->string('manifest_hash', 71)->nullable()->change();
            $table->string('compiled_hash', 71)->nullable()->change();
            $table->string('content_hash', 71)->nullable()->change();
        });

        Schema::table('content_release_manifests', function (Blueprint $table) {
            $table->char('manifest_hash', 71)->change();
            $table->char('compiled_hash', 71)->nullable()->change();
            $table->char('content_hash', 71)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('content_pack_releases', function (Blueprint $table) {
            $table->string('manifest_hash', 64)->nullable()->change();
            $table->string('compiled_hash', 64)->nullable()->change();
            $table->string('content_hash', 64)->nullable()->change();
        });

        Schema::table('content_release_manifests', function (Blueprint $table) {
            $table->char('manifest_hash', 64)->change();
            $table->char('compiled_hash', 64)->nullable()->change();
            $table->char('content_hash', 64)->nullable()->change();
        });
    }
};
