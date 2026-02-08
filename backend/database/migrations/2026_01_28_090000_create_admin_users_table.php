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
        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 64);
                $table->string('email', 191)->unique();
                $table->string('password', 255);
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('last_login_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
            return;
        }

        Schema::table('admin_users', function (Blueprint $table): void {
            if (!Schema::hasColumn('admin_users', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('admin_users', 'name')) {
                $table->string('name', 64)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'email')) {
                $table->string('email', 191)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'password')) {
                $table->string('password', 255)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'is_active')) {
                $table->tinyInteger('is_active')->default(1);
            }
            if (!Schema::hasColumn('admin_users', 'last_login_at')) {
                $table->dateTime('last_login_at')->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'remember_token')) {
                $table->rememberToken();
            }
            if (!Schema::hasColumn('admin_users', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('admin_users', 'email')) {
            $this->ensureUniqueIndex('admin_users', ['email'], 'uniq_admin_users_email', ['admin_users_email_unique']);
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('admin_users');
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName, array $alternateNames = []): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $knownNames = array_values(array_unique(array_merge([$indexName], $alternateNames)));

        foreach ($knownNames as $knownName) {
            if (SchemaIndex::indexExists($tableName, $knownName)) {
                SchemaIndex::logIndexAction('create_unique_skip_exists', $tableName, $knownName, $driver, ['phase' => 'up']);
                return;
            }
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
