<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'content_pack_releases';

    private const IDX_GIT_SHA = 'content_pack_releases_git_sha_idx';

    private const IDX_COMPILED_HASH = 'content_pack_releases_compiled_hash_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'manifest_hash')) {
                $table->string('manifest_hash', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'compiled_hash')) {
                $table->string('compiled_hash', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'content_hash')) {
                $table->string('content_hash', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'norms_version')) {
                $table->string('norms_version', 128)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'git_sha')) {
                $table->string('git_sha', 64)->nullable();
            }
        });

        if (Schema::hasColumn(self::TABLE, 'git_sha') && !SchemaIndex::indexExists(self::TABLE, self::IDX_GIT_SHA)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['git_sha'], self::IDX_GIT_SHA);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'compiled_hash') && !SchemaIndex::indexExists(self::TABLE, self::IDX_COMPILED_HASH)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['compiled_hash'], self::IDX_COMPILED_HASH);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
