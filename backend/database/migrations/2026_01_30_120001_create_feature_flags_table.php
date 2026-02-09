<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'feature_flags';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique('feature_flags_key_unique');
                $table->json('rules_json')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'key')) {
                $table->string('key')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'rules_json')) {
                $table->json('rules_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'key')
            && !SchemaIndex::indexExists($tableName, 'feature_flags_key_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('key', 'feature_flags_key_unique');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
