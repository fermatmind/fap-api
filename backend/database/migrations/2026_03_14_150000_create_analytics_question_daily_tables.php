<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPTION_TABLE = 'analytics_question_option_daily';

    private const PROGRESS_TABLE = 'analytics_question_progress_daily';

    public function up(): void
    {
        $this->createOptionTable();
        $this->createProgressTable();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOptionTable(): void
    {
        if (! Schema::hasTable(self::OPTION_TABLE)) {
            Schema::create(self::OPTION_TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('locale', 16)->default('unknown');
                $table->string('region', 32)->default('unknown');
                $table->string('scale_code', 64)->default('BIG5_OCEAN');
                $table->string('content_package_version', 128)->default('unknown');
                $table->string('scoring_spec_version', 64)->default('unknown');
                $table->string('norm_version', 64)->default('unknown');
                $table->string('question_id', 128);
                $table->unsignedInteger('question_order')->default(0);
                $table->string('option_key', 64);
                $table->unsignedInteger('answered_rows_count')->default(0);
                $table->unsignedInteger('distinct_attempts_answered')->default(0);
                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::OPTION_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::OPTION_TABLE, 'day')) {
                    $table->date('day')->nullable();
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'region')) {
                    $table->string('region', 32)->default('unknown');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'scale_code')) {
                    $table->string('scale_code', 64)->default('BIG5_OCEAN');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'content_package_version')) {
                    $table->string('content_package_version', 128)->default('unknown');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'norm_version')) {
                    $table->string('norm_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'question_id')) {
                    $table->string('question_id', 128)->nullable();
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'question_order')) {
                    $table->unsignedInteger('question_order')->default(0);
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'option_key')) {
                    $table->string('option_key', 64)->nullable();
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'answered_rows_count')) {
                    $table->unsignedInteger('answered_rows_count')->default(0);
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'distinct_attempts_answered')) {
                    $table->unsignedInteger('distinct_attempts_answered')->default(0);
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'last_refreshed_at')) {
                    $table->timestamp('last_refreshed_at')->nullable();
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::OPTION_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(
            self::OPTION_TABLE,
            [
                'day',
                'org_id',
                'locale',
                'region',
                'scale_code',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'question_id',
                'option_key',
            ],
            'analytics_question_option_daily_scope_unique'
        );
        $this->ensureIndex(self::OPTION_TABLE, ['org_id', 'day'], 'analytics_question_option_daily_org_day_idx');
        $this->ensureIndex(
            self::OPTION_TABLE,
            ['org_id', 'scale_code', 'day'],
            'analytics_question_option_daily_org_scale_day_idx'
        );
        $this->ensureIndex(
            self::OPTION_TABLE,
            ['org_id', 'question_order', 'day'],
            'analytics_question_option_daily_org_question_day_idx'
        );
    }

    private function createProgressTable(): void
    {
        if (! Schema::hasTable(self::PROGRESS_TABLE)) {
            Schema::create(self::PROGRESS_TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('locale', 16)->default('unknown');
                $table->string('region', 32)->default('unknown');
                $table->string('scale_code', 64)->default('BIG5_OCEAN');
                $table->string('content_package_version', 128)->default('unknown');
                $table->string('scoring_spec_version', 64)->default('unknown');
                $table->string('norm_version', 64)->default('unknown');
                $table->string('question_id', 128);
                $table->unsignedInteger('question_order')->default(0);
                $table->unsignedInteger('reached_attempts_count')->default(0);
                $table->unsignedInteger('answered_attempts_count')->default(0);
                $table->unsignedInteger('completed_attempts_count')->default(0);
                $table->unsignedInteger('dropoff_attempts_count')->default(0);
                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::PROGRESS_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'day')) {
                    $table->date('day')->nullable();
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'region')) {
                    $table->string('region', 32)->default('unknown');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'scale_code')) {
                    $table->string('scale_code', 64)->default('BIG5_OCEAN');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'content_package_version')) {
                    $table->string('content_package_version', 128)->default('unknown');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'norm_version')) {
                    $table->string('norm_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'question_id')) {
                    $table->string('question_id', 128)->nullable();
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'question_order')) {
                    $table->unsignedInteger('question_order')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'reached_attempts_count')) {
                    $table->unsignedInteger('reached_attempts_count')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'answered_attempts_count')) {
                    $table->unsignedInteger('answered_attempts_count')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'completed_attempts_count')) {
                    $table->unsignedInteger('completed_attempts_count')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'dropoff_attempts_count')) {
                    $table->unsignedInteger('dropoff_attempts_count')->default(0);
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'last_refreshed_at')) {
                    $table->timestamp('last_refreshed_at')->nullable();
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::PROGRESS_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(
            self::PROGRESS_TABLE,
            [
                'day',
                'org_id',
                'locale',
                'region',
                'scale_code',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'question_id',
            ],
            'analytics_question_progress_daily_scope_unique'
        );
        $this->ensureIndex(self::PROGRESS_TABLE, ['org_id', 'day'], 'analytics_question_progress_daily_org_day_idx');
        $this->ensureIndex(
            self::PROGRESS_TABLE,
            ['org_id', 'scale_code', 'day'],
            'analytics_question_progress_daily_org_scale_day_idx'
        );
        $this->ensureIndex(
            self::PROGRESS_TABLE,
            ['org_id', 'question_order', 'day'],
            'analytics_question_progress_daily_org_question_day_idx'
        );
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUnique(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
