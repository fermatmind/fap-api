<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPE_TABLE = 'analytics_mbti_type_daily';

    private const AXIS_TABLE = 'analytics_axis_daily';

    public function up(): void
    {
        $this->createTypeTable();
        $this->createAxisTable();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createTypeTable(): void
    {
        if (! Schema::hasTable(self::TYPE_TABLE)) {
            Schema::create(self::TYPE_TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('locale', 16)->default('unknown');
                $table->string('region', 32)->default('unknown');
                $table->string('scale_code', 64)->default('MBTI');
                $table->string('content_package_version', 128)->default('unknown');
                $table->string('scoring_spec_version', 64)->default('unknown');
                $table->string('norm_version', 64)->default('unknown');
                $table->string('type_code', 16);
                $table->unsignedInteger('results_count')->default(0);
                $table->unsignedInteger('distinct_attempts_with_results')->default(0);
                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::TYPE_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TYPE_TABLE, 'day')) {
                    $table->date('day')->nullable();
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'region')) {
                    $table->string('region', 32)->default('unknown');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'scale_code')) {
                    $table->string('scale_code', 64)->default('MBTI');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'content_package_version')) {
                    $table->string('content_package_version', 128)->default('unknown');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'norm_version')) {
                    $table->string('norm_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'type_code')) {
                    $table->string('type_code', 16)->nullable();
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'results_count')) {
                    $table->unsignedInteger('results_count')->default(0);
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'distinct_attempts_with_results')) {
                    $table->unsignedInteger('distinct_attempts_with_results')->default(0);
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'last_refreshed_at')) {
                    $table->timestamp('last_refreshed_at')->nullable();
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TYPE_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(
            self::TYPE_TABLE,
            [
                'day',
                'org_id',
                'locale',
                'region',
                'scale_code',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'type_code',
            ],
            'analytics_mbti_type_daily_scope_unique'
        );
        $this->ensureIndex(self::TYPE_TABLE, ['org_id', 'day'], 'analytics_mbti_type_daily_org_day_idx');
        $this->ensureIndex(
            self::TYPE_TABLE,
            ['org_id', 'locale', 'day'],
            'analytics_mbti_type_daily_org_locale_day_idx'
        );
        $this->ensureIndex(
            self::TYPE_TABLE,
            ['org_id', 'type_code', 'day'],
            'analytics_mbti_type_daily_org_type_day_idx'
        );
    }

    private function createAxisTable(): void
    {
        if (! Schema::hasTable(self::AXIS_TABLE)) {
            Schema::create(self::AXIS_TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('locale', 16)->default('unknown');
                $table->string('region', 32)->default('unknown');
                $table->string('scale_code', 64)->default('MBTI');
                $table->string('content_package_version', 128)->default('unknown');
                $table->string('scoring_spec_version', 64)->default('unknown');
                $table->string('norm_version', 64)->default('unknown');
                $table->string('axis_code', 8);
                $table->string('side_code', 4);
                $table->unsignedInteger('results_count')->default(0);
                $table->unsignedInteger('distinct_attempts_with_results')->default(0);
                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::AXIS_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::AXIS_TABLE, 'day')) {
                    $table->date('day')->nullable();
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'region')) {
                    $table->string('region', 32)->default('unknown');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'scale_code')) {
                    $table->string('scale_code', 64)->default('MBTI');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'content_package_version')) {
                    $table->string('content_package_version', 128)->default('unknown');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'norm_version')) {
                    $table->string('norm_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'axis_code')) {
                    $table->string('axis_code', 8)->nullable();
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'side_code')) {
                    $table->string('side_code', 4)->nullable();
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'results_count')) {
                    $table->unsignedInteger('results_count')->default(0);
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'distinct_attempts_with_results')) {
                    $table->unsignedInteger('distinct_attempts_with_results')->default(0);
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'last_refreshed_at')) {
                    $table->timestamp('last_refreshed_at')->nullable();
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::AXIS_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(
            self::AXIS_TABLE,
            [
                'day',
                'org_id',
                'locale',
                'region',
                'scale_code',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'axis_code',
                'side_code',
            ],
            'analytics_axis_daily_scope_unique'
        );
        $this->ensureIndex(self::AXIS_TABLE, ['org_id', 'day'], 'analytics_axis_daily_org_day_idx');
        $this->ensureIndex(
            self::AXIS_TABLE,
            ['org_id', 'locale', 'day'],
            'analytics_axis_daily_org_locale_day_idx'
        );
        $this->ensureIndex(
            self::AXIS_TABLE,
            ['org_id', 'axis_code', 'day'],
            'analytics_axis_daily_org_axis_day_idx'
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
