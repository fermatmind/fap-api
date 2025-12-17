<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("events", function (Blueprint $table) {
            $table->uuid("id")->primary();

            $table->string("event_code", 64);
            $table->string("anon_id", 128)->nullable();
            $table->string("attempt_id", 64);
            $table->json("meta_json")->nullable();

            $table->timestamps();

            $table->index(["attempt_id"]);
            $table->index(["event_code"]);
            $table->index(["created_at"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("events");
    }
};
