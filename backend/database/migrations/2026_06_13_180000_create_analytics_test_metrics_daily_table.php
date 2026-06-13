<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'analytics_test_metrics_daily';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64)->default('unknown');
                $table->string('scale_code_v2', 64)->default('');
                $table->char('scale_uid', 36)->default('');
                $table->string('form_code', 64)->default('');
                $table->string('locale', 16)->default('unknown');

                $table->unsignedInteger('started_attempts')->default(0);
                $table->unsignedInteger('successful_attempts')->default(0);
                $table->unsignedInteger('failed_attempts')->default(0);
                $table->unsignedInteger('total_attempts')->default(0);

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
                if (! Schema::hasColumn(self::TABLE, 'scale_code_v2')) {
                    $table->string('scale_code_v2', 64)->default('');
                }
                if (! Schema::hasColumn(self::TABLE, 'scale_uid')) {
                    $table->char('scale_uid', 36)->default('');
                }
                if (! Schema::hasColumn(self::TABLE, 'form_code')) {
                    $table->string('form_code', 64)->default('');
                }
                if (! Schema::hasColumn(self::TABLE, 'locale')) {
                    $table->string('locale', 16)->default('unknown');
                }
                if (! Schema::hasColumn(self::TABLE, 'started_attempts')) {
                    $table->unsignedInteger('started_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'successful_attempts')) {
                    $table->unsignedInteger('successful_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'failed_attempts')) {
                    $table->unsignedInteger('failed_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'total_attempts')) {
                    $table->unsignedInteger('total_attempts')->default(0);
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
            ['day', 'org_id', 'scale_code', 'scale_code_v2', 'form_code', 'locale'],
            'analytics_test_metrics_daily_unique'
        );
        $this->ensureIndex(['org_id', 'day'], 'analytics_test_metrics_daily_org_day_idx');
        $this->ensureIndex(
            ['org_id', 'scale_code', 'scale_code_v2', 'form_code', 'day'],
            'analytics_test_metrics_daily_scope_day_idx'
        );
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent analytics data loss.
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
