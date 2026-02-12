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
        Schema::create('content_pack_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('region', 32)->index();
            $table->string('locale', 32)->index();
            $table->string('pack_id', 128)->index();
            $table->string('content_package_version', 64)->index();
            $table->string('dir_version_alias', 128);

            $table->string('source_type', 32);
            $table->text('source_ref');
            $table->string('sha256', 64)->index();
            $table->longText('manifest_json');
            $table->text('extracted_rel_path');

            $table->string('created_by', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
