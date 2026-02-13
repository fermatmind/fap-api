<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempts')) {
            Schema::table('attempts', function (Blueprint $table): void {
                if (!Schema::hasColumn('attempts', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('attempts', 'duration_ms')) {
                    $table->integer('duration_ms')->default(0);
                }
                if (!Schema::hasColumn('attempts', 'answers_digest')) {
                    $table->string('answers_digest', 64)->nullable();
                }
                if (!Schema::hasColumn('attempts', 'resume_expires_at')) {
                    $table->timestamp('resume_expires_at')->nullable();
                }
                if (!Schema::hasColumn('attempts', 'device_key_hash')) {
                    $table->string('device_key_hash', 128)->nullable();
                }
            });
        }

        if (Schema::hasTable('results')) {
            Schema::table('results', function (Blueprint $table): void {
                if (!Schema::hasColumn('results', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('results', 'attempt_id')) {
                    $table->uuid('attempt_id')->nullable();
                }
                if (!Schema::hasColumn('results', 'result_json')) {
                    $table->json('result_json')->nullable();
                }
            });
        }

        if (Schema::hasTable('attempt_answer_sets')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table): void {
                if (!Schema::hasColumn('attempt_answer_sets', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('attempt_answer_sets', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable();
                }
                if (!Schema::hasColumn('attempt_answer_sets', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('attempt_answer_rows')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table): void {
                if (!Schema::hasColumn('attempt_answer_rows', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('attempt_answer_rows', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable();
                }
                if (!Schema::hasColumn('attempt_answer_rows', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('attempt_drafts')) {
            Schema::table('attempt_drafts', function (Blueprint $table): void {
                if (!Schema::hasColumn('attempt_drafts', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('attempt_drafts', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
