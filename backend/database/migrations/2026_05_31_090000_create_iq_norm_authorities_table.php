<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'iq_norm_authorities';

    private const UNIQUE_VERSION = 'iq_norm_authorities_version_unique';

    private const IDX_STATUS = 'iq_norm_authorities_status_idx';

    private const IDX_BANK = 'iq_norm_authorities_bank_idx';

    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($isSqlite): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64)->default('IQ_INTELLIGENCE_QUOTIENT');
                $table->string('bank_id', 128);
                $table->string('norm_table_version', 128);
                $table->string('status', 32)->default('draft');
                $table->string('population_key', 128)->default('general_adult_online');
                $table->string('locale', 16)->default('zh-CN');
                $table->unsignedInteger('sample_size')->nullable();
                $table->decimal('mean', 8, 3)->nullable();
                $table->decimal('standard_deviation', 8, 3)->nullable();
                $table->decimal('min_raw_score', 8, 3)->nullable();
                $table->decimal('max_raw_score', 8, 3)->nullable();
                $table->string('source_kind', 32)->default('internal_calibration');
                $table->string('source_ref', 255)->nullable();
                $table->boolean('license_verified')->default(false);
                $table->boolean('locked')->default(false);
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                if ($isSqlite) {
                    $table->text('metadata')->nullable();
                } else {
                    $table->json('metadata')->nullable();
                }
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        $this->addIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to protect norm authority audit state.
        // Irreversible operation: schema/data rollback must use a forward fix migration.
    }

    private function addIndexes(): void
    {
        if (! SchemaIndex::indexExists(self::TABLE, self::UNIQUE_VERSION)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique([
                    'org_id',
                    'scale_code',
                    'bank_id',
                    'norm_table_version',
                    'population_key',
                    'locale',
                ], self::UNIQUE_VERSION);
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::IDX_STATUS)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scale_code', 'status', 'effective_at'], self::IDX_STATUS);
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::IDX_BANK)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scale_code', 'bank_id', 'norm_table_version'], self::IDX_BANK);
            });
        }
    }
};
