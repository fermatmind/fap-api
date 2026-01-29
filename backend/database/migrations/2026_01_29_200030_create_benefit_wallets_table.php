<?php

use Database\Migrations\Concerns\HasIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/Concerns/HasIndex.php';

return new class extends Migration
{
    use HasIndex;

    public function up(): void
    {
        if (!Schema::hasTable('benefit_wallets')) {
            Schema::create('benefit_wallets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('benefit_code', 64);
                $table->integer('balance')->default(0);
                $table->timestamps();

                $table->unique(['org_id', 'benefit_code'], 'benefit_wallets_org_id_benefit_code_unique');
                $table->index('org_id', 'benefit_wallets_org_idx');
            });
            return;
        }

        Schema::table('benefit_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_wallets', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallets', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallets', 'balance')) {
                $table->integer('balance')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallets', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_wallets', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasTable('benefit_wallets') && Schema::hasColumn('benefit_wallets', 'org_id')) {
            DB::table('benefit_wallets')->whereNull('org_id')->update(['org_id' => 0]);
        }

        $uniqueName = 'benefit_wallets_org_id_benefit_code_unique';
        $legacyUniqueName = 'benefit_wallets_org_benefit_unique';
        if (!$this->indexExists('benefit_wallets', $uniqueName)
            && !$this->indexExists('benefit_wallets', $legacyUniqueName)
            && Schema::hasColumn('benefit_wallets', 'org_id')
            && Schema::hasColumn('benefit_wallets', 'benefit_code')) {
            $duplicates = DB::table('benefit_wallets')
                ->select('org_id', 'benefit_code')
                ->whereNotNull('benefit_code')
                ->groupBy('org_id', 'benefit_code')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('benefit_wallets', function (Blueprint $table) {
                    $table->unique(['org_id', 'benefit_code'], 'benefit_wallets_org_id_benefit_code_unique');
                });
            }
        }

        if (!$this->indexExists('benefit_wallets', 'benefit_wallets_org_idx') && Schema::hasColumn('benefit_wallets', 'org_id')) {
            Schema::table('benefit_wallets', function (Blueprint $table) {
                $table->index('org_id', 'benefit_wallets_org_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_wallets');
    }
};
