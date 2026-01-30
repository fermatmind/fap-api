<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('experiment_assignments')) {
            return;
        }

        Schema::create('experiment_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('anon_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('experiment_key');
            $table->string('variant');
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['org_id', 'anon_id', 'experiment_key'], 'experiment_assignments_org_anon_experiment_unique');
            $table->index(['org_id', 'user_id', 'experiment_key'], 'experiment_assignments_org_user_experiment_idx');
            $table->index(['org_id', 'experiment_key'], 'experiment_assignments_org_experiment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_assignments');
    }
};
