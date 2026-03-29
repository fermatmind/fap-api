<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'articles',
        'career_guides',
        'career_jobs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'lifecycle_state')) {
                    $table->string('lifecycle_state', 32)->default('active');
                }

                if (! Schema::hasColumn($tableName, 'lifecycle_changed_at')) {
                    $table->timestamp('lifecycle_changed_at')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'lifecycle_changed_by_admin_user_id')) {
                    $table->unsignedBigInteger('lifecycle_changed_by_admin_user_id')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'lifecycle_note')) {
                    $table->string('lifecycle_note', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent lifecycle data loss.
    }
};
