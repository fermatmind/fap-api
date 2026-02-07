<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_dlq_replays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('failed_job_id')->index();
            $table->string('failed_job_uuid', 64)->nullable()->index();
            $table->string('connection_name', 64)->default('');
            $table->string('queue_name', 128)->default('');
            $table->string('replay_status', 32)->default('replayed')->index();
            $table->string('replayed_job_id', 64)->nullable();
            $table->string('requested_by', 64)->nullable();
            $table->string('request_source', 32)->default('api');
            $table->text('notes')->nullable();
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_dlq_replays');
    }
};
