<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'analytics_scale_quality_daily';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64)->default('unknown');
                $table->string('locale', 16)->default('unknown');
                $table->string('region', 32)->default('unknown');
                $table->string('content_package_version', 128)->default('unknown');
                $table->string('scoring_spec_version', 64)->default('unknown');
                $table->string('norm_version', 64)->default('unknown');

                $table->unsignedInteger('started_attempts')->default(0);
                $table->unsignedInteger('completed_attempts')->default(0);
                $table->unsignedInteger('results_count')->default(0);
                $table->unsignedInteger('valid_results_count')->default(0);
                $table->unsignedInteger('invalid_results_count')->default(0);
                $table->unsignedInteger('quality_a_count')->default(0);
                $table->unsignedInteger('quality_b_count')->default(0);
                $table->unsignedInteger('quality_c_count')->default(0);
                $table->unsignedInteger('quality_d_count')->default(0);
                $table->unsignedInteger('crisis_alert_count')->default(0);
                $table->unsignedInteger('speeding_count')->default(0);
                $table->unsignedInteger('longstring_count')->default(0);
                $table->unsignedInteger('straightlining_count')->default(0);
                $table->unsignedInteger('extreme_count')->default(0);
                $table->unsignedInteger('inconsistency_count')->default(0);
                $table->unsignedInteger('warnings_count')->default(0);

                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TABLE, 'day')) {
                    $table->date('day')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'scale_code')) {
                    $table->string('scale_code', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'region')) {
                    $table->string('region', 32)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'content_package_version')) {
                    $table->string('content_package_version', 128)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'norm_version')) {
                    $table->string('norm_version', 64)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'started_attempts')) {
                    $table->unsignedInteger('started_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'completed_attempts')) {
                    $table->unsignedInteger('completed_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'results_count')) {
                    $table->unsignedInteger('results_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'valid_results_count')) {
                    $table->unsignedInteger('valid_results_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'invalid_results_count')) {
                    $table->unsignedInteger('invalid_results_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'quality_a_count')) {
                    $table->unsignedInteger('quality_a_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'quality_b_count')) {
                    $table->unsignedInteger('quality_b_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'quality_c_count')) {
                    $table->unsignedInteger('quality_c_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'quality_d_count')) {
                    $table->unsignedInteger('quality_d_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'crisis_alert_count')) {
                    $table->unsignedInteger('crisis_alert_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'speeding_count')) {
                    $table->unsignedInteger('speeding_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'longstring_count')) {
                    $table->unsignedInteger('longstring_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'straightlining_count')) {
                    $table->unsignedInteger('straightlining_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'extreme_count')) {
                    $table->unsignedInteger('extreme_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'inconsistency_count')) {
                    $table->unsignedInteger('inconsistency_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'warnings_count')) {
                    $table->unsignedInteger('warnings_count')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'last_refreshed_at')) {
                    $table->timestamp('last_refreshed_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(
            [
                'day',
                'org_id',
                'scale_code',
                'locale',
                'region',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
            ],
            'analytics_scale_quality_daily_scope_unique'
        );
        $this->ensureIndex(['org_id', 'day'], 'analytics_scale_quality_daily_org_day_idx');
        $this->ensureIndex(
            ['org_id', 'scale_code', 'locale', 'day'],
            'analytics_scale_quality_daily_org_scale_locale_day_idx'
        );
        $this->ensureIndex(
            ['org_id', 'content_package_version', 'scoring_spec_version', 'norm_version'],
            'analytics_scale_quality_daily_version_scope_idx'
        );
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUnique(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE) || SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE) || SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
