<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_users')) {
            Schema::table('admin_users', function (Blueprint $table): void {
                if (!Schema::hasColumn('admin_users', 'totp_secret')) {
                    $table->text('totp_secret')->nullable()->after('password');
                }

                if (!Schema::hasColumn('admin_users', 'totp_enabled_at')) {
                    $table->dateTime('totp_enabled_at')->nullable()->after('totp_secret');
                }

                if (!Schema::hasColumn('admin_users', 'password_changed_at')) {
                    $table->dateTime('password_changed_at')->nullable()->after('totp_enabled_at');
                }

                if (!Schema::hasColumn('admin_users', 'failed_login_count')) {
                    $table->unsignedInteger('failed_login_count')->default(0)->after('password_changed_at');
                }

                if (!Schema::hasColumn('admin_users', 'locked_until')) {
                    $table->dateTime('locked_until')->nullable()->after('failed_login_count');
                }
            });

            $this->ensureIndex('admin_users', ['locked_until'], 'admin_users_locked_until_idx');
        }

        if (!Schema::hasTable('admin_user_totp_recovery_codes')) {
            Schema::create('admin_user_totp_recovery_codes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('admin_user_id');
                $table->string('code_hash', 191);
                $table->dateTime('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('admin_user_password_histories')) {
            Schema::create('admin_user_password_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('admin_user_id');
                $table->string('password_hash', 255);
                $table->dateTime('created_at');
            });
        }

        $this->ensureIndex('admin_user_totp_recovery_codes', ['admin_user_id', 'used_at'], 'admin_totp_codes_user_used_idx');
        $this->ensureUniqueIndex('admin_user_totp_recovery_codes', ['code_hash'], 'admin_totp_codes_hash_uniq');
        $this->ensureIndex('admin_user_password_histories', ['admin_user_id', 'created_at'], 'admin_pwd_hist_user_time_idx');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('create_index_skip_exists', $tableName, $indexName, $driver, ['phase' => 'up']);

            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
            SchemaIndex::logIndexAction('create_index', $tableName, $indexName, $driver, ['phase' => 'up']);
        } catch (\Throwable $e) {
            if (SchemaIndex::isDuplicateIndexException($e, $indexName)) {
                SchemaIndex::logIndexAction('create_index_skip_duplicate', $tableName, $indexName, $driver, ['phase' => 'up']);

                return;
            }

            throw $e;
        }
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('create_unique_skip_exists', $tableName, $indexName, $driver, ['phase' => 'up']);

            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->unique($columns, $indexName);
            });
            SchemaIndex::logIndexAction('create_unique', $tableName, $indexName, $driver, ['phase' => 'up']);
        } catch (\Throwable $e) {
            if (SchemaIndex::isDuplicateIndexException($e, $indexName)) {
                SchemaIndex::logIndexAction('create_unique_skip_duplicate', $tableName, $indexName, $driver, ['phase' => 'up']);

                return;
            }

            throw $e;
        }
    }
};
