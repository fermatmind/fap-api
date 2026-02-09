<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'ai_insights';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('user_id')->nullable();
                $table->string('anon_id')->nullable();
                $table->string('period_type', 16);
                $table->date('period_start');
                $table->date('period_end');
                $table->string('input_hash', 64);
                $table->string('prompt_version', 64);
                $table->string('model', 64);
                $table->string('provider', 32);
                $table->integer('tokens_in')->default(0);
                $table->integer('tokens_out')->default(0);
                $table->decimal('cost_usd', 10, 4)->default(0);
                $table->string('status', 16);
                $table->json('output_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamps();

                $table->index('input_hash', 'ai_insights_input_hash_index');
                $table->index('status', 'ai_insights_status_index');
                $table->index(['user_id', 'created_at'], 'ai_insights_user_id_created_at_index');
                $table->index(['period_start', 'period_end'], 'ai_insights_period_start_period_end_index');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'user_id')) {
                $table->string('user_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'anon_id')) {
                $table->string('anon_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'period_type')) {
                $table->string('period_type', 16)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'period_start')) {
                $table->date('period_start')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'period_end')) {
                $table->date('period_end')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'input_hash')) {
                $table->string('input_hash', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'prompt_version')) {
                $table->string('prompt_version', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'model')) {
                $table->string('model', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'provider')) {
                $table->string('provider', 32)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'tokens_in')) {
                $table->integer('tokens_in')->default(0);
            }
            if (!Schema::hasColumn($tableName, 'tokens_out')) {
                $table->integer('tokens_out')->default(0);
            }
            if (!Schema::hasColumn($tableName, 'cost_usd')) {
                $table->decimal('cost_usd', 10, 4)->default(0);
            }
            if (!Schema::hasColumn($tableName, 'status')) {
                $table->string('status', 16)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'output_json')) {
                $table->json('output_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'evidence_json')) {
                $table->json('evidence_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'error_code')) {
                $table->string('error_code', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'input_hash')
            && !SchemaIndex::indexExists($tableName, 'ai_insights_input_hash_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('input_hash', 'ai_insights_input_hash_index');
            });
        }

        if (Schema::hasColumn($tableName, 'status') && !SchemaIndex::indexExists($tableName, 'ai_insights_status_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('status', 'ai_insights_status_index');
            });
        }

        if (Schema::hasColumn($tableName, 'user_id')
            && Schema::hasColumn($tableName, 'created_at')
            && !SchemaIndex::indexExists($tableName, 'ai_insights_user_id_created_at_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['user_id', 'created_at'], 'ai_insights_user_id_created_at_index');
            });
        }

        if (Schema::hasColumn($tableName, 'period_start')
            && Schema::hasColumn($tableName, 'period_end')
            && !SchemaIndex::indexExists($tableName, 'ai_insights_period_start_period_end_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['period_start', 'period_end'], 'ai_insights_period_start_period_end_index');
            });
        }
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::drop('ai_insights');
    }
};
