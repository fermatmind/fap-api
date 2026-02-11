<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'audit_logs';
    private const INDEX = 'audit_logs_org_id_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'org_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('org_id')->default(0)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'org_id')) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('org_id');
        });
    }
};
