<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Database;

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SchemaIndexTest extends TestCase
{
    private string $tableName = 'schema_index_test_rows';
    private bool $dropAuditTableAfterTest = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('migration_index_audits')) {
            Schema::create('migration_index_audits', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('migration_name', 191)->nullable();
                $table->string('table_name', 128);
                $table->string('index_name', 128);
                $table->string('action', 64);
                $table->string('phase', 32)->nullable();
                $table->string('driver', 32);
                $table->string('status', 32)->default('logged');
                $table->string('reason', 191)->nullable();
                $table->text('meta_json')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
            });
            $this->dropAuditTableAfterTest = true;
        }

        DB::table('migration_index_audits')->delete();

        Schema::dropIfExists($this->tableName);
        Schema::create($this->tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->tableName);
        if ($this->dropAuditTableAfterTest) {
            Schema::dropIfExists('migration_index_audits');
        }
        parent::tearDown();
    }

    public function test_index_exists_returns_true_after_create_and_false_after_drop(): void
    {
        $indexName = 'idx_schema_index_name';

        $this->assertFalse(SchemaIndex::indexExists($this->tableName, $indexName));

        Schema::table($this->tableName, function (Blueprint $table) use ($indexName): void {
            $table->index('name', $indexName);
        });

        $this->assertTrue(SchemaIndex::indexExists($this->tableName, $indexName));

        Schema::table($this->tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });

        $this->assertFalse(SchemaIndex::indexExists($this->tableName, $indexName));
    }

    public function test_is_duplicate_index_exception_returns_true_for_duplicate_create(): void
    {
        $indexName = 'idx_schema_index_duplicate';

        Schema::table($this->tableName, function (Blueprint $table) use ($indexName): void {
            $table->index('name', $indexName);
        });

        try {
            Schema::table($this->tableName, function (Blueprint $table) use ($indexName): void {
                $table->index('name', $indexName);
            });
        } catch (\Throwable $e) {
            $this->assertTrue(SchemaIndex::isDuplicateIndexException($e, $indexName));
            return;
        }

        $this->fail('Expected duplicate index exception was not thrown.');
    }

    public function test_is_missing_index_exception_returns_true_for_drop_missing_index(): void
    {
        $indexName = 'idx_schema_index_missing';

        try {
            Schema::table($this->tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            $this->assertTrue(SchemaIndex::isMissingIndexException($e, $indexName));
            return;
        }

        $this->fail('Expected missing index exception was not thrown.');
    }

    public function test_log_index_action_persists_observability_record_when_audit_table_exists(): void
    {
        SchemaIndex::logIndexAction(
            'create_index',
            $this->tableName,
            'idx_schema_index_name',
            'sqlite',
            [
                'phase' => 'up',
                'status' => 'logged',
                'reason' => 'unit_test',
            ]
        );

        $this->assertDatabaseHas('migration_index_audits', [
            'table_name' => $this->tableName,
            'index_name' => 'idx_schema_index_name',
            'action' => 'create_index',
            'phase' => 'up',
            'status' => 'logged',
            'reason' => 'unit_test',
        ]);
    }
}
