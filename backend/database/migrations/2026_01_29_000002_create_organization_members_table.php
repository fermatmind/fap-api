<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_members')) {
            return;
        }

        Schema::create('organization_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'user_id'], 'organization_members_org_user_unique');
            $table->index('org_id', 'organization_members_org_id_idx');
            $table->index('user_id', 'organization_members_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
