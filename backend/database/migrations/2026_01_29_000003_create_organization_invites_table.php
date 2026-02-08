<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_invites')) {
            return;
        }

        Schema::create('organization_invites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id');
            $table->string('email', 255);
            $table->string('token', 128)->unique('organization_invites_token_unique');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('org_id', 'organization_invites_org_id_idx');
            $table->index('email', 'organization_invites_email_idx');
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('organization_invites');
    }
};
