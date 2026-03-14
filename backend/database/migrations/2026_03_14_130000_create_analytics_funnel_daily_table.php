<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'analytics_funnel_daily';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64)->default('unknown');
                $table->string('locale', 16)->default('unknown');

                $table->unsignedInteger('started_attempts')->default(0);
                $table->unsignedInteger('submitted_attempts')->default(0);
                $table->unsignedInteger('first_view_attempts')->default(0);
                $table->unsignedInteger('order_created_attempts')->default(0);
                $table->unsignedInteger('paid_attempts')->default(0);
                $table->unsignedBigInteger('paid_revenue_cents')->default(0);
                $table->unsignedInteger('unlocked_attempts')->default(0);
                $table->unsignedInteger('report_ready_attempts')->default(0);

                $table->unsignedInteger('pdf_download_attempts')->default(0);
                $table->unsignedInteger('share_generated_attempts')->default(0);
                $table->unsignedInteger('share_click_attempts')->default(0);

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
                if (! Schema::hasColumn(self::TABLE, 'started_attempts')) {
                    $table->unsignedInteger('started_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'submitted_attempts')) {
                    $table->unsignedInteger('submitted_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'first_view_attempts')) {
                    $table->unsignedInteger('first_view_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'order_created_attempts')) {
                    $table->unsignedInteger('order_created_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'paid_attempts')) {
                    $table->unsignedInteger('paid_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'paid_revenue_cents')) {
                    $table->unsignedBigInteger('paid_revenue_cents')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'unlocked_attempts')) {
                    $table->unsignedInteger('unlocked_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'report_ready_attempts')) {
                    $table->unsignedInteger('report_ready_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'pdf_download_attempts')) {
                    $table->unsignedInteger('pdf_download_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'share_generated_attempts')) {
                    $table->unsignedInteger('share_generated_attempts')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'share_click_attempts')) {
                    $table->unsignedInteger('share_click_attempts')->default(0);
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
            ['day', 'org_id', 'scale_code', 'locale'],
            'analytics_funnel_daily_day_org_scale_locale_unique'
        );
        $this->ensureIndex(['org_id', 'day'], 'analytics_funnel_daily_org_day_idx');
        $this->ensureIndex(
            ['org_id', 'scale_code', 'locale', 'day'],
            'analytics_funnel_daily_org_scale_locale_day_idx'
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
