<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'benefit_grants';
    private const IDX_USER = 'idx_grant_user_access';
    private const IDX_ANON = 'idx_grant_anon_access';
    private const IDX_SCOPE = 'idx_grant_scope_org';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addIndex(
            ['org_id', 'benefit_code', 'status', 'attempt_id', 'user_id', 'expires_at'],
            self::IDX_USER
        );
        $this->addIndex(
            ['org_id', 'benefit_code', 'status', 'attempt_id', 'benefit_ref', 'expires_at'],
            self::IDX_ANON
        );
        $this->addIndex(
            ['org_id', 'benefit_code', 'status', 'scope', 'expires_at'],
            self::IDX_SCOPE
        );
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param list<string> $columns
     */
    private function addIndex(array $columns, string $index): void
    {
        if (SchemaIndex::indexExists(self::TABLE, $index)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) {
                return;
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $index): void {
            $table->index($columns, $index);
        });
    }

    private function dropIndex(string $index): void
    {
        if (!SchemaIndex::indexExists(self::TABLE, $index)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }
};
