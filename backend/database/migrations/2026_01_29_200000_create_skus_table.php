<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('skus')) {
            Schema::create('skus', function (Blueprint $table) {
                $table->string('sku', 64)->primary();
                $table->string('scale_code', 64);
                $table->string('kind', 64);
                $table->integer('unit_qty')->default(1);
                $table->string('benefit_code', 64);
                $table->string('scope', 32);
                $table->integer('price_cents')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->boolean('is_active')->default(true);
                $table->json('meta_json')->nullable();
                $table->timestamps();

                $table->index(['scale_code', 'is_active'], 'skus_scale_active_idx');
                $table->index('benefit_code', 'skus_benefit_code_idx');
            });
            return;
        }

        Schema::table('skus', function (Blueprint $table) {
            if (!Schema::hasColumn('skus', 'sku')) {
                $table->string('sku', 64);
            }
            if (!Schema::hasColumn('skus', 'scale_code')) {
                $table->string('scale_code', 64)->nullable();
            }
            if (!Schema::hasColumn('skus', 'kind')) {
                $table->string('kind', 64)->nullable();
            }
            if (!Schema::hasColumn('skus', 'unit_qty')) {
                $table->integer('unit_qty')->default(1);
            }
            if (!Schema::hasColumn('skus', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('skus', 'scope')) {
                $table->string('scope', 32)->nullable();
            }
            if (!Schema::hasColumn('skus', 'price_cents')) {
                $table->integer('price_cents')->default(0);
            }
            if (!Schema::hasColumn('skus', 'currency')) {
                $table->string('currency', 8)->default('USD');
            }
            if (!Schema::hasColumn('skus', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('skus', 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (!Schema::hasColumn('skus', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('skus', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (!$this->indexExists('skus', 'skus_scale_active_idx')
            && Schema::hasColumn('skus', 'scale_code')
            && Schema::hasColumn('skus', 'is_active')) {
            Schema::table('skus', function (Blueprint $table) {
                $table->index(['scale_code', 'is_active'], 'skus_scale_active_idx');
            });
        }

        if (!$this->indexExists('skus', 'skus_benefit_code_idx') && Schema::hasColumn('skus', 'benefit_code')) {
            Schema::table('skus', function (Blueprint $table) {
                $table->index('benefit_code', 'skus_benefit_code_idx');
            });
        }

        if (!$this->indexExists('skus', 'skus_sku_unique') && Schema::hasColumn('skus', 'sku')) {
            Schema::table('skus', function (Blueprint $table) {
                $table->unique('sku', 'skus_sku_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `{$table}`");
            foreach ($rows as $row) {
                if ((string) ($row->Key_name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }
};
