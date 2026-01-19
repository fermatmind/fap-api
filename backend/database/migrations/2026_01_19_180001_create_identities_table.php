<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('identities')) {
            Schema::create('identities', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('user_id', 64)->index();
                $table->string('provider', 32)->index();
                $table->string('provider_uid', 128);
                $table->timestamp('linked_at')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_uid'], 'uniq_identities_provider_uid');
            });
            return;
        }

        Schema::table('identities', function (Blueprint $table) {
            if (!Schema::hasColumn('identities', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('identities', 'user_id')) {
                $table->string('user_id', 64)->index();
            }
            if (!Schema::hasColumn('identities', 'provider')) {
                $table->string('provider', 32)->index();
            }
            if (!Schema::hasColumn('identities', 'provider_uid')) {
                $table->string('provider_uid', 128);
            }
            if (!Schema::hasColumn('identities', 'linked_at')) {
                $table->timestamp('linked_at')->nullable();
            }
            if (!Schema::hasColumn('identities', 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (!Schema::hasColumn('identities', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
